<?php

namespace Exceedone\Exment\Services\SafetyCheck;

use Illuminate\Cache\Lock as BaseLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One send per safety check event at a time.
 *
 * Two sends of the same event at once (送信 still running when 再送 is pressed, or the watcher's
 * send and a 再送) would each create answer rows for the users not having one yet, so duplicates,
 * and message those users twice. Whoever starts a send takes this lock and releases it when done.
 *
 * The lock has an owner, so a send can only extend or release its own. It lives SECONDS past its
 * last refresh: a running send refreshes it, a crashed one frees the event within that time.
 * The send matters more than the lock: when the cache fails, sending goes on without it.
 * It only works across processes when they share one cache store (and not with apc).
 *
 * acquireKey() takes any other key the same way, e.g. the admin's 送信 guard.
 */
class SafetyCheckSendLock
{
    public const SECONDS = 120;

    /**
     * Refresh the lock when this long has passed since the last refresh.
     */
    public const KEEP_EVERY_SECONDS = 30;

    /**
     * @var string
     */
    protected $key;

    /**
     * @var Lock|null null when the cache failed and the send goes on without a lock
     */
    protected $lock;

    /**
     * @var int
     */
    protected $keptAt;

    /**
     * @var bool
     */
    protected $refreshable = true;

    /**
     * @var array<int, self> kept and released together with this lock
     */
    protected $companions = [];

    final protected function __construct(string $key, ?Lock $lock)
    {
        $this->key = $key;
        $this->lock = $lock;
        $this->keptAt = now()->getTimestamp();
    }

    /**
     * @param int|string $eventId
     */
    public static function key($eventId): string
    {
        return 'safety_check_event_send.' . $eventId;
    }

    /**
     * @param int|string $eventId
     * @return static|null null when another send of this event holds the lock
     */
    public static function acquire($eventId): ?self
    {
        return static::acquireKey(static::key($eventId));
    }

    /**
     * @return static|null null when someone else holds the lock
     */
    public static function acquireKey(string $key): ?self
    {
        try {
            $lock = Cache::lock($key, static::SECONDS);
            if (!$lock->get()) {
                return null;
            }
        } catch (\Throwable $e) {
            Log::warning('safety check send lock unavailable, sending without it', [
                'key' => $key,
                'exception' => $e,
            ]);
            $lock = null;
        }
        return new static($key, $lock);
    }

    /**
     * Have $other kept and released together with this lock.
     *
     * @return $this
     */
    public function with(self $other): self
    {
        $this->companions[] = $other;
        return $this;
    }

    /**
     * Called for every user while sending; refreshes the lock now and then.
     */
    public function keep(): void
    {
        foreach ($this->companions as $companion) {
            $companion->keep();
        }

        $now = now()->getTimestamp();
        if (!$this->lock || !$this->refreshable || $now - $this->keptAt < static::KEEP_EVERY_SECONDS) {
            return;
        }
        $this->keptAt = $now;

        try {
            if (!$this->lock instanceof BaseLock) {
                throw new \RuntimeException(get_class($this->lock) . ' cannot refresh locks.');
            }
            // lost: lapsed, or wiped with the cache (System::clearCache() flushes it with exment.use_cache);
            // take it back under the same owner unless another send got it meanwhile
            if (!$this->lock->refresh() && !$this->lock->get()) {
                Log::warning('safety check send lock lost while sending', ['key' => $this->key]);
            }
        } catch (\Throwable $e) {
            // e.g. a cache driver that cannot refresh locks; the lock then lapses after SECONDS
            $this->refreshable = false;
            Log::warning('safety check send lock cannot be refreshed', ['key' => $this->key, 'exception' => $e]);
        }
    }

    /**
     * Releases the lock if this send still owns it.
     */
    public function release(): void
    {
        foreach ($this->companions as $companion) {
            $companion->release();
        }

        if (!$this->lock) {
            return;
        }
        try {
            $this->lock->release();
        } catch (\Throwable $e) {
            Log::warning('safety check send lock release failed', ['key' => $this->key, 'exception' => $e]);
        }
    }
}
