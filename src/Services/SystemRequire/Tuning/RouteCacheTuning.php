<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * The route cache (php artisan route:cache) must not be there (ISS02823).
 * Exment adds the routes of the plugins from the database on every request
 * (PluginServiceProvider); with a route cache, a plugin installed later cannot be opened.
 * So "set" here means that there is no route cache.
 */
class RouteCacheTuning extends TuningBase
{
    protected function key(): string
    {
        return 'route_cache';
    }

    public static function cached(): bool
    {
        return is_file(app()->getCachedRoutesPath());
    }

    public function checkResult(): string
    {
        return static::cached() ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        return static::cached() ? exmtrans('tuning.item.route_cache.cached') : exmtrans('tuning.item.route_cache.none');
    }

    public function getFixText(): ?string
    {
        return 'php artisan route:clear';
    }
}
