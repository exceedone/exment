<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Model\SyncRun;
use Exceedone\Exment\Services\Sync\SyncEngine;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A part of a run of the data sync (SyncEngine, ISS00113), then the next part as a new job, so
 * a worker is not held. Without a worker (the sync driver) the parts follow one another here.
 */
class SyncRunJob implements ShouldQueue
{
    use JobTrait;

    public function __construct(public int $runId)
    {
        // a part is not tried again: the next run goes on from the cursor (set here: JobTrait
        // declares them, and a property declared again with another value is a fatal error)
        $this->tries = 1;
        $this->timeout = 600;
        $this->afterCommit();
    }

    public function handle(): void
    {
        $this->resetRequestSessionOnWorker();
        /** @var SyncRun|null $run */
        $run = SyncRun::query()->find($this->runId);
        if (!isset($run) || $run->status !== SyncRun::STATUS_RUNNING) {
            return;
        }
        $done = SyncEngine::step($run);
        if (!$done) {
            static::dispatch($this->runId);
        }
    }
}
