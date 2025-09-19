<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class TenantInfoService
{
    public const CACHE_TTL_SECONDS = 3600;

    /**
     * Get current subdomain from request based on exment.tenant.base_domain config.
     */
    public static function getCurrentSubdomain(): ?string
    {
        try {
            $host = \request()->getHost();
            $baseDomain = Config::get('exment.tenant.base_domain');

            if ($baseDomain && substr($host, -strlen('.' . $baseDomain)) === '.' . $baseDomain) {
                return str_replace('.' . $baseDomain, '', $host);
            }
            return null;
        } catch (\Exception $e) {
            Log::error('Failed to get current subdomain', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Build cache key for tenant info by subdomain.
     */
    public static function getInfoCacheKey(?string $subdomain = null): ?string
    {
        if (!$subdomain) {
            $subdomain = self::getCurrentSubdomain();
        }
        if (!$subdomain) {
            return null;
        }
        return "tenant_info_{$subdomain}";
    }

    /**
     * Get tenant info (user_limit, db_size_gb, decrypted token) from cache for the current subdomain.
     * If cache is missing, load from DB and update the cache.
     *
     * @return array
     */
    public static function getCurrentTenantInfo(): array
    {
        try {
            $subdomain = self::getCurrentSubdomain();
            if (!$subdomain) {
                return [
                    'success' => false,
                    'error' => 'SUBDOMAIN_NOT_FOUND',
                    'message' => 'Current subdomain not found',
                    'status' => 404,
                ];
            }

            $cacheKey = self::getInfoCacheKey($subdomain);
            if ($cacheKey === null) {
                return [
                    'success' => false,
                    'error' => 'CACHE_KEY_ERROR',
                    'message' => 'Failed to build cache key',
                    'status' => 500,
                ];
            }

            $cached = Cache::get($cacheKey);
            if ($cached === null) {
                $refresh = self::refreshTenantInfoCache($subdomain);
                if (!($refresh['success'] ?? false)) {
                    return $refresh;
                }
                $cached = $refresh['data'] ?? null;
            }

            return [
                'success' => true,
                'subdomain' => $subdomain,
                'data' => $cached,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get current tenant info', [
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => 'GET_TENANT_INFO_FAILED',
                'message' => 'Failed to get tenant info: ' . $e->getMessage(),
                'status' => 500,
            ];
        }
    }

    /**
     * Refresh tenant info cache from DB for the specified subdomain (or current subdomain).
     *
     * @param string|null $subdomain
     * @return array
     */
    public static function refreshTenantInfoCache(?string $subdomain = null): array
    {
        try {
            if (!$subdomain) {
                $subdomain = self::getCurrentSubdomain();
            }

            if (!$subdomain) {
                return [
                    'success' => false,
                    'error' => 'SUBDOMAIN_NOT_FOUND',
                    'message' => 'Subdomain not provided and current subdomain not found',
                    'status' => 404,
                ];
            }

            /** @var Tenant|null $tenant */
            $tenant = Tenant::where('subdomain', $subdomain)->first();
            if (!$tenant) {
                return [
                    'success' => false,
                    'error' => 'TENANT_NOT_FOUND',
                    'message' => 'Tenant not found for subdomain',
                    'status' => 404,
                ];
            }

            $plan = (array) ($tenant->plan_info ?? []);
            $data = [
                'subdomain' => $subdomain,
                'user_limit' => isset($plan['user_limit']) ? (int) $plan['user_limit'] : 0,
                'db_size_gb' => isset($plan['db_size_gb']) ? (float) $plan['db_size_gb'] : 0.0,
                'token' => (string) ($tenant->token ?? ''),
            ];

            $cacheKey = self::getInfoCacheKey($subdomain);
            if ($cacheKey === null) {
                return [
                    'success' => false,
                    'error' => 'CACHE_KEY_ERROR',
                    'message' => 'Failed to build cache key',
                    'status' => 500,
                ];
            }

            Cache::put($cacheKey, $data, self::CACHE_TTL_SECONDS);

            return [
                'success' => true,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to refresh tenant info cache', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => 'REFRESH_TENANT_INFO_CACHE_FAILED',
                'message' => 'Failed to refresh tenant info cache: ' . $e->getMessage(),
                'status' => 500,
            ];
        }
    }
}


