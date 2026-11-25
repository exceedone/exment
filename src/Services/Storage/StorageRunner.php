<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\DB;

/**
 * Resumable, batched run over every row of a table's value table (backfill, verification).
 * Internal: requires ConversionAvailability (unavailable in production); no route/command/job.
 *
 * - Rows: the value table through the query builder - no Eloquent scope: soft-deleted rows and
 *   rows hidden by permissions are included.
 * - Work boundary: max(id) when the run starts (rows inserted later are written by the dual-write
 *   of the state the run requires). Verification extends it when it reaches it.
 * - One batch = one transaction. Lock order: run row FOR UPDATE (serializes concurrent runners
 *   of the run; no writer or transition locks it), then the table storage row under the shared
 *   lock, then value rows (the order of StorageWriteGate), with state and
 *   generation checked against the run (mismatch -> run stale, never resumed), the batch's rows,
 *   pending rows recorded, checkpoint + counts. An interrupted batch rolls back completely and
 *   is redone from the unchanged checkpoint.
 * - The checkpoint passes a row only once the row is settled or durably recorded as pending
 *   (same transaction). After the range, pending rows are retried; the run completes when the
 *   range is done and nothing is pending (verification: completes with a result).
 */
abstract class StorageRunner
{
    public const DEFAULT_BATCH = 1000;
    public const MAX_BATCH = 5000;
    /**
     * SQL Server: a statement takes at most 2,100 parameters (the pending / cleanup whereIn of a
     * batch), and a statement holding 5,000 locks on one table escalates to a table lock; batches
     * stay at 2,000 rows [documented limits; not yet run on an instance].
     */
    public const MAX_BATCH_SQLSRV = 2000;

    public static function maxBatch(): int
    {
        return PhysicalSchemaManager::driverFamily() === 'sqlsrv' ? self::MAX_BATCH_SQLSRV : self::MAX_BATCH;
    }

    /** @var ConversionAvailability */
    protected $availability;

    public function __construct(ConversionAvailability $availability)
    {
        $this->availability = $availability;
    }

    /** Run kind (StorageRunRepository::KIND_*). */
    abstract protected function kind(): string;

    /** The one storage state the run is allowed in. */
    abstract protected function requiredState(): string;

    /** @return array<int, string> the storage states the run may execute in (default: requiredState) */
    protected function allowedStates(): array
    {
        return [$this->requiredState()];
    }

    /** Driver capability required (DriverSupport). */
    abstract protected function capability(): string;

    /**
     * Process range rows (id, value and whatever columns()) - return per row:
     * 'settled' | 'deleted' | ['pending', reason, columns].
     *
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @param array<string, mixed> $counts
     * @return array<int, mixed> row id => outcome
     */
    abstract protected function processRows(CustomTable $table, $rows, array &$counts, bool $retry): array;

    /** @return array<int, string> columns to read for each row */
    abstract protected function columns(CustomTable $table): array;

    /** Whether the run may complete with pending rows (verification: yes, as a failed result). */
    abstract protected function completesWithPending(): bool;

    /** Extend the boundary to the current max id when reached (verification). */
    abstract protected function extendsBoundary(): bool;

    /**
     * Whether a run needs no repetition at its generation: completed with nothing pending.
     * (Verification overrides: only a passed run is settled, a failed one may be run again.)
     */
    protected function isSettled($run): bool
    {
        return $run->status === StorageRunRepository::STATUS_COMPLETED && StorageRunRepository::pendingCount((int)$run->id) === 0;
    }

    /**
     * Start a run, or return the running one of the current generation (resume).
     *
     * @return int run id
     */
    public function start(CustomTable $table): int
    {
        $this->preconditions();
        return DB::transaction(function () use ($table) {
            // exclusive: two starts cannot both create a run (rare; waits for in-flight writes)
            $storage = TableStorage::lockedForTransition((int)$table->id);
            $this->assertState($storage);
            $running = StorageRunRepository::running((int)$table->id, $this->kind(), $storage->generation());
            if ($running) {
                return (int)$running->id;
            }
            // a late or duplicate delivery after the work is done must not start the work again:
            // the settled run of this generation is returned (its step() is a no-op)
            $latest = StorageRunRepository::latest((int)$table->id, $this->kind(), $storage->generation());
            if ($latest && $this->isSettled($latest)) {
                return (int)$latest->id;
            }
            $boundary = DB::table(getDBTableName($table))->max('id');
            return StorageRunRepository::create((int)$table->id, $this->kind(), $storage->generation(), $boundary === null ? 0 : (int)$boundary);
        });
    }

    /**
     * Start or resume, then run up to $maxBatches batches.
     *
     * @return array<string, mixed> run status
     */
    public function run(CustomTable $table, int $batchSize = self::DEFAULT_BATCH, int $maxBatches = PHP_INT_MAX): array
    {
        $runId = $this->start($table);
        $status = [];
        for ($i = 0; $i < $maxBatches; $i++) {
            $status = $this->step($table, $runId, $batchSize);
            if ($status['status'] !== StorageRunRepository::STATUS_RUNNING || ($status['idle'] ?? false)) {
                break;
            }
        }
        return $status;
    }

    /**
     * One batch.
     *
     * @return array<string, mixed>
     */
    public function step(CustomTable $table, int $runId, int $batchSize = self::DEFAULT_BATCH): array
    {
        $this->preconditions();
        $batchSize = max(1, min($batchSize, static::maxBatch()));
        return DB::transaction(function () use ($table, $runId, $batchSize) {
            $run = StorageRunRepository::lock($runId);
            if (!$run || (int)$run->custom_table_id !== (int)$table->id || $run->kind !== $this->kind()) {
                throw new StorageConversionException('run not found for this table and kind', ['run' => $runId]);
            }
            if ($run->status !== StorageRunRepository::STATUS_RUNNING) {
                return $this->status($run);
            }

            $storage = StorageWriteGate::lock($table);
            if ($storage === null || !in_array($storage->state(), $this->allowedStates(), true) || $storage->generation() !== (int)$run->generation) {
                StorageRunRepository::update($runId, ['status' => StorageRunRepository::STATUS_STALE, 'finished_at' => now(),
                    'error' => 'storage state/generation changed: ' . ($storage ? $storage->state() . '/' . $storage->generation() : 'none')]);
                return $this->status(StorageRunRepository::lock($runId));
            }

            $counts = StorageRunRepository::counts($run);
            $counts['batches']++;
            $db_table_name = getDBTableName($table);
            $checkpoint = (int)$run->checkpoint_id;
            $boundary = (int)$run->boundary_id;
            $fields = [];
            $idle = false;

            if ($checkpoint < $boundary) {
                // range phase
                $rows = DB::table($db_table_name)->where('id', '>', $checkpoint)->where('id', '<=', $boundary)
                    ->orderBy('id')->limit($batchSize)->get($this->columns($table));
                $outcomes = $this->processRows($table, $rows, $counts, false);
                $last = $checkpoint;
                foreach ($rows as $row) {
                    $this->record($run, $table, (int)$row->id, $outcomes[(int)$row->id] ?? 'settled', $counts, false);
                    $last = (int)$row->id;
                }
                $counts['rows'] += $rows->count();
                $fields['checkpoint_id'] = $rows->isEmpty() ? $boundary : $last;
            } elseif ($this->extendsBoundary() && ($max = (int)DB::table($db_table_name)->max('id')) > $boundary) {
                $fields['boundary_id'] = $max;
            } else {
                // pending phase
                $pending = StorageRunRepository::pending((int)$run->id, $batchSize);
                if ($pending->isEmpty()) {
                    $fields['status'] = StorageRunRepository::STATUS_COMPLETED;
                    $fields['finished_at'] = now();
                    if ($this->completesWithPending()) {
                        $fields['result'] = StorageRunRepository::RESULT_PASSED;
                    }
                } else {
                    $ids = $pending->pluck('row_id')->map(function ($id) {
                        return (int)$id;
                    })->all();
                    $rows = DB::table($db_table_name)->whereIn('id', $ids)->orderBy('id')->get($this->columns($table))->keyBy('id');
                    $outcomes = $this->processRows($table, $rows->values(), $counts, true);
                    $stillPending = 0;
                    foreach ($ids as $id) {
                        $outcome = $rows->has($id) ? ($outcomes[$id] ?? 'settled') : 'deleted';
                        if ($this->record($run, $table, $id, $outcome, $counts, true)) {
                            $stillPending++;
                        }
                    }
                    if ($stillPending === count($ids)) {
                        // nothing resolved in this pass
                        if ($this->completesWithPending()) {
                            $fields['status'] = StorageRunRepository::STATUS_COMPLETED;
                            $fields['result'] = StorageRunRepository::RESULT_FAILED;
                            $fields['finished_at'] = now();
                        } else {
                            $idle = true; // backfill: hot rows; call again later
                        }
                    }
                }
            }

            $fields['counts'] = $counts;
            StorageRunRepository::update((int)$run->id, $fields);
            $status = $this->status(StorageRunRepository::lock((int)$run->id));
            $status['idle'] = $idle;
            return $status;
        });
    }

    /**
     * Record one row's outcome. Returns whether the row is (still) pending.
     *
     * @param mixed $outcome
     * @param array<string, mixed> $counts
     */
    private function record($run, CustomTable $table, int $rowId, $outcome, array &$counts, bool $retry): bool
    {
        if ($outcome === 'deleted') {
            $counts['deleted']++;
            if ($retry) {
                StorageRunRepository::resolvePending((int)$run->id, $rowId);
                $counts['pending_resolved']++;
            }
            return false;
        }
        if (is_array($outcome) && $outcome[0] === 'pending') {
            StorageRunRepository::addPending((int)$run->id, (int)$table->id, $rowId, (string)$outcome[1], $outcome[2] ?? []);
            if (!$retry) {
                $counts['pending_added']++;
            }
            return true;
        }
        if ($retry) {
            StorageRunRepository::resolvePending((int)$run->id, $rowId);
            $counts['pending_resolved']++;
        }
        return false;
    }

    /** @return array<string, mixed> */
    protected function status($run): array
    {
        return [
            'run_id' => (int)$run->id,
            'kind' => $run->kind,
            'generation' => (int)$run->generation,
            'status' => $run->status,
            'result' => $run->result,
            'boundary_id' => (int)$run->boundary_id,
            'checkpoint_id' => (int)$run->checkpoint_id,
            'pending' => StorageRunRepository::pendingCount((int)$run->id),
            'counts' => StorageRunRepository::counts($run),
            'error' => $run->error,
        ];
    }

    private function preconditions(): void
    {
        if (!$this->availability->isAvailable()) {
            throw new StorageConversionException('conversion is not available', ['missing' => $this->availability->missing()]);
        }
        DriverSupport::require($this->capability());
    }

    private function assertState(?TableStorage $storage): void
    {
        if ($storage === null || !in_array($storage->state(), $this->allowedStates(), true)) {
            throw new StorageConversionException('run refused: storage state is ' . ($storage ? $storage->state() : 'none') . ', allowed ' . implode('/', $this->allowedStates()), []);
        }
    }
}
