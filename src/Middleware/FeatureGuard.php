<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\Features;
use Illuminate\Http\Request;

/**
 * The pages and the API of a feature that is not used, or that the user may
 * not use (Admin setting → Feature management), are not found, even when the
 * URL is opened directly. See Exceedone\Exment\Services\Features.
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
        if ($feature !== null && !Features::allowed($feature)) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['result' => false, 'message' => exmtrans('feature.message_not_used')], 404);
            }
            // a plain "not found" (the error page of the admin pages answers 200)
            return response(e(exmtrans('feature.message_not_used')), 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        return $next($request);
    }
}
