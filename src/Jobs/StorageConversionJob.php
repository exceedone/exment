<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Storage\ConversionAvailability;
use Exceedone\Exment\Services\Storage\PhysicalSchemaManager;
use Exceedone\Exment\Services\Storage\StagingCleanup;
use Exceedone\Exment\Services\Storage\StorageAutoIndexer;
use Exceedone\Exment\Services\Storage\StorageBackfill;
use Exceedone\Exment\Services\Storage\StorageRunRepository;
use Exceedone\Exment\Services\Storage\StorageRunner;
use Exceedone\Exment\Services\Storage\StorageVerifier;
use Exceedone\Exment\Services\Storage\TableStorage;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued execution of one storage conversion operation of one table (Stage B block 5):
 * provision, backfill, verify or staging cleanup. It only invokes the storage services.
 *
 * - Reloads everything: the worker's request-session cache is dropped, the table and its
 *   storage row are read fresh; a job whose generation differs from the table's is stale and
 *   does nothing (an older queued job never acts on a newer conversion).
 * - Bounded work: up to BATCHES_PER_JOB batches of BATCH_SIZE rows, then the job re-dispatches
 *   itself while the run is still running (idle backfill - only conflicting hot rows left - is
 *   re-dispatched with a delay). Progress lives in the run row (durable checkpoint), never in
 *   the job.
 * - Duplicate delivery / retries: unique per table, operation and generation until processing
 *   starts; a duplicate that runs anyway is harmless - batches of a run are serialized by the
 *   run row lock and every batch is one transaction (an interrupted batch is redone from the
 *   unchanged checkpoint). Provisioning is idempotent. No job performs a state transition
 *   other than provisioning's json -> provisioned (itself idempotent); every other transition
 *   is an explicit administrative action.
 * - Failures: recorded on the run as an error class/code only (a database message can carry
 *   values, so it is never stored); an unavailable conversion or a refused precondition fails
 *   the job without retry.
 * Requires a cache store with atomic locks for the uniqueness (operational prerequisite).
 */
class StorageConversionJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use JobTrait;

    public const OP_PROVISION = 'provision';
    public const OP_BACKFILL = 'backfill';
    public const OP_VERIFY = 'verify';
    public const OP_CLEANUP = 'cleanup';
    public const OP_INDEX = 'index';

    public const BATCH_SIZE = 1000;
    public const BATCHES_PER_JOB = 20;
    public const IDLE_DELAY_SECONDS = 60;

    /** @var int */
    public $tableId;
    /** @var string */
    public $operation;
    /** @var int */
    public $generation;

    /** @var int seconds the uniqueness lock is kept if the job is never processed */
    public $uniqueFor = 3600;

    public function __construct(int $tableId, string $operation, int $generation)
    {
        $this->tableId = $tableId;
        $this->operation = $operation;
        $this->generation = $generation;
        $this->timeout = 600;
    }

    public function uniqueId(): string
    {
        return "exment_storage_conversion_{$this->tableId}_{$this->operation}_{$this->generation}";
    }

    public function handle(): void
    {
        $this->resetRequestSessionOnWorker();
        try {
            if (!app(ConversionAvailability::class)->isAvailable()) {
                throw new StorageConversionException('conversion is not available', []);
            }
            $table = CustomTable::getEloquent($this->tableId);
            if (!$table) {
                return;
            }
            $storage = TableStorage::fresh($table);
            if ($storage->generation() !== $this->generation) {
                return; // stale job
            }

            if ($this->operation === self::OP_PROVISION) {
                app(PhysicalSchemaManager::class)->provision($table);
                return;
            }
            if ($this->operation === self::OP_INDEX) {
                if ($storage->state() !== StorageState::PHYSICAL_READ) {
                    return; // automatic indexes only on a table reading physical
                }
                // at most one index per job; the next one in a new job
                $result = app(StorageAutoIndexer::class)->runOnce($table);
                if ($result['outcome'] === 'created' && $result['remaining'] > 0) {
                    static::dispatch($this->tableId, $this->operation, $this->generation);
                }
                return;
            }

            $runner = $this->runner();
            if (!in_array($storage->state(), $this->allowedStates(), true)) {
                return; // the conversion moved on; nothing to do
            }
            $status = $runner->run($table, self::BATCH_SIZE, self::BATCHES_PER_JOB);
            if (($status['status'] ?? null) === StorageRunRepository::STATUS_RUNNING) {
                $next = static::dispatch($this->tableId, $this->operation, $this->generation);
                if ($status['idle'] ?? false) {
                    $next->delay(now()->addSeconds(self::IDLE_DELAY_SECONDS));
                }
            }
        } catch (StorageConversionException $ex) {
            $this->recordError($ex);
            $this->fail($ex);
        } catch (\Throwable $ex) {
            $this->recordError($ex);
            throw $ex;
        }
    }

    private function runner(): StorageRunner
    {
        switch ($this->operation) {
            case self::OP_BACKFILL:
                return app(StorageBackfill::class);
            case self::OP_VERIFY:
                return app(StorageVerifier::class);
            case self::OP_CLEANUP:
                return app(StagingCleanup::class);
        }
        throw new StorageConversionException("unknown storage operation {$this->operation}", []);
    }

    /** @return array<int, string> */
    private function allowedStates(): array
    {
        switch ($this->operation) {
            case self::OP_BACKFILL:
                return [StorageState::BACKFILLING];
            case self::OP_VERIFY:
                return [StorageState::VERIFYING, StorageState::PHYSICAL_READ];
            case self::OP_CLEANUP:
                return [StorageState::PROVISIONED];
        }
        return [];
    }

    /** The error class and code on the running run - never the message (it may carry values). */
    private function recordError(\Throwable $ex): void
    {
        $kind = [self::OP_BACKFILL => StorageRunRepository::KIND_BACKFILL, self::OP_VERIFY => StorageRunRepository::KIND_VERIFY, self::OP_CLEANUP => StagingCleanup::KIND,
            self::OP_INDEX => StorageAutoIndexer::KIND][$this->operation] ?? null;
        if ($kind === null) {
            return;
        }
        try {
            $run = StorageRunRepository::running($this->tableId, $kind, $this->generation);
            if ($run) {
                StorageRunRepository::update((int)$run->id, ['error' => substr(get_class($ex) . ' ' . (string)$ex->getCode(), 0, 1000)]);
            }
        } catch (\Throwable $ignored) {
            // recording the error must not hide the error itself
        }
    }
}
