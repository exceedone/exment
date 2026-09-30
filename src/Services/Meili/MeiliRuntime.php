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
}
