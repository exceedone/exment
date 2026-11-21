<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Services\Features;
use Illuminate\Http\Request;

/**
 * The pages and the API of a feature that is not used (404), or that the
 * user may not use (403), even when the URL is opened directly (Admin
 * setting → Feature management). See Exceedone\Exment\Services\Features.
 */
class FeatureGuard
{
    /**
     * @param \Closure(Request): mixed $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        // the features of the plugins are known before: their classes are
        // made when the routes are made, or kept in the cache when the routes
        // are cached (Features::bootPlugins)
        $feature = Features::forRequest($request);
        if ($feature === null || Features::allowed($feature)) {
            return $next($request);
        }

        return Features::deny($feature, $request);
    }
}
