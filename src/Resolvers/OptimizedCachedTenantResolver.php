<?php

namespace Exceedone\Exment\Resolvers;

use Exceedone\Exment\Model\Tenant;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Cache\RedisStore;

class OptimizedCachedTenantResolver
{
    protected static $validSubdomainsCacheKey = 'tenants:valid_subdomains';
    protected static $ttl = 86400;

    public static function resolve(string $subdomain)
    {
        $cacheStore = Cache::store(env('CACHE_DRIVER', 'file'));

        if (!static::isValidSubdomain($subdomain, $cacheStore)) {
            return null;
        }

        $tenantCacheKey = "tenant:{$subdomain}";
        
        
        $tenantData = $cacheStore->remember($tenantCacheKey, static::$ttl, function () use ($subdomain) {
            $tenant = Tenant::where('subdomain', $subdomain)->first();
            return $tenant ? $tenant->toArray() : null;
        });

        if (!$tenantData) {
            return null;
        }
        // Optimize in next release
        $tenant = new Tenant($tenantData);
        $tenant->id = $tenantData['id'];
        $tenant->exists = true;
        return $tenant;
    }

    protected static function isValidSubdomain(string $subdomain, $cacheStore): bool
    {
        if (!$cacheStore->has(static::$validSubdomainsCacheKey)) {
            static::preloadValidSubdomains($cacheStore);
        }

        if ($cacheStore->getStore() instanceof RedisStore) {
            return $cacheStore->connection()->sismember(static::$validSubdomainsCacheKey, $subdomain);
        }

        $validSubdomains = $cacheStore->get(static::$validSubdomainsCacheKey, []);
        return in_array($subdomain, $validSubdomains);
    }

    protected static function preloadValidSubdomains($cacheStore): void
    {
        $subdomains = Tenant::pluck('subdomain')->toArray();

        if (empty($subdomains)) {
            Log::warning('No valid subdomains found in DB');
            return;
        }

        if ($cacheStore->getStore() instanceof RedisStore) {
            $redis = $cacheStore->connection();
            foreach ($subdomains as $sd) {
                $redis->sadd(static::$validSubdomainsCacheKey, $sd);
            }
            $redis->expire(static::$validSubdomainsCacheKey, static::$ttl);
        } else {
            $cacheStore->put(static::$validSubdomainsCacheKey, $subdomains, static::$ttl);
        }
    }

    public static function addSubdomain(string $subdomain, array $tenantData): void
    {
        $cacheStore = Cache::store(env('CACHE_DRIVER', 'file'));
        if ($cacheStore->getStore() instanceof RedisStore) {
            $cacheStore->connection()->sadd(static::$validSubdomainsCacheKey, $subdomain);
        } else {
            $valid = $cacheStore->get(static::$validSubdomainsCacheKey, []);
            if (!in_array($subdomain, $valid)) {
                $valid[] = $subdomain;
                $cacheStore->put(static::$validSubdomainsCacheKey, $valid, static::$ttl);
            }
        }

        $tenantCacheKey = "tenant:{$subdomain}";
        $cacheStore->put($tenantCacheKey, $tenantData, static::$ttl);
    }

    public static function updateSubdomain(string $oldSubdomain, string $newSubdomain, array $tenantData): void
    {
        static::removeSubdomain($oldSubdomain);
        static::addSubdomain($newSubdomain, $tenantData);
    }

    public static function removeSubdomain(string $subdomain): void
    {
        $cacheStore = Cache::store(env('CACHE_DRIVER', 'file'));
        if ($cacheStore->getStore() instanceof RedisStore) {
            $cacheStore->connection()->srem(static::$validSubdomainsCacheKey, $subdomain);
        } else {
            $valid = $cacheStore->get(static::$validSubdomainsCacheKey, []);
            $valid = array_diff($valid, [$subdomain]);
            $cacheStore->put(static::$validSubdomainsCacheKey, $valid, static::$ttl);
        }

        $tenantCacheKey = "tenant:{$subdomain}";
        $cacheStore->forget($tenantCacheKey);
    }
}
