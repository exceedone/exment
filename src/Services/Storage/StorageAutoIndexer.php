<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Enums\StorageMode;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Bounded automatic indexes on the typed columns of a table reading physical (Stage B; 02 §15.18).
 *
 * Candidates (deterministic, in column order): single-valued columns whose EXISTING index setting
 * is on (`index_enabled`), whose physical type is one the current query routing reads from the
 * typed column (PhysicalQueryRouting::isRoutedType - numbers, dates, ids, yes/no; not strings,
 * which stay on the JSON shadow until D-6), and whose key fits the engine. Multi-valued columns
 * have no typed column. Nothing is derived from view usage or statistics (later stages).
 *
 * Budget: an explicit total-index budget is REQUIRED (config exment.storage_auto_index_budget).
 * No approved limit exists (D-8 is open; column_index_enabled_count limits index-enabled columns,
 * not indexes of a table), so without it automatic creation is disabled. Counting: every
 * secondary index of the value table as the schema reports it (Schema::getIndexes, primary key
 * excluded) - the generated column_<suuid> indexes of the index setting, system indexes
 * (parent_id, ...), manual indexes and earlier automatic ones alike. Creation is allowed while
 * count < min(budget, engine limit: 64 secondary indexes for InnoDB).
 *
 * An existing index whose FIRST column is the typed column (any name, manual or automatic) is
 * equivalent: no index is created. No index is ever dropped or replaced.
 *
 * One index per call (one per job). Protocol:
 *  1. claim under the exclusive storage lock: state physical_read / mode physical, no other
 *     index claim of the table in progress; an earlier claim is reconciled first from the actual
 *     schema (the index exists -> created; no index and older than the claim timeout ->
 *     abandoned; otherwise in progress -> nothing now). The claim is a run row (kind 'index').
 *  2. state re-read just before the DDL; changed -> skipped.
 *  3. CREATE INDEX outside any transaction (DDL commits implicitly; it is never assumed to roll
 *     back); the outcome is decided from the actual schema afterwards, also after an error.
 * Outcomes (run result + counts): created / skipped(reason) / failed(db error code); column
 * names, index names and codes only.
 */
class StorageAutoIndexer
{
    public const KIND = 'index';
    public const CLAIM_TIMEOUT_SECONDS = 3600;

    /** @var ConversionAvailability */
    private $availability;

    public function __construct(ConversionAvailability $availability)
    {
        $this->availability = $availability;
    }

    /** The configured total-index budget, or null (automatic creation disabled). */
    public static function budget(): ?int
    {
        $budget = config('exment.storage_auto_index_budget');
        if ($budget === null || $budget === '' || !is_numeric($budget) || (int)$budget < 1) {
            return null;
        }
        return (int)$budget;
    }

    /**
     * Read-only plan of the table's automatic indexes.
     *
     * @return array<string, mixed>
     */
    public function plan(CustomTable $table): array
    {
        $db_table_name = getDBTableName($table);
        $schema = DB::connection()->getSchemaBuilder();
        $budget = static::budget();
        $engineMax = PhysicalSchemaManager::driverFamily() === 'sqlsrv' ? PhysicalSchemaManager::SQLSRV_MAX_INDEXES : PhysicalSchemaManager::MYSQL_MAX_INDEXES;
        $keyMax = PhysicalSchemaManager::driverFamily() === 'sqlsrv' ? PhysicalSchemaManager::SQLSRV_MAX_KEY_BYTES : PhysicalSchemaManager::MYSQL_MAX_KEY_BYTES;

        $indexes = $schema->hasTable($db_table_name) ? $schema->getIndexes($db_table_name) : [];
        $columnsPresent = [];
        if ($schema->hasTable($db_table_name)) {
            foreach ($schema->getColumns($db_table_name) as $c) {
                $columnsPresent[(string)$c['name']] = true;
            }
        }
        $secondary = array_values(array_filter($indexes, function ($i) {
            return empty($i['primary']);
        }));
        $leading = [];
        foreach ($secondary as $i) {
            $first = $i['columns'][0] ?? null;
            if ($first !== null) {
                $leading[(string)$first][] = (string)$i['name'];
            }
        }

        $candidates = [];
        foreach ($table->custom_columns_cache as $column) {
            $type = PhysicalColumnType::of($column);
            $entry = ['column' => (string)$column->column_name, 'staging_column' => null, 'index_name' => null, 'status' => null];
            if (!$column->index_enabled) {
                continue; // only columns whose existing index setting is on
            }
            if ($type === null) {
                $entry['status'] = 'excluded_multivalue_or_unsupported';
            } elseif (!PhysicalQueryRouting::isRoutedType($type)) {
                $entry['status'] = 'excluded_not_routed';
            } elseif (PhysicalColumnType::keyBytes($type) === null || PhysicalColumnType::keyBytes($type) > $keyMax) {
                $entry['status'] = 'excluded_key_type';
            } else {
                $pcol = PhysicalNaming::stagingColumn($column);
                $entry['staging_column'] = $pcol;
                $entry['index_name'] = PhysicalNaming::stagingIndex($column);
                if (!isset($columnsPresent[$pcol])) {
                    $entry['status'] = 'excluded_column_missing';
                } elseif (in_array($entry['index_name'], $leading[$pcol] ?? [], true)) {
                    $entry['status'] = 'present';
                } elseif (!empty($leading[$pcol])) {
                    $entry['status'] = 'equivalent_exists';
                } else {
                    $entry['status'] = 'candidate';
                }
            }
            $candidates[] = $entry;
        }

        $limit = $budget === null ? null : min($budget, $engineMax);
        return [
            'enabled' => $budget !== null,
            'disabled_reason' => $budget === null ? 'no_configured_budget' : null,
            'budget' => $budget,
            'engine_max' => $engineMax,
            'limit' => $limit,
            'existing' => count($secondary),
            'headroom' => $limit === null ? null : max(0, $limit - count($secondary)),
            'sqlsrv_index_mode' => static::sqlServerIndexMode(),
            'candidates' => $candidates,
        ];
    }

    /**
     * Create at most one index.
     *
     * @return array{outcome: string, reason: string|null, index_name: string|null, remaining: int}
     */
    public function runOnce(CustomTable $table): array
    {
        if (!$this->availability->isAvailable()) {
            throw new StorageConversionException('conversion is not available', ['missing' => $this->availability->missing()]);
        }
        DriverSupport::require(DriverSupport::AUTO_INDEX);
        if (static::budget() === null) {
            throw new StorageConversionException('automatic indexes are disabled: no configured index budget (exment.storage_auto_index_budget)', []);
        }
        $db_table_name = getDBTableName($table);
        $sqlsrvMode = static::sqlServerIndexMode();
        if ($sqlsrvMode === 'refused') {
            return ['outcome' => 'skipped', 'reason' => 'sqlsrv_online_index_unavailable', 'index_name' => null, 'remaining' => 0];
        }

        // 1. claim
        $claim = DB::transaction(function () use ($table) {
            $locked = TableStorage::lockedForTransition((int)$table->id);
            if ($locked->state() !== StorageState::PHYSICAL_READ || $locked->mode() !== StorageMode::PHYSICAL) {
                return ['outcome' => 'skipped', 'reason' => 'state_not_physical_read'];
            }
            $open = DB::table(StorageRunRepository::RUNS)->where('custom_table_id', $table->id)->where('kind', self::KIND)
                ->where('status', StorageRunRepository::STATUS_RUNNING)->lockForUpdate()->get();
            foreach ($open as $run) {
                $settled = $this->reconcile($table, $run);
                if ($settled === null) {
                    return ['outcome' => 'skipped', 'reason' => 'claim_in_progress'];
                }
            }
            $plan = $this->plan($table);
            $next = null;
            foreach ($plan['candidates'] as $c) {
                if ($c['status'] === 'candidate') {
                    $next = $c;
                    break;
                }
            }
            if ($next === null) {
                return ['outcome' => 'skipped', 'reason' => 'nothing_to_create'];
            }
            if ($plan['headroom'] < 1) {
                $this->record($table, $locked->generation(), $next, 'skipped', 'budget_exhausted');
                return ['outcome' => 'skipped', 'reason' => 'budget_exhausted', 'index_name' => $next['index_name']];
            }
            $runId = StorageRunRepository::create((int)$table->id, self::KIND, $locked->generation(), null);
            StorageRunRepository::update($runId, ['counts' => ['index_name' => $next['index_name'], 'column' => $next['column'], 'staging_column' => $next['staging_column']]]);
            return ['outcome' => 'claimed', 'run_id' => $runId, 'next' => $next, 'generation' => $locked->generation()];
        });
        if ($claim['outcome'] !== 'claimed') {
            return ['outcome' => $claim['outcome'], 'reason' => $claim['reason'] ?? null, 'index_name' => $claim['index_name'] ?? null, 'remaining' => $this->remaining($table)];
        }
        $next = $claim['next'];
        $runId = (int)$claim['run_id'];
        $claim['sqlsrv_mode'] = $sqlsrvMode;

        // 2. state re-read right before the DDL
        $now = TableStorage::fresh($table);
        if ($now->state() !== StorageState::PHYSICAL_READ || $now->generation() !== (int)$claim['generation']) {
            $this->finish($runId, $next, 'skipped', 'state_changed');
            return ['outcome' => 'skipped', 'reason' => 'state_changed', 'index_name' => $next['index_name'], 'remaining' => $this->remaining($table)];
        }

        // 3. DDL outside any transaction, outcome from the actual schema
        $error = null;
        try {
            if ($claim['sqlsrv_mode'] === 'online') {
                // SQL Server: ONLINE = ON keeps the table readable/writable while the index builds
                $grammar = DB::connection()->getQueryGrammar();
                DB::statement('CREATE INDEX ' . $grammar->wrap($next['index_name']) . ' ON ' . $grammar->wrapTable($db_table_name)
                    . ' (' . $grammar->wrap($next['staging_column']) . ') WITH (ONLINE = ON)');
            } else {
                DB::connection()->getSchemaBuilder()->table($db_table_name, function (Blueprint $blueprint) use ($next) {
                    $blueprint->index([$next['staging_column']], $next['index_name']);
                });
            }
        } catch (\Illuminate\Database\QueryException $ex) {
            $error = PhysicalSchemaManager::dbErrorCode($ex);
        }
        if ($this->indexExists($db_table_name, $next['index_name'])) {
            $this->finish($runId, $next, 'created', $error === null ? null : 'existed_after_error');
            return ['outcome' => 'created', 'reason' => null, 'index_name' => $next['index_name'], 'remaining' => $this->remaining($table)];
        }
        $this->finish($runId, $next, 'failed', $error ?? 'not_created');
        return ['outcome' => 'failed', 'reason' => $error ?? 'not_created', 'index_name' => $next['index_name'], 'remaining' => $this->remaining($table)];
    }

    /**
     * How an index may be built on SQL Server (null on other drivers):
     *  - 'online'  : the engine edition supports CREATE INDEX ... WITH (ONLINE = ON)
     *                (SERVERPROPERTY('EngineEdition') 3 = Enterprise/Developer/Evaluation,
     *                5 = Azure SQL Database, 8 = Azure SQL Managed Instance);
     *  - 'offline' : other editions, only with exment.storage_auto_index_sqlsrv_allow_offline
     *                (an offline build blocks the table - operate it in a maintenance window);
     *  - 'refused' : other editions without that setting.
     */
    public static function sqlServerIndexMode(): ?string
    {
        if (PhysicalSchemaManager::driverFamily() !== 'sqlsrv') {
            return null;
        }
        try {
            $edition = (int)(DB::selectOne("SELECT CAST(SERVERPROPERTY('EngineEdition') AS int) AS e")->e ?? 0);
        } catch (\Throwable $ex) {
            $edition = 0;
        }
        if (in_array($edition, [3, 5, 8], true)) {
            return 'online';
        }
        return boolval(config('exment.storage_auto_index_sqlsrv_allow_offline', false)) ? 'offline' : 'refused';
    }

    /**
     * Settle an earlier claim from the actual schema. Returns null while it may still be running.
     */
    private function reconcile(CustomTable $table, $run): ?string
    {
        $counts = Projection::decode($run->counts ?? null) ?? [];
        $name = (string)($counts['index_name'] ?? '');
        if ($name !== '' && $this->indexExists(getDBTableName($table), $name)) {
            $this->finish((int)$run->id, $counts, 'created', 'reconciled');
            return 'created';
        }
        $age = time() - strtotime((string)$run->started_at);
        if ($age > (int)config('exment.storage_auto_index_claim_timeout', self::CLAIM_TIMEOUT_SECONDS)) {
            $this->finish((int)$run->id, $counts, 'failed', 'abandoned');
            return 'abandoned';
        }
        return null;
    }

    private function indexExists(string $db_table_name, string $name): bool
    {
        foreach (DB::connection()->getSchemaBuilder()->getIndexes($db_table_name) as $i) {
            if ((string)$i['name'] === $name) {
                return true;
            }
        }
        return false;
    }

    private function remaining(CustomTable $table): int
    {
        $plan = $this->plan($table);
        if (!$plan['enabled'] || $plan['headroom'] < 1) {
            return 0;
        }
        return count(array_filter($plan['candidates'], function ($c) {
            return $c['status'] === 'candidate';
        }));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function record(CustomTable $table, int $generation, array $entry, string $outcome, ?string $reason): void
    {
        $runId = StorageRunRepository::create((int)$table->id, self::KIND, $generation, null);
        $this->finish($runId, $entry, $outcome, $reason);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function finish(int $runId, array $entry, string $outcome, ?string $reason): void
    {
        StorageRunRepository::update($runId, [
            'status' => StorageRunRepository::STATUS_COMPLETED,
            'result' => $outcome,
            'counts' => [
                'index_name' => $entry['index_name'] ?? null,
                'column' => $entry['column'] ?? null,
                'staging_column' => $entry['staging_column'] ?? null,
                'outcome' => $outcome,
                'reason' => $reason,
            ],
            'finished_at' => now(),
        ]);
    }
}
