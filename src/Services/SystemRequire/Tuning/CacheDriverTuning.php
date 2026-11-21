<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * Where the cache lives. Redis (or Memcached, APCu) is faster than files,
 * and is needed when several web servers serve one site.
 */
class CacheDriverTuning extends TuningBase
{
    public const FAST_DRIVERS = ['redis', 'memcached', 'apc', 'octane'];

    protected function key(): string
    {
        return 'cache_driver';
    }

    protected function driver(): string
    {
        $store = (string) config('cache.default');
        return (string) config("cache.stores.{$store}.driver", $store);
    }

    public function checkResult(): string
    {
        return in_array($this->driver(), static::FAST_DRIVERS, true) ? SystemRequireResult::OK : SystemRequireResult::WARNING;
    }

    public function getResultText(): ?string
    {
        return $this->driver() . ' / ' . (string) config('session.driver');
    }

    public function getFixText(): ?string
    {
        return "# .env (Redis)\nCACHE_STORE=redis\nSESSION_DRIVER=redis";
    }
}
