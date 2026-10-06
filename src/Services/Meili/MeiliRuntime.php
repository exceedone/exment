<?php

namespace Exceedone\Exment\Services\Meili;

/**
 * Runtime gates for automatic sync and scheduled repair. The global-search
 * switch controls only which backend serves read requests.
 */
final class MeiliRuntime
{
    public static function realtimeSyncEnabled(): bool
    {
        return boolval(config('meilisearch.realtime_sync'));
    }

    /** The default connection can have any name; inspect its actual driver. */
    public static function queueUsesSyncDriver(): bool
    {
        $connection = (string) config('queue.default', 'sync');
        return config('queue.connections.' . $connection . '.driver') === 'sync';
    }

    public static function reconcileScheduleEnabled(): bool
    {
        return boolval(config('meilisearch.repair_enabled'));
    }
    public static function attachmentQueueName(): string
    {
        return (string) config('meilisearch.attachment_queue', 'meili-attachments');
    }

    /**
     * What is waiting on the attachment queue. "pending" counts only jobs that
     * are runnable and NOT reserved: Queue::size() counts in-flight jobs too,
     * so it cannot tell a stalled queue from a busy one.
     *
     * @return array{pending:int|null,reserved:int|null,oldest_seconds:int|null}
     */
    public static function attachmentQueueBacklog(): array
    {
        $queue = self::attachmentQueueName();
        $out = ['pending' => null, 'reserved' => null, 'oldest_seconds' => null];

        try {
            $connection = (string) config('queue.default', 'sync');
            if (config('queue.connections.' . $connection . '.driver') !== 'database') {
                // Elsewhere only the total is available, and it includes
                // reserved jobs - enough to report, not enough to judge.
                $out['pending'] = (int) \Illuminate\Support\Facades\Queue::size($queue);

                return $out;
            }

            $table = (string) config('queue.connections.' . $connection . '.table', 'jobs');
            $now = time();
            $base = \Illuminate\Support\Facades\DB::table($table)->where('queue', $queue);

            $out['reserved'] = (int) (clone $base)->whereNotNull('reserved_at')->count();

            // available_at, not created_at: a deliberately delayed job has not
            // been waiting for anyone yet.
            $waiting = (clone $base)->whereNull('reserved_at')->where('available_at', '<=', $now);
            $out['pending'] = (int) (clone $waiting)->count();
            $oldest = (clone $waiting)->min('available_at');
            if ($oldest !== null) {
                $out['oldest_seconds'] = max(0, $now - (int) $oldest);
            }
        } catch (\Throwable $e) {
            return ['pending' => null, 'reserved' => null, 'oldest_seconds' => null];
        }

        return $out;
    }

    /**
     * Attachment extraction runs on its own queue, so a worker started with the
     * usual --queue=default,meili-reindex drains nothing and fails nothing: the
     * jobs just pile up. A runnable job nobody reserved for minutes is the only
     * signal available - and a reserved job proves a worker is alive, however
     * long the backlog has been there.
     *
     * @param array{pending?:int|null,reserved?:int|null,oldest_seconds?:int|null} $backlog
     */
    public static function attachmentQueueLooksUnworked(array $backlog, int $thresholdSeconds = 300): bool
    {
        if (!empty($backlog['reserved'])) {
            return false;
        }

        $pending = $backlog['pending'] ?? null;
        $oldest = $backlog['oldest_seconds'] ?? null;

        return $pending !== null
            && $pending > 0
            && $oldest !== null
            && $oldest > $thresholdSeconds;
    }
}
