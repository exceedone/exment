<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * "php artisan config:cache" puts every config file into one file, read once per request.
 *
 * Only the config is cached. The routes are not: Exment adds the routes of the plugins from
 * the database on every request (PluginServiceProvider), so "route:cache" would hide a plugin
 * installed later. Exment clears this cache itself whenever it writes .env (EnvService::setEnv).
 */
class ConfigCacheTuning extends TuningBase
{
    public const ACTION_CACHE = 'config_cache';
    public const ACTION_CLEAR = 'config_clear';

    protected function key(): string
    {
        return 'config_cache';
    }

    /**
     * Whether the cache file is there now (configurationIsCached() keeps the answer
     * of the start of the request).
     */
    public static function cached(): bool
    {
        return is_file(app()->getCachedConfigPath());
    }

    public function checkResult(): string
    {
        return static::cached() ? SystemRequireResult::OK : SystemRequireResult::WARNING;
    }

    public function getFixText(): ?string
    {
        return "php artisan config:cache";
    }

    public function getAction(): ?string
    {
        return static::cached() ? static::ACTION_CLEAR : static::ACTION_CACHE;
    }
}
