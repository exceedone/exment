<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Support\Facades\DB;

/**
 * Set-based writes (ValueBulkWriter, C1) under the storage write protocol (Stage B block 3).
 *
 * Each statement keeps its existing SQL, row selection and bypass semantics (no model, no event,
 * no revision, no prepare()). It runs in a transaction that first takes the shared lock of its
 * table's storage row (participation, StorageWriteGate). In a dual-write state, the JSON
 * statement is followed - in the same transaction, while its rows are still locked by it - by
 * the projection of exactly what it persisted:
 *  - setKey (one key of one row): the row's `value` and `value_original` are re-read FOR UPDATE
 *    after the JSON_SET, the key's cell is converted from the persisted JSON and its typed column
 *    and value_original entry are written in one UPDATE; other value_original keys stay as the
 *    locked row has them.
 *  - removeKey (one key of every selected row, no scopes): the same base query sets the key's
 *    typed column to NULL and removes its value_original entry (JSON_REMOVE; MySQL/MariaDB only).
 * Readers never see the JSON statement without its projection (one transaction). Refused states
 * refuse the write before the JSON statement.
 */
final class StorageBulkWrite
{
    /**
     * @param \Closure $jsonStatement runs the existing statement, returns its result
     * @param \Closure|null $dualPreflight checks run before the JSON statement in dual-write
     * @param \Closure $dualProjection (CustomTable $table) runs after it in dual-write
     * @return mixed the JSON statement's result
     */
    public static function run(string $db_table_name, \Closure $jsonStatement, ?\Closure $dualPreflight, \Closure $dualProjection)
    {
        $table = static::tableOf($db_table_name);
        if ($table === null || !TableStorage::installed()) {
            return $jsonStatement();
        }
        return DB::transaction(function () use ($table, $jsonStatement, $dualPreflight, $dualProjection) {
            $storage = StorageWriteGate::lock($table);
            if (StorageWriteGate::classify($storage, $table) === StorageWriteGate::JSON_ONLY) {
                return $jsonStatement();
            }
            if ($dualPreflight) {
                $dualPreflight($table);
            }
            $result = $jsonStatement();
            $dualProjection($table);
            return $result;
        });
    }

    /**
     * Dual-write projection of setKey: one key of one row, from the persisted JSON.
     *
     * @param mixed $id
     */
    public static function projectKey(CustomTable $table, string $db_table_name, $id, string $column_name): void
    {
        $column = static::typedColumn($table, $column_name);
        if ($column === null) {
            return; // multi-valued or not a column of the table: JSON only
        }
        DualWriter::assertColumnsExist($db_table_name, [PhysicalNaming::stagingColumn($column)]);

        $row = DB::table($db_table_name)->where('id', $id)->lockForUpdate()->first(['value', PhysicalNaming::VALUE_ORIGINAL]);
        if (!$row) {
            return;
        }
        $projection = Projection::ofColumn($column, Projection::decode($row->value));
        $original = Projection::decode($row->{PhysicalNaming::VALUE_ORIGINAL}) ?? [];
        $name = (string)$column->column_name;
        if (array_key_exists($name, $projection->original())) {
            $original[$name] = $projection->original()[$name];
        } else {
            unset($original[$name]);
        }
        $update = $projection->typed();
        $update[PhysicalNaming::VALUE_ORIGINAL] = empty($original) ? null : Json::encode($original);
        DB::table($db_table_name)->where('id', $id)->update($update);
    }

    /**
     * Dual-write preflight of removeKey: driver and key name.
     */
    public static function assertRemoveKeySupported(CustomTable $table, string $column_name): void
    {
        DriverSupport::require(DriverSupport::BULK_REMOVE_KEY);
        if (!preg_match('/^[A-Za-z0-9_]+$/', $column_name)) {
            throw new StorageConversionException('set-based remove refused: column name is not a safe JSON path key', ['column' => $column_name]);
        }
    }

    /**
     * Dual-write projection of removeKey: on the same rows (base query, no scopes), the key's
     * typed column becomes NULL and its value_original entry is removed.
     *
     * @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder $query
     */
    public static function projectRemovedKey(CustomTable $table, string $db_table_name, $query, string $column_name): void
    {
        $base = $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->getQuery() : $query;
        DualWriter::assertColumnsExist($db_table_name, []);
        // @phpstan-ignore-next-line ExtendedBuilderTrait
        (clone $base)->updateRemovingJsonKey(PhysicalNaming::VALUE_ORIGINAL . '->' . $column_name);

        $column = static::typedColumn($table, $column_name);
        if ($column === null) {
            return;
        }
        $pcol = PhysicalNaming::stagingColumn($column);
        if (hasColumn($db_table_name, $pcol)) {
            (clone $base)->update([$pcol => null]);
        }
    }

    /** The single-valued column with a physical type named $column_name, or null. */
    private static function typedColumn(CustomTable $table, string $column_name): ?CustomColumn
    {
        foreach ($table->custom_columns_cache as $column) {
            if ((string)$column->column_name === $column_name) {
                return PhysicalColumnType::of($column) === null ? null : $column;
            }
        }
        return null;
    }

    /** The custom table of a value table name (`exm__<suuid>`), or null. */
    public static function tableOf(string $db_table_name): ?CustomTable
    {
        if (strncmp($db_table_name, 'exm__', 5) !== 0) {
            return null;
        }
        $suuid = substr($db_table_name, 5);
        $table = CustomTable::allRecords(function ($t) use ($suuid) {
            return (string)$t->suuid === $suuid;
        })->first();
        return $table instanceof CustomTable ? $table : null;
    }
}
