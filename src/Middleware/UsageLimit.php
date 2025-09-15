<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Exceedone\Exment\Services\TenantUsageService;
use Exceedone\Exment\Model\Tenant;

class UsageLimit
{
    public function handle($request, Closure $next)
    {
        try {
            $context = TenantUsageService::getCurrentSubdomainWithUsage();
            if (!$context['success']) {
                return $next($request);
            }

            if (!$this->shouldCheckUsage($request)) {
                return $next($request);
            }

            $subdomain = $context['data']['subdomain'];
            $tenantRow = TenantUsageService::getCurrentTenant($subdomain);
            if (!$tenantRow) {
                return $this->tenantNotFoundResponse($request);
            }

            $planLimitGb = $this->extractPlanLimitGb($tenantRow);
            if ($planLimitGb <= 0) {
                return $this->planLimitNotSetResponse($request);
            }

            $currentBytes = (int) $context['data']['total_usage_bytes'];
            $incomingBytes = $this->estimateIncomingBytes($request);
            $projectedBytes = $currentBytes + $incomingBytes;
            $limitBytes = (int) round($planLimitGb * 1024 * 1024 * 1024);

            if ($projectedBytes > $limitBytes) {
                return $this->limitExceededResponse($request);
            }

            $this->cacheProjectedUsage($subdomain, $projectedBytes);
            return $next($request);
        } catch (\Throwable $e) {
            Log::error('UsageLimit middleware failed', [
                'error' => $e->getMessage(),
            ]);
            if ($request->pjax()) {
                admin_toastr('Usage limit check failed', 'error');
                return redirect($request->header('referer', '/'))->withInput();
            } else {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'USAGE_LIMIT_CHECK_FAILED',
                    'message' => 'Usage limit check failed'
                ], 500);
            }
        }
    }

    /**
     * Estimate incoming bytes of the request (body + uploaded files)
     */
    protected function estimateIncomingBytes($request): int
    {
        $bytes = 0;

        // Raw content length if available
        $contentLength = (int) ($request->header('Content-Length') ?? 0);
        if ($contentLength > 0) {
            $bytes = max($bytes, $contentLength);
        }

        // Sum uploaded file sizes
        try {
            $files = $request->allFiles();
            foreach ($files as $fileGroup) {
                $bytes += $this->sumFileSizes($fileGroup);
            }
        } catch (\Throwable $e) {
        }

        // Optional explicit hint from client: X-Incoming-Bytes
        $hint = (int) ($request->header('X-Incoming-Bytes') ?? 0);
        if ($hint > 0) {
            $bytes = max($bytes, $hint);
        }

        return $bytes;
    }

    /**
     * Recursively sum file sizes
     */
    protected function sumFileSizes($file): int
    {
        $total = 0;
        if (is_array($file)) {
            foreach ($file as $f) {
                $total += $this->sumFileSizes($f);
            }
            return $total;
        }
        if (method_exists($file, 'getSize')) {
            return (int) $file->getSize();
        }
        return 0;
    }

    protected function shouldCheckUsage($request): bool
    {
        $files = (array) ($request->allFiles() ?? []);
        $contentType = strtolower((string) ($request->header('Content-Type') ?? ''));
        $hasFiles = !empty($files);
        $isUpload = $hasFiles
            || (strpos($contentType, 'multipart/form-data') !== false)
            || (strpos($contentType, 'application/octet-stream') !== false);

        $method = strtoupper((string) $request->method());
        // check isLargeWritePayload
        $contentLength = (int) ($request->header('Content-Length') ?? 0);
        $isLargeWritePayload = in_array($method, ['POST', 'PUT'], true)
            && $contentLength > (2 * 1024 * 1024);

        return $isUpload || $isLargeWritePayload;
    }

    protected function extractPlanLimitGb($tenantRow): float
    {
        $planInfo = [];
        if (isset($tenantRow->plan_info)) {
            $decoded = json_decode($tenantRow->plan_info, true);
            if (is_array($decoded)) {
                $planInfo = $decoded;
            }
        }
        return (float) ($planInfo['db_size_gb'] ?? 0);
    }

    protected function cacheProjectedUsage(string $subdomain, int $projectedBytes): void
    {
        $usageCacheKey = TenantUsageService::getUsageCacheKey($subdomain);
        Cache::put($usageCacheKey, $projectedBytes, TenantUsageService::CACHE_TIME_SECONDS);
    }

    protected function tenantNotFoundResponse($request)
    {
        if ($request->pjax()) {
            admin_toastr('Tenant not found', 'error');
            return redirect($request->header('referer', '/'))->withInput();
        }
        return new JsonResponse([
            'success' => false,
            'error' => 'TENANT_NOT_FOUND',
            'message' => 'Tenant not found'
        ], 404);
    }

    protected function planLimitNotSetResponse($request)
    {
        if ($request->pjax()) {
            admin_toastr('Plan limit (db_size_gb) is not set', 'error');
            return redirect($request->header('referer', '/'))->withInput();
        }
        return new JsonResponse([
            'success' => false,
            'error' => 'PLAN_LIMIT_NOT_SET',
            'message' => 'Plan limit (db_size_gb) is not set'
        ], 403);
    }

    protected function limitExceededResponse($request)
    {
        if ($request->pjax()) {
            admin_toastr(exmtrans('tenant.plan_limit_exceeded'), 'error');
            return redirect($request->header('referer', '/'))->withInput();
        }
        return new JsonResponse([
            'success' => false,
            'error' => exmtrans('tenant.plan_limit_exceeded'),
            '0' => exmtrans('tenant.plan_limit_exceeded'),
            'message' => 'Total data size limit exceeded'
        ], 403);
    }
}
