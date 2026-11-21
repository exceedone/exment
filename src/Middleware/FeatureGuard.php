<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Model\Plugin;
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
        // a page of a plugin: make its class first, so that the features it
        // registers (in its constructor) are known, also on the routes that
        // skip admin.bootstrap (as its API)
        if (str_contains('/' . trim($request->path(), '/') . '/', '/plugins/')) {
            try {
                Plugin::getPluginPageModel();
            } catch (\Throwable $e) {
                // the plugin answers for itself
            }
        }
        $feature = Features::forRequest($request);
        if ($feature === null || Features::allowed($feature)) {
            return $next($request);
        }

        return Features::deny($feature, $request);
    }
}
