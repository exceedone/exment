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
        try {
            // Run after authentication on all admin requests.
            if (\Exment::user()) {
                (new PluginLicenseSyncService())->syncThrottled(1);
            }
        } catch (\Throwable $e) {
            Log::warning('[PluginLicenseSync] Failed: ' . $e->getMessage());
        }

        return $next($request);
    }
}
