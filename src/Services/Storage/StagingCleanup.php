<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Enums\StorageMode;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\DB;

/**
 * Staging-data cleanup after a cancelled conversion (02 §15.15 lifecycle; Stage B block 5).
 *
 * Only in `provisioned` with storage_mode json - JSON is the only authoritative copy and no
 * projecting write state is active - and only with explicit administrative intent (the table
 * name typed back). Clears ONLY the owned staging data: the `pcol_*` columns of the table's
 * current columns and `value_original`, as the physical schema plan recognises them as ours
 * (not generated, expected type). Never touches `value`. Never runs in a projecting state,
 * physical_read, cleaning or cleaned, where the typed data is written or authoritative.
 *
 * Resumable run (StorageRunner, kind 'cleanup'): batches by id with checkpoint; each batch holds
 * the shared lock of the table's storage row and checks state and generation, so a transition
 * (e.g. startDualWrite) waits for the batch and then makes the run stale - and startDualWrite
 * refuses while a cleanup run of the generation is running.
 *
 * Optional deprovisioning afterwards (PhysicalSchemaManager::deprovision) is DDL: it commits
 * implicitly on MySQL/MariaDB, so it runs outside any transaction, after the cleanup run
 * completed; deprovision re-checks that every column it drops is empty, and an interrupted
 * deprovision is resumed by calling it again (it drops what is still there, then returns the
 * table to json).
 */
class StagingCleanup extends StorageRunner
{
    public const KIND = 'cleanup';

    protected function kind(): string
    {
        return self::KIND;
    }

    protected function requiredState(): string
    {
        return StorageState::PROVISIONED;
    }

    protected function capability(): string
    {
        return DriverSupport::BACKFILL;
    }

    protected function completesWithPending(): bool
    {
        return false;
    }

    protected function extendsBoundary(): bool
    {
        // rows inserted while provisioned are written JSON-only: their staging columns are NULL
        return false;
    }

    protected function columns(CustomTable $table): array
    {
        return ['id'];
    }

    /**
     * Start (or resume) the cleanup run: explicit intent and a JSON-authoritative table required.
     */
    public function startConfirmed(CustomTable $table, string $typedTableName): int
    {
        if (!hash_equals((string)$table->table_name, $typedTableName)) {
            throw new StorageConversionException('staging cleanup refused: the typed table name does not match', []);
        }
        $storage = TableStorage::fresh($table);
        if ($storage->state() !== StorageState::PROVISIONED || $storage->mode() !== StorageMode::JSON) {
            throw new StorageConversionException('staging cleanup refused: storage is ' . $storage->mode() . '/' . $storage->state() . ', JSON-authoritative provisioned required', []);
        }
        $runId = $this->start($table);
        StorageAudit::record($storage->tableId(), StorageState::PROVISIONED, StorageState::PROVISIONED, $storage->generation(), $storage->generation(),
            ['operation' => 'staging_cleanup_start', 'run_id' => $runId], (int)\Exment::getUserId());
        return $runId;
    }

    protected function processRows(CustomTable $table, $rows, array &$counts, bool $retry): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row->id;
        }
        if (empty($ids)) {
            return [];
        }
        $columns = $this->ownedColumns($table);
        if (!empty($columns)) {
            $update = [];
            foreach ($columns as $column) {
                $update[$column] = null;
            }
            $counts['written'] += DB::table(getDBTableName($table))->whereIn('id', $ids)->update($update);
        }
        return [];
    }

    /**
     * The staging columns the plan recognises as owned and present ('exists'), plus value_original.
     *
     * @return array<int, string>
     */
    private function ownedColumns(CustomTable $table): array
    {
        $plan = app(PhysicalSchemaManager::class)->plan($table);
        $columns = [];
        foreach ($plan->columns() as $entry) {
            if ($entry['action'] === 'exists') {
                $columns[] = (string)$entry['staging_column'];
            }
        }
        if (($plan->valueOriginal()['action'] ?? null) === 'exists') {
            $columns[] = PhysicalNaming::VALUE_ORIGINAL;
        }
        return $columns;
    }
}
