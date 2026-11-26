<?php

namespace Exceedone\Exment\Services;

use Carbon\Carbon;
use Exceedone\Exment\Jobs\QueueHeartbeatJob;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\Cache;

/**
 * The queue's workers (ISS02908): whether one is running, and the "restart" signal kept.
 *
 * - The signal: "php artisan queue:restart" writes the time to the cache (illuminate:queue:restart)
 *   and a worker stops when it sees another value than when it started. Clearing Exment's cache
 *   (a file store is emptied whole) and "cache:clear" of exment:update wiped it, so a worker went
 *   on with the old code (the Meilisearch case). keepRestartSignal() puts it back.
 * - The heartbeat: every five minutes, while the queue is used (QUEUE_CONNECTION is not sync), a
 *   small job is queued; the worker that runs it writes the time. Sent but not run for
 *   STALE_MINUTES: no worker is running (the security check says so).
 *
 * The times are rows of the systems table, not the cache: clearing the cache does not lose them.
 */
class QueueWorker
{
    public const RESTART_KEY = 'illuminate:queue:restart';

    public const KEY_SENT = 'queue_heartbeat_sent_at';
    public const KEY_DONE = 'queue_heartbeat_done_at';

    /** The minutes a heartbeat may wait before the worker counts as not running. */
    public const STALE_MINUTES = 15;

    /**
     * Run what clears the cache, the restart signal of the queue kept.
     *
     * @template T
     * @param callable(): T $clear
     * @return T
     */
    public static function keepRestartSignal(callable $clear)
    {
        $signal = null;
        try {
            $signal = Cache::get(static::RESTART_KEY);
        } catch (\Throwable $e) {
            // no store to read
        }
        try {
            return $clear();
        } finally {
            if ($signal !== null) {
                try {
                    Cache::forever(static::RESTART_KEY, $signal);
                } catch (\Throwable $e) {
                    // the worker restarts within its --max-time anyway
                }
            }
        }
    }

    /** @var mixed the signal held while "php artisan cache:clear" runs */
    protected static $held = null;

    /**
     * Laravel's "php artisan cache:clear" (what a deploy often runs) empties the store and the
     * signal with it: the command tells "cache:clearing" before and "cache:cleared" after, and
     * these two hold the signal and put it back.
     */
    public static function holdSignal(): void
    {
        try {
            static::$held = Cache::get(static::RESTART_KEY);
        } catch (\Throwable $e) {
            static::$held = null;
        }
    }

    public static function putBackSignal(): void
    {
        if (static::$held === null) {
            return;
        }
        try {
            Cache::forever(static::RESTART_KEY, static::$held);
        } catch (\Throwable $e) {
            // the worker restarts within its --max-time anyway
        }
        static::$held = null;
    }

    /** Whether the site uses a queue (else every job runs in the request: nothing to watch). */
    public static function used(): bool
    {
        $connection = strval(config('queue.default', 'sync'));

        return $connection !== '' && $connection !== 'sync' && strval(config("queue.connections.{$connection}.driver", 'sync')) !== 'sync';
    }

    /** The scheduler, every five minutes: a heartbeat queued. */
    public static function beat(?Carbon $now = null): void
    {
        if (!static::used()) {
            return;
        }
        static::write(static::KEY_SENT, ($now ?? Carbon::now())->format('Y-m-d H:i:s'));
        QueueHeartbeatJob::dispatch();
    }

    /** The worker ran the heartbeat. */
    public static function done(?Carbon $now = null): void
    {
        static::write(static::KEY_DONE, ($now ?? Carbon::now())->format('Y-m-d H:i:s'));
    }

    /**
     * ok: the last heartbeat was run; stale: sent STALE_MINUTES ago and not run; unknown: none
     * sent yet (the scheduler is not running, or it just started); unused: no queue.
     */
    public static function state(?Carbon $now = null): string
    {
        if (!static::used()) {
            return 'unused';
        }
        $now = $now ?? Carbon::now();
        $sent = static::read(static::KEY_SENT);
        $done = static::read(static::KEY_DONE);
        if (!isset($sent)) {
            return 'unknown';
        }
        if (isset($done) && !$done->lessThan($sent)) {
            return 'ok';
        }
        // the one sent now may still wait for its turn
        $waiting = isset($done) ? $done : $sent;

        return $now->diffInMinutes($waiting, true) >= static::STALE_MINUTES ? 'stale' : 'ok';
    }

    public static function lastDone(): ?Carbon
    {
        return static::read(static::KEY_DONE);
    }

    protected static function write(string $key, string $value): void
    {
        try {
            \DB::table((new System())->getTable())->updateOrInsert(['system_name' => $key], ['system_value' => $value]);
        } catch (\Throwable $e) {
            \Log::debug('[Exment] the heartbeat of the queue was not written: ' . $e->getMessage());
        }
    }

    protected static function read(string $key): ?Carbon
    {
        try {
            $value = \DB::table((new System())->getTable())->where('system_name', $key)->value('system_value');
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
