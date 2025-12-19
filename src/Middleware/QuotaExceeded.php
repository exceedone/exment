<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class QuotaExceeded
{
    public function handle($request, Closure $next)
    {
        try {            
            if (!$this->shouldCheckUsage($request)) {
                return $next($request);
            }
            if ($this->quotaExceededExists()) {
                return $this->limitExceededResponse($request);
            }

            return $next($request);
        } catch (\Throwable $e) {
            Log::error('QuotaExceeded middleware failed: ' . $e->getMessage());
            return $next($request);
        }
    }

    /**
     * Check if .quota_exceeded file exists
     * 
     * @return bool
     */
    protected function quotaExceededExists(): bool
    {
        // Kiểm tra trong storage/app
        $storagePath = storage_path('app/.quota_exceeded');
        if (File::exists($storagePath)) {
            return true;
        }

        return false;
    }

    /**
     * Return response when quota is exceeded
     * Same as UsageLimit::limitExceededResponse
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
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
    protected function shouldCheckUsage($request): bool
    {
        $files = (array) ($request->allFiles() ?? []);
        $contentType = strtolower((string) ($request->header('Content-Type') ?? ''));
        $hasFiles = !empty($files);
        $isUpload = $hasFiles
            || (strpos($contentType, 'multipart/form-data') !== false)
            || (strpos($contentType, 'application/octet-stream') !== false);
        if ($isUpload) {
            return true;
        }
        $method = strtoupper((string) $request->method());
        // check isLargeWritePayload
        $contentLength = (int) ($request->header('Content-Length') ?? 0);
        $isLargeWritePayload = in_array($method, ['POST', 'PUT'], true)
            && $contentLength > (2 * 1024 * 1024);

        return $isUpload || $isLargeWritePayload;
    }
}

