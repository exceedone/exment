<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\QueueWorker;
use Illuminate\Console\Command;

/**
 * php artisan exment:queue-heartbeat -- the heartbeat of the queue's worker (ISS02908,
 * QueueWorker), queued by the scheduler every five minutes while a queue is used; and its state.
 */
class QueueHeartbeatCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'exment:queue-heartbeat {--status : only show whether a worker runs}';

    /**
     * @var string
     */
    protected $description = 'Queue the heartbeat of the queue worker, or show whether a worker runs.';

    public function handle(): int
    {
        if (!QueueWorker::used()) {
            $this->line('No queue: QUEUE_CONNECTION is sync.');

            return 0;
        }
        if (!boolval($this->option('status'))) {
            QueueWorker::beat();
        }
        $last = QueueWorker::lastDone();
        $this->line('state: ' . QueueWorker::state() . ', last run by a worker: ' . (isset($last) ? $last->format('Y-m-d H:i:s') : '-'));

        return 0;
    }
}
