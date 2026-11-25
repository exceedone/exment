<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only status of the conversion of one table, for the management screen (Stage B block 5).
 *
 * Reads only: no lock, no DDL, no transition, no availability requirement. It reports the
 * preflight (ConversionEligibility + the physical schema plan) which is NOT the eligibility of
 * an action: every action re-checks its own preconditions in its service, under its own locks.
 * Counts, ids, column names and reason codes only - never a cell value.
 */
final class StorageConversionStatus
{
    /** @var PhysicalSchemaManager */
    private $schema;
    /** @var ConversionAvailability */
    private $availability;

    public function __construct(PhysicalSchemaManager $schema, ConversionAvailability $availability)
    {
        $this->schema = $schema;
        $this->availability = $availability;
    }

    /** @return array{available: bool, missing: array<int, string>, driver: string, installed: bool} */
    public function execution(): array
    {
        return [
            'available' => $this->availability->isAvailable(),
            'missing' => $this->availability->missing(),
            'driver' => PhysicalSchemaManager::driverFamily(),
            'installed' => TableStorage::installed() && DB::connection()->getSchemaBuilder()->hasTable(StorageRunRepository::RUNS),
        ];
    }

    /**
     * Business tables (R6: system tables are listed separately as not convertible).
     *
     * @return array<int, array<string, mixed>>
     */
    public function tables(): array
    {
        $rows = [];
        foreach (CustomTable::allRecords() as $table) {
            $storage = $this->storage($table);
            $rows[] = [
                'id' => (int)$table->id,
                'table_name' => (string)$table->table_name,
                'table_view_name' => (string)$table->table_view_name,
                'system_table' => ConversionEligibility::isSystemTable($table),
                'storage' => $storage ? $storage->toArray() : null,
            ];
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    public function table(CustomTable $table): array
    {
        $storage = $this->storage($table);
        $plan = null;
        $planError = null;
        try {
            $plan = $this->schema->plan($table);
        } catch (\Throwable $ex) {
            $planError = get_class($ex);
        }
        $eligibility = TableStorage::installed() ? ConversionEligibility::check($table, $plan) : null;

        $generation = $storage ? $storage->generation() : 0;
        $runs = [];
        $l5 = null;
        if ($storage && DB::connection()->getSchemaBuilder()->hasTable(StorageRunRepository::RUNS)) {
            foreach ([StorageRunRepository::KIND_BACKFILL, StorageRunRepository::KIND_VERIFY, StagingCleanup::KIND] as $kind) {
                $run = StorageRunRepository::latest((int)$table->id, $kind, $generation);
                if ($run) {
                    $runs[$kind] = [
                        'id' => (int)$run->id,
                        'status' => $run->status,
                        'result' => $run->result,
                        'boundary_id' => (int)$run->boundary_id,
                        'checkpoint_id' => (int)$run->checkpoint_id,
                        'pending' => StorageRunRepository::pendingCount((int)$run->id),
                        'counts' => StorageRunRepository::counts($run),
                        'error' => $run->error,
                        'started_at' => $run->started_at,
                        'finished_at' => $run->finished_at,
                    ];
                    if ($kind === StorageRunRepository::KIND_VERIFY && $run->status === StorageRunRepository::STATUS_COMPLETED) {
                        $l5 = StorageL5Report::fromRun($run)->toArray();
                    }
                }
            }
        }

        // provisioning is complete only when the live schema has every planned column
        $provisionComplete = $plan !== null && empty($plan->toAdd()) && ($plan->valueOriginal()['action'] ?? null) === 'exists' && $plan->isProvisionable();

        $autoIndex = null;
        $indexOutcomes = [];
        try {
            $autoIndex = app(StorageAutoIndexer::class)->plan($table);
        } catch (\Throwable $ex) {
            $autoIndex = ['error' => get_class($ex)];
        }
        if (DB::connection()->getSchemaBuilder()->hasTable(StorageRunRepository::RUNS)) {
            $indexOutcomes = DB::table(StorageRunRepository::RUNS)->where('custom_table_id', $table->id)->where('kind', StorageAutoIndexer::KIND)
                ->orderByDesc('id')->limit(10)->get()->map(function ($run) {
                    $c = Projection::decode($run->counts) ?? [];
                    return ['status' => $run->status, 'result' => $run->result, 'index_name' => $c['index_name'] ?? null,
                        'column' => $c['column'] ?? null, 'reason' => $c['reason'] ?? null, 'finished_at' => $run->finished_at];
                })->all();
        }

        return [
            'provision_complete' => $provisionComplete,
            'auto_index' => $autoIndex,
            'index_outcomes' => $indexOutcomes,
            'storage' => $storage ? $storage->toArray() : null,
            'preflight' => [
                'eligible' => $eligibility ? $eligibility->isEligible() : false,
                'blockers' => $eligibility ? $eligibility->blockers() : [['code' => 'storage_not_installed']],
                'warnings' => $eligibility ? $eligibility->warnings() : [],
                'dependencies' => $eligibility ? $eligibility->dependencies() : ConversionEligibility::DEPENDENCIES,
                'plan_error' => $planError,
            ],
            'plan' => $plan ? $plan->toArray() : null,
            'runs' => $runs,
            'l5_report' => $l5,
            'transitions' => $this->transitions((int)$table->id),
        ];
    }

    private function storage(CustomTable $table): ?TableStorage
    {
        if (!TableStorage::installed()) {
            return null;
        }
        try {
            return TableStorage::fresh($table);
        } catch (\InvalidArgumentException $ex) {
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> the last transitions (no attestation text shown here) */
    private function transitions(int $tableId): array
    {
        if (!DB::connection()->getSchemaBuilder()->hasTable('custom_table_storage_transitions')) {
            return [];
        }
        return DB::table('custom_table_storage_transitions')->where('custom_table_id', $tableId)
            ->orderByDesc('id')->limit(20)
            ->get(['from_state', 'to_state', 'from_generation', 'to_generation', 'actor_user_id', 'created_at'])
            ->map(function ($r) {
                return (array)$r;
            })->all();
    }
}
