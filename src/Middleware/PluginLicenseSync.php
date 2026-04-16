<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Services\Plugin\PluginLicenseSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PluginLicenseSync
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // No-op when marketplace is not configured (OSS mode).
        if (!config('exment.market_tenant_uuid')) {
            return $next($request);
        }

        // License sync only needs to run on page loads, not on every AJAX call.
        if ($request->ajax() || $request->expectsJson()) {
            return $next($request);
        }

        try {
            // Run after authentication on all admin requests.
            if (\Exment::user()) {
                (new PluginLicenseSyncService())->syncThrottled(1440);
            }
        } catch (\Throwable $e) {
            Log::warning('[PluginLicenseSync] Failed: ' . $e->getMessage());
        }

        return $next($request);
    }
}
