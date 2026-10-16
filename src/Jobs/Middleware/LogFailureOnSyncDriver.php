<?php

namespace Exceedone\Exment\Jobs\Middleware;

use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;

/**
 * Keep a Meilisearch outage from breaking the user's save.
 *
 * The Meili jobs call afterCommit(), so on the 'sync' driver they run from the
 * transaction manager's commit callback - after the data is committed and long
 * after the try/catch around the dispatch has returned. An exception there
 * escapes DB::commit() and reaches the user as a 500 on a save that in fact
 * succeeded; resubmitting the form then creates a duplicate record.
 *
 * On a real worker nothing changes: the exception is rethrown so the job is
 * retried and lands in failed_jobs as before.
 */
class LogFailureOnSyncDriver
{
    /**
     * @param  mixed  $job  the job instance (has InteractsWithQueue::$job)
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($job, $next)
    {
        try {
            return $next($job);
        } catch (\Throwable $e) {
            // Not inline in the caller's request -> let the worker retry.
            if (!(($job->job ?? null) instanceof SyncJob)) {
                throw $e;
            }

            Log::warning(sprintf(
                "[Meili] %s failed on the 'sync' queue driver and was skipped to protect the caller: %s"
                . '. Run `php artisan exment:meili-index` once Meilisearch is reachable.',
                class_basename($job),
                $e->getMessage()
            ));
        }
    }
}
