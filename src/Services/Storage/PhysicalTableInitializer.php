<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Enums\StorageMode;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\DB;

/**
 * A business table created directly as physical (02 §16). No conversion workflow: the table must be
 * provably empty, so dual-write, backfill, verification, the L5 report and the drain attestation have
 * nothing to act on and are omitted; everything that protects consistency is kept.
 *
 *   json --claim--> initializing --DDL, validate--> physical_read
 *                      |  ^  retry (idempotent)            (storage_mode physical, generation + 1)
 *                      +--cancel (still empty)--> json
 *
 * Claim (one transaction, EXCLUSIVE storage lock on the custom_tables row): the lock waits for every
 * participating write that started under the previous state, and every later write sees `initializing`
 * and is refused (StorageWriteGate; reads: CustomValueModelScope). Only then is the emptiness checked,
 * with a raw query that includes soft-deleted rows - so a row can no longer appear between the check
 * and the state change. A non-empty table is refused and the transaction leaves nothing behind.
 * Raw writers that do not participate in the protocol cannot be excluded technically (as in the
 * conversion); a row that appears anyway is caught by the second emptiness check at publication.
 *
 * DDL (outside any transaction: it commits implicitly on MySQL/MariaDB): value_original and every
 * missing typed column, idempotent and resumable (PhysicalSchemaManager::applyPlanDdl). A failure
 * leaves the table `initializing` with an audit row (driver code only); calling initialize() again
 * resumes from the live schema. Publication (physical_read) happens only when the live schema equals
 * the plan: no column to add, value_original present, no blocker.
 *
 * Cancel: only while still empty; drops the (empty) typed columns and returns to JSON.
 * Deleting the table in any state works (the value table is dropped with it).
 */
final class PhysicalTableInitializer
{
    /** @var ConversionAvailability */
    private $availability;
    /** @var PhysicalSchemaManager */
    private $schema;

    public function __construct(ConversionAvailability $availability, PhysicalSchemaManager $schema)
    {
        $this->availability = $availability;
        $this->schema = $schema;
    }

    /**
     * Whether a table may be created as physical in this installation (the offer of the choice).
     */
    public function isOffered(): bool
    {
        return TableStorage::installed() && $this->availability->isAvailable();
    }

    /**
     * Blockers that forbid initialization (empty when allowed). State and emptiness are checked under the
     * lock, not here.
     *
     * @return array<int, array<string, mixed>>
     */
    public function blockers(CustomTable $table): array
    {
        $blockers = [];
        if (!TableStorage::installed()) {
            return [['code' => 'storage_not_installed', 'message' => 'run the migrations: custom_tables has no storage columns']];
        }
        if (ConversionEligibility::isSystemTable($table)) {
            $blockers[] = ['code' => 'system_table', 'message' => "R6: system table {$table->table_name} stays JSON"];
        }
        if (!DB::connection()->getSchemaBuilder()->hasTable(getDBTableName($table))) {
            $blockers[] = ['code' => 'value_table_missing', 'message' => 'value table does not exist'];
        }
        foreach ($table->custom_columns_cache as $column) {
            if (!$column->isMultipleEnabled() && !PhysicalColumnType::isKnownColumnType((string)$column->column_type)) {
                $blockers[] = ['code' => 'unsupported_column_type', 'column' => $column->column_name,
                    'message' => "single-valued column type {$column->column_type} has no physical type"];
            }
        }
        $blockers = array_merge($blockers, $this->schema->plan($table)->blockers());
        return $blockers;
    }

    /**
     * Initialize (or resume the initialization of) a table as physical.
     *
     * @throws StorageConversionException
     */
    public function initialize(CustomTable $table, ?int $actorUserId = null): TableStorage
    {
        $this->assertAvailable();
        $blockers = $this->blockers($table);
        if (!empty($blockers)) {
            throw new StorageConversionException('table cannot be created as physical', ['blockers' => $blockers]);
        }
        $db = getDBTableName($table);

        // 1. claim under the exclusive lock, then prove emptiness (writes are already excluded)
        $claimed = DB::transaction(function () use ($table, $db, $actorUserId) {
            $locked = TableStorage::lockedForTransition((int)$table->id);
            if ($locked->state() === StorageState::INITIALIZING && $locked->mode() === StorageMode::JSON) {
                return $locked; // resume
            }
            if ($locked->state() !== StorageState::JSON || $locked->mode() !== StorageMode::JSON) {
                throw new StorageConversionException("initialization refused: storage is {$locked->mode()}/{$locked->state()}", []);
            }
            $next = $locked->transitionTo(StorageState::INITIALIZING, StorageMode::JSON, true);
            if ($next === null) {
                throw new StorageConversionException('storage state changed concurrently', []);
            }
            // raw: soft-deleted rows count, and no scope hides a row
            if (DB::table($db)->exists()) {
                throw new StorageConversionException('initialization refused: the table already has records (including deleted ones); use the conversion workflow', []);
            }
            StorageAudit::record((int)$table->id, StorageState::JSON, StorageState::INITIALIZING, $locked->generation(), $next->generation(), ['operation' => 'initialize_physical'], $actorUserId);
            return $next;
        });

        // 2. DDL, validate the live schema, publish
        try {
            $plan = $this->schema->plan($table);
            if (!$plan->isProvisionable()) {
                throw new StorageConversionException('physical schema plan has blockers', ['blockers' => $plan->blockers()]);
            }
            $this->schema->applyPlanDdl($table, $plan, $claimed->generation(), StorageState::INITIALIZING);
            $verified = $this->schema->plan($table);
            if (!$verified->isProvisionable() || !empty($verified->toAdd()) || ($verified->valueOriginal()['action'] ?? null) !== 'exists') {
                throw new StorageConversionException('the live schema does not match the table definition', ['blockers' => $verified->blockers()]);
            }
        } catch (StorageConversionException $ex) {
            throw $ex; // applyPlanDdl already recorded provision-style failures
        } catch (\Throwable $ex) {
            StorageAudit::record((int)$table->id, StorageState::INITIALIZING, StorageState::INITIALIZING, $claimed->generation(), $claimed->generation(),
                ['operation' => 'initialize_failed', 'error' => get_class($ex)], $actorUserId);
            throw new StorageConversionException('initialization stopped: ' . get_class($ex) . '; the table stays initializing, run it again', []);
        }

        return DB::transaction(function () use ($table, $db, $actorUserId) {
            $locked = TableStorage::lockedForTransition((int)$table->id);
            if ($locked->state() !== StorageState::INITIALIZING || $locked->mode() !== StorageMode::JSON) {
                throw new StorageConversionException("publication refused: storage is {$locked->mode()}/{$locked->state()}", []);
            }
            if (DB::table($db)->exists()) {
                throw new StorageConversionException('publication refused: a record appeared while the table was initializing', []);
            }
            $next = $locked->transitionTo(StorageState::PHYSICAL_READ, StorageMode::PHYSICAL, true);
            if ($next === null) {
                throw new StorageConversionException('storage state changed concurrently', []);
            }
            StorageAudit::record((int)$table->id, StorageState::INITIALIZING, StorageState::PHYSICAL_READ, $locked->generation(), $next->generation(), ['operation' => 'initialize_published'], $actorUserId);
            return $next;
        });
    }

    /**
     * Cancel an initialization: only while the table is still empty; the (empty) typed columns are dropped
     * and the table returns to JSON. Refused otherwise; nothing is dropped when anything holds a value.
     *
     * @throws StorageConversionException
     */
    public function cancel(CustomTable $table, ?int $actorUserId = null): TableStorage
    {
        $this->assertAvailable();
        $db = getDBTableName($table);
        $storage = TableStorage::fresh($table);
        if ($storage->state() !== StorageState::INITIALIZING) {
            throw new StorageConversionException('cancel needs state initializing, storage is ' . $storage->mode() . '/' . $storage->state(), []);
        }
        if (DB::table($db)->exists()) {
            throw new StorageConversionException('cancel refused: the table has records', []);
        }
        [, $dropped] = $this->schema->dropEmptyStagingColumns($table);

        return DB::transaction(function () use ($table, $dropped, $actorUserId) {
            $locked = TableStorage::lockedForTransition((int)$table->id);
            if ($locked->state() !== StorageState::INITIALIZING) {
                throw new StorageConversionException("cancel refused: storage is {$locked->mode()}/{$locked->state()}", ['dropped' => $dropped]);
            }
            $next = $locked->transitionTo(StorageState::JSON, StorageMode::JSON, true);
            if ($next === null) {
                throw new StorageConversionException('storage state changed concurrently; staging columns were dropped', ['dropped' => $dropped]);
            }
            StorageAudit::record((int)$table->id, StorageState::INITIALIZING, StorageState::JSON, $locked->generation(), $next->generation(), ['operation' => 'initialize_cancelled', 'dropped' => $dropped], $actorUserId);
            return $next;
        });
    }

    private function assertAvailable(): void
    {
        if (!$this->availability->isAvailable()) {
            throw new StorageConversionException('conversion is not available', ['missing' => $this->availability->missing()]);
        }
    }
}
