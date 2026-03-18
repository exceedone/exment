<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class TenantExpired
{
    public function handle($request, Closure $next)
    {
        try {
            if (File::exists(storage_path('app/.tenant_expired'))) {
                return $this->tenantExpiredResponse($request);
            }
        } catch (\Throwable $e) {
            Log::error('TenantExpired middleware failed: ' . $e->getMessage());
        }

        return $next($request);
    }

    /**
     * Return response when tenant is expired.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    protected function tenantExpiredResponse($request)
    {
        $locale = config('exment.locale', env('APP_LOCALE', 'en'));
        app()->setLocale($locale === 'ja' ? 'ja' : 'en');

        if ($request->expectsJson()) {
            return new JsonResponse([
                'success' => false,
                'message' => exmtrans('error.tenant_expired'),
            ], 403);
        }

        return response(view('exment::exception.tenant_expired'), 403);
    }
}
