<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Model\BackupRun;
use Exceedone\Exment\Services\BackupRestore\BackupRuns;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A backup asked on the screen, run by the queue's worker (ISS02943). The run was started (queued)
 * by the request: the job runs it only while it is still queued, so a job given again (the
 * retry_after of the connection, a second worker) never runs it twice.
 */
class BackupRunJob implements ShouldQueue
{
    use JobTrait;

    public function __construct(public string $uuid)
    {
        // once only: a backup is not tried again by itself (set here: JobTrait declares them)
        $this->tries = 1;
        $this->timeout = max(60, intval(config('exment.backup.job_timeout', 21600)));
    }

    public function handle(): void
    {
        $this->resetRequestSessionOnWorker();
        \Exment::setTimeLimitLong();

        // queued -> running, once: another job given the same run finds it running
        $updated = BackupRun::query()->where('uuid', $this->uuid)->where('status', BackupRun::STATUS_QUEUED)
            ->update(['status' => BackupRun::STATUS_RUNNING, 'heartbeat_at' => now()]);
        if ($updated !== 1) {
            return;
        }
        /** @var BackupRun|null $run */
        $run = BackupRun::query()->where('uuid', $this->uuid)->first();
        if (!isset($run)) {
            return;
        }
        try {
            BackupRuns::runBackup($run);
        } catch (\Throwable $e) {
            // told in the run (failed), the bell and the mail: the job itself is not failed again
        }
    }

    /**
     * The job failed before it ran (the worker stopped it): the run waiting is told.
     */
    public function failed(?\Throwable $e = null): void
    {
        try {
            BackupRun::query()->where('uuid', $this->uuid)->where('status', BackupRun::STATUS_QUEUED)->update([
                'status' => BackupRun::STATUS_FAILED,
                'finished_at' => now(),
                'message' => strval(exmtrans('backup.run.hints.see_log')),
            ]);
        } catch (\Throwable $ex) {
            // nothing more to do
        }
    }
}
