<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Enums\StorageMode;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\DB;

/**
 * Column definition changes on a table that reads physical (02 §16): create, update, delete.
 *
 * Contract. The typed column `pcol_<suuid>` is the only physical object of a column; the JSON shadow,
 * value_original and the generated index columns need no DDL (writes keep the shadow complete; index
 * columns are still handled by CustomColumn::alterColumn from the JSON). Ordering makes every failure
 * window harmless:
 *   - create: the typed column is added BEFORE the metadata row exists (no writer knows the column yet);
 *     a failed insert leaves an unreferenced empty column (reported as an orphan, removed by reconcile());
 *   - delete: the metadata row is deleted first (writers stop projecting the column), the typed column is
 *     dropped afterwards; a failed drop leaves an orphan, never a metadata row without its column.
 * A writer that loaded the old column list in another process may fail once with a database error after
 * the column was dropped; it cannot write a wrong value (DualWriter::assertColumnsExist).
 *
 * What the application permits and what is refused here (before anything is mutated):
 *   - column_type and column_name cannot be edited by the screen (display only after creation); a changed
 *     value reaching the model anyway (template import, API) is refused;
 *   - a single-valued column whose type has no physical type is refused at creation;
 *   - switching multiple_enabled is allowed only while NO row holds a value for the column (a value would
 *     have to move between the typed column and a JSON list; that data migration is not implemented), so
 *     existing data is never reinterpreted silently;
 *   - everything else (labels, select items, required, index_enabled, validation options) needs no DDL.
 * Serialization: a short exclusive lock on the table's storage row checks that the table still reads
 * physical (conversion transitions take the same lock, writers hold it shared); after the DDL the
 * generation is checked again. DDL itself cannot hold the lock (implicit commit), so the protection of
 * the DDL window is the ordering above plus the idempotent reconcile().
 */
final class PhysicalColumnLifecycle
{
    /** @var PhysicalSchemaManager */
    private $schema;

    /** @var array<int, array<string, mixed>> pending after-save/after-delete work, by spl_object_id of the column */
    private static $pending = [];

    public function __construct(PhysicalSchemaManager $schema)
    {
        $this->schema = $schema;
    }

    /** Whether a lifecycle step queued work for this column object (so the common JSON path never builds the service). */
    public static function hasPending(CustomColumn $column): bool
    {
        return isset(self::$pending[spl_object_id($column)]);
    }

    /**
     * @param string $operation 'create' | 'update' | 'delete'
     * @throws StorageConversionException refused before anything is mutated
     */
    public function beforeChange(CustomColumn $column, string $operation): void
    {
        $table = CustomTable::getEloquent((int)$column->custom_table_id);
        if (!($table instanceof CustomTable)) {
            return;
        }
        $generation = $this->claim($table);
        $key = spl_object_id($column);

        if ($operation === 'delete') {
            if (!empty($column->suuid)) {
                self::$pending[$key] = ['drop' => (string)$column->suuid, 'table' => (int)$table->id, 'generation' => $generation];
            }
            return;
        }

        if ($operation === 'create') {
            if (is_nullorempty($column->suuid)) {
                $column->suuid = short_uuid(); // the typed column is named from it, before the row exists
            }
            $this->addTyped($table, $column, $generation);
            return;
        }

        // update
        foreach (['column_type', 'column_name'] as $immutable) {
            if ($column->isDirty($immutable)) {
                throw new StorageConversionException("column change refused: $immutable cannot change on a table that reads physical", ['column' => $column->column_name]);
            }
        }
        $old = (new CustomColumn())->forceFill($column->getOriginal());
        $wasMulti = $old->isMultipleEnabled();
        $isMulti = $column->isMultipleEnabled();
        if ($wasMulti === $isMulti) {
            return;
        }
        if ($this->holdsValues($table, (string)$column->column_name)) {
            throw new StorageConversionException('column change refused: the column holds values, so single-valued <-> multi-valued is not possible (the data would have to move between the typed column and a JSON list)', ['column' => $column->column_name]);
        }
        if ($isMulti) {
            // single -> multi: the typed column is no longer written; dropped after the metadata is saved
            self::$pending[$key] = ['drop' => (string)$column->suuid, 'table' => (int)$table->id, 'generation' => $generation];
        } else {
            // multi -> single: the typed column must exist before the metadata says single-valued
            $this->addTyped($table, $column, $generation);
        }
    }

    /** After the column row was saved: work that must follow the metadata (dropping a typed column). */
    public function afterChange(CustomColumn $column): void
    {
        $this->runPending($column);
    }

    /** After the column row was deleted. */
    public function afterDeleted(CustomColumn $column): void
    {
        $this->runPending($column);
    }

    /**
     * Bring the live schema to the definition: add what is missing, drop typed columns no column owns.
     * Idempotent; the recovery of an interrupted lifecycle step (an orphan after a failed drop or insert).
     *
     * @return array{added: array<int, string>, dropped: array<int, string>}
     * @throws StorageConversionException
     */
    public function reconcile(CustomTable $table, ?int $actorUserId = null): array
    {
        $generation = $this->claim($table);
        $added = [];
        $dropped = [];
        $this->schema->ensureValueOriginal($table);
        $byName = [];
        foreach ($table->custom_columns_cache as $column) {
            if (!$column->isMultipleEnabled() && !empty($column->suuid) && PhysicalColumnType::of($column) !== null) {
                $byName[PhysicalNaming::stagingColumn($column)] = $column;
            }
        }
        foreach ($this->schema->plan($table)->toAdd() as $entry) {
            if (isset($byName[$entry['staging_column']])) {
                $this->schema->addColumnDdl($table, $byName[$entry['staging_column']]);
                $added[] = (string)$entry['staging_column'];
            }
        }
        foreach ($this->schema->orphanStagingColumns($table) as $orphan) {
            if ($this->schema->dropColumnDdl($table, substr($orphan, strlen(PhysicalNaming::STAGING_PREFIX)))) {
                $dropped[] = $orphan;
            }
        }
        $plan = $this->schema->plan($table);
        if (!$plan->isProvisionable() || !empty($plan->toAdd()) || ($plan->valueOriginal()['action'] ?? null) !== 'exists') {
            throw new StorageConversionException('the live schema still does not match the table definition', ['blockers' => $plan->blockers()]);
        }
        $this->assertFence($table, $generation);
        StorageAudit::record((int)$table->id, StorageState::PHYSICAL_READ, StorageState::PHYSICAL_READ, $generation, $generation,
            ['operation' => 'reconcile_schema', 'added' => $added, 'dropped' => $dropped], $actorUserId);
        return ['added' => $added, 'dropped' => $dropped];
    }

    // ------------------------------------------------------------------ internals

    private function addTyped(CustomTable $table, CustomColumn $column, int $generation): void
    {
        $this->schema->addColumnDdl($table, $column);
        $this->assertFence($table, $generation);
    }

    private function runPending(CustomColumn $column): void
    {
        $key = spl_object_id($column);
        if (!isset(self::$pending[$key])) {
            return;
        }
        $work = self::$pending[$key];
        unset(self::$pending[$key]);
        $table = CustomTable::getEloquent((int)$work['table']);
        if (!($table instanceof CustomTable)) {
            return;
        }
        try {
            $this->schema->dropColumnDdl($table, (string)$work['drop']);
        } catch (StorageConversionException $ex) {
            // the metadata is already saved: the typed column stays as an unreferenced orphan (never read)
            StorageAudit::record((int)$table->id, StorageState::PHYSICAL_READ, StorageState::PHYSICAL_READ, (int)$work['generation'], (int)$work['generation'],
                ['operation' => 'column_drop_failed', 'column' => 'pcol_' . $work['drop']]);
            throw $ex;
        }
    }

    /**
     * The table must still read physical; takes the exclusive storage lock briefly (waits for writes in
     * flight, excludes a concurrent transition) and returns the generation to fence against.
     *
     * @throws StorageConversionException
     */
    private function claim(CustomTable $table): int
    {
        return DB::transaction(function () use ($table) {
            $locked = TableStorage::lockedForTransition((int)$table->id);
            if ($locked->state() !== StorageState::PHYSICAL_READ || $locked->mode() !== StorageMode::PHYSICAL) {
                throw new StorageConversionException("column change refused: table storage is {$locked->mode()}/{$locked->state()}", ['storage' => $locked->toArray()]);
            }
            return $locked->generation();
        });
    }

    /** @throws StorageConversionException */
    private function assertFence(CustomTable $table, int $generation): void
    {
        $now = TableStorage::fresh($table);
        if ($now->state() !== StorageState::PHYSICAL_READ || $now->generation() !== $generation) {
            throw new StorageConversionException('column change refused: the table storage changed while the schema was being changed', ['storage' => $now->toArray()]);
        }
    }

    /**
     * Whether any row - soft-deleted ones included - holds a value for the column in the JSON shadow
     * (complete on a table that reads physical). A JSON null counts as a value: conservative.
     */
    public function holdsValues(CustomTable $table, string $columnName): bool
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $columnName)) {
            throw new \InvalidArgumentException('Invalid column name');
        }
        $db = getDBTableName($table);
        $path = '$."' . $columnName . '"';
        if (PhysicalSchemaManager::driverFamily() === 'sqlsrv') {
            return DB::table($db)->whereRaw('(JSON_VALUE([value], ?) IS NOT NULL OR JSON_QUERY([value], ?) IS NOT NULL)', [$path, $path])->exists();
        }
        return DB::table($db)->whereRaw('JSON_EXTRACT(`value`, ?) IS NOT NULL', [$path])->exists();
    }
}
