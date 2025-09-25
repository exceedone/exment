<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class TenantInfoService
{
    protected static array $runtimeCache = [];
    protected const SUBDOMAIN_MAP_THRESHOLD = 1000;

    public static function getTenantBySubdomain($subdomain = null): ?array
    {
        if(!$subdomain) {
            $subdomain = self::getCurrentSubdomain();
            if (!$subdomain) {
                return null;
            }
        }
        // Check runtime cache
        if (isset(self::$runtimeCache["tenant:{$subdomain}"])) {
            return self::$runtimeCache["tenant:{$subdomain}"];
        }

        // Lấy map subdomain -> tenantId từ cache
        $map = self::getSubdomainMap();
        $tenantId = $map[$subdomain] ?? null;

        if (!$tenantId) {
            self::$runtimeCache["tenant:{$subdomain}"] = null;
            return null;
        }

        // Lấy tenant info từ cache (theo tenantId)
        $tenant = self::getTenantInfo($tenantId);
        // Cache trong request runtime
        self::$runtimeCache["tenant:{$subdomain}"] = $tenant;
        return $tenant;
    }

    protected static function getSubdomainMap(): array
    {
        $map = Cache::rememberForever('tenants:subdomain_map', function () {
            return Tenant::query()
                ->pluck('id', 'subdomain')
                ->toArray();
        });

        // If map exceeds threshold, use per-tenant caching
        if (count($map) > self::SUBDOMAIN_MAP_THRESHOLD) {
            foreach ($map as $subdomain => $tenantId) {
                Cache::forever("tenant:subdomain:{$subdomain}", $tenantId);
            }
            Cache::forget('tenants:subdomain_map');
            return $map;
        }

        return $map;
    }

    protected static function getTenantIdBySubdomain(string $subdomain): ?int
    {
        return Cache::rememberForever("tenant:subdomain:{$subdomain}", function () use ($subdomain) {
            $tenant = Tenant::where('subdomain', $subdomain)->first();
            return $tenant ? $tenant->id : null;
        });
    }

    protected static function getTenantInfo(int $tenantId): ?array
    {
        return Cache::rememberForever("tenant:info:{$tenantId}", function () use ($tenantId) {
            $tenant = Tenant::find($tenantId);
            return $tenant ? $tenant->toArray() : null;
        });
    }

    public static function addCacheTenant($tenant): array
    {
        $tenantArray = $tenant->toArray();

        // Update caches
        if (count(self::getSubdomainMap()) > self::SUBDOMAIN_MAP_THRESHOLD) {
            Cache::forever("tenant:subdomain:{$tenant->subdomain}", $tenant->id);
            Cache::forever("tenant:info:{$tenant->id}", $tenantArray);
        } else {
            $map = self::getSubdomainMap();
            $map[$tenant->subdomain] = $tenant->id;
            Cache::forever('tenants:subdomain_map', $map);
            Cache::forever("tenant:info:{$tenant->id}", $tenantArray);
        }

        self::$runtimeCache["tenant:{$tenant->subdomain}"] = $tenantArray;
        return $tenantArray;
    }

    public static function updateCacheTenant($tenant, $oldSubdomain = null): ?array
    {
        $tenantArray = $tenant->toArray();
        $newSubdomain = $tenant->subdomain;
        $tenantId = $tenant->id;

        // Update caches
        if (count(self::getSubdomainMap()) > self::SUBDOMAIN_MAP_THRESHOLD) {
            // Update per-tenant caches
            if ($oldSubdomain && $oldSubdomain !== $newSubdomain) {
                Cache::forget("tenant:subdomain:{$oldSubdomain}");
                Cache::forever("tenant:subdomain:{$newSubdomain}", $tenantId);
            }
            Cache::forever("tenant:info:{$tenantId}", $tenantArray);
        } else {
            // Update subdomain map
            $map = self::getSubdomainMap();
            if ($oldSubdomain && $oldSubdomain !== $newSubdomain) {
                unset($map[$oldSubdomain]);
                $map[$newSubdomain] = $tenantId;
                Cache::forever('tenants:subdomain_map', $map);
            }
            Cache::forever("tenant:info:{$tenantId}", $tenantArray);
        }

        // Update runtime cache
        unset(self::$runtimeCache["tenant:{$oldSubdomain}"]);
        self::$runtimeCache["tenant:{$newSubdomain}"] = $tenantArray;

        return $tenantArray;
    }

    public static function deleteCacheTenant($tenant): void
    {
        $tenantId = $tenant->id;
        $subdomain = $tenant->subdomain;
        // Clear caches
        if (count(self::getSubdomainMap()) > self::SUBDOMAIN_MAP_THRESHOLD) {
            Cache::forget("tenant:subdomain:{$subdomain}");
            Cache::forget("tenant:info:{$tenantId}");
        } else {
            $map = self::getSubdomainMap();
            unset($map[$subdomain]);
            Cache::forever('tenants:subdomain_map', $map);
            Cache::forget("tenant:info:{$tenantId}");
        }

        unset(self::$runtimeCache["tenant:{$subdomain}"]);
    }

    public static function clearCache(int $tenantId, ?string $subdomain = null): void
    {
        Cache::forget("tenant:info:{$tenantId}");
        if ($subdomain) {
            Cache::forget("tenant:subdomain:{$subdomain}");
            unset(self::$runtimeCache["tenant:{$subdomain}"]);
        }
        // Only clear subdomain map if not using per-tenant cache
        if (count(self::getSubdomainMap()) <= self::SUBDOMAIN_MAP_THRESHOLD) {
            Cache::forget('tenants:subdomain_map');
        }
    }

    public static function getCurrentSubdomain(): ?string
    {
        try {
            $host = request()->getHost();
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
}


