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
        // No-op when marketplace API key is not configured.
        if (!config('exment.ai_server_api_key')) {
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
