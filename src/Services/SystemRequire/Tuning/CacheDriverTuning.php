<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * Where the cache and the sessions live. Redis (or Memcached, APCu) is faster than files,
 * and is needed when several web servers serve one site.
 * Redis needs the redis extension of PHP (phpredis, the default client of Laravel) (ISS02823).
 */
class CacheDriverTuning extends TuningBase
{
    public const FAST_DRIVERS = ['redis', 'memcached', 'apc', 'octane'];

    /** @var bool|null whether the redis extension is loaded (a test sets it) */
    public $redisExtension;

    protected function key(): string
    {
        return 'cache_driver';
    }

    protected function driver(): string
    {
        $store = (string) config('cache.default');
        return (string) config("cache.stores.{$store}.driver", $store);
    }

    public function redisExtension(): bool
    {
        return $this->redisExtension ?? extension_loaded('redis');
    }

    /**
     * Redis is used (cache or sessions) with phpredis, but PHP has no redis extension.
     */
    public function redisWithoutExtension(): bool
    {
        $usesRedis = $this->driver() === 'redis' || (string) config('session.driver') === 'redis';
        return $usesRedis && (string) config('database.redis.client', 'phpredis') === 'phpredis' && !$this->redisExtension();
    }

    public function checkResult(): string
    {
        if ($this->redisWithoutExtension()) {
            return SystemRequireResult::WARNING;
        }
        return in_array($this->driver(), static::FAST_DRIVERS, true) ? SystemRequireResult::OK : SystemRequireResult::WARNING;
    }

    public function getResultText(): ?string
    {
        return $this->driver() . ' / ' . (string) config('session.driver')
            . ' (' . exmtrans('tuning.item.cache_driver.redis_extension', [
                'result' => $this->redisExtension() ? exmtrans('tuning.item.cache_driver.extension_yes') : exmtrans('tuning.item.cache_driver.extension_no'),
            ]) . ')';
    }

    protected function getMessageWarning(): ?string
    {
        return $this->redisWithoutExtension()
            ? exmtrans('tuning.item.cache_driver.warning_extension')
            : parent::getMessageWarning();
    }

    public function getFixText(): ?string
    {
        $lines = [];
        if (!$this->redisExtension()) {
            $lines[] = '# PHP: the redis extension (pecl install redis, or the package php-redis), then restart PHP';
        }
        $lines[] = "# .env (Redis)\nCACHE_STORE=redis\nSESSION_DRIVER=redis";
        return implode("\n", $lines);
    }
}
