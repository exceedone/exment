<?php

namespace Exceedone\Exment\Observers;

use Exceedone\Exment\Model\Tenant;
use Exceedone\Exment\Resolvers\OptimizedCachedTenantResolver;
use Illuminate\Support\Facades\Cache;

class TenantObserver
{
    public function created(Tenant $tenant)
    {
        OptimizedCachedTenantResolver::addSubdomain($tenant->subdomain, $tenant->toArray());
    }

    public function updated(Tenant $tenant)
    {
        if ($tenant->isDirty('subdomain')) {
            OptimizedCachedTenantResolver::updateSubdomain(
                $tenant->getOriginal('subdomain'),
                $tenant->subdomain,
                $tenant->toArray()
            );
        } else {
            $tenantCacheKey = "tenant:{$tenant->subdomain}";
            Cache::store(env('CACHE_DRIVER', 'file'))->put($tenantCacheKey, $tenant->toArray(), 86400);
        }
    }

    public function deleted(Tenant $tenant)
    {
        OptimizedCachedTenantResolver::removeSubdomain($tenant->subdomain);
    }
}
