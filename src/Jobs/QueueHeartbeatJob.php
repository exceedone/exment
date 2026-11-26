<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\QueueWorker;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The heartbeat of the queue (ISS02908, QueueWorker): run by a worker, it writes the time, so the
 * site knows a worker is running.
 */
class QueueHeartbeatJob implements ShouldQueue
{
    use JobTrait;

    public function __construct()
    {
        // one is sent every five minutes: a failed one is not tried again
        $this->tries = 1;
    }

    public function handle(): void
    {
        QueueWorker::done();
    }
}
