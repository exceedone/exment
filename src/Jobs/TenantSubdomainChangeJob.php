<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Enums\TenantStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Exceedone\Exment\Model\Tenant;
use Exceedone\Exment\Services\Aws\Route53Service;
use Exceedone\Exment\Services\TenantService;

class TenantSubdomainChangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Tenant $tenant;

    /**
     * Create a new job instance.
     */
    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    /**
     * Execute the job.
     */
    public function handle(Route53Service $route53Service, TenantService $tenantService): void
    {
        $tenant = $this->fetchTenant();
        if (!$tenant || $tenant->status !== TenantStatus::SUBDOMAIN_CHANGE_PENDING) {
            return;
        }

        $validated = $this->validateConfigAndBuildDomains($tenant);
        if ($validated === null) {
            return;
        }

        [$baseDomain, $oldFqdn, $newFqdn] = $validated;

        $this->logStart($tenant, $oldFqdn, $newFqdn);

        try {
            if (!$this->precheckOldRecord($route53Service, $tenant, $oldFqdn, $tenantService)) {
                return;
            }

            $create = $this->createNewRecordOrAbort($route53Service, $tenant, $newFqdn, $tenantService);
            if ($create === null) {
                return;
            }

            if (!$this->waitForChangeIfNeeded($route53Service, $tenant, $create, $tenantService)) {
                return;
            }

            if (!$this->verifyDnsIfEnabled($tenant, $newFqdn, $tenantService)) {
                return;
            }

            $this->deleteOldRecordSafe($route53Service, $oldFqdn);

            $this->finalizeSuccess($tenant, $tenantService);
        } catch (\Throwable $e) {
            $this->handleFailure($tenant, $tenantService, $e);
        }
    }

    private function isDnsPointing(string $fqdn, string $expected): bool
    {
        if ($expected === '' || !function_exists('dns_get_record')) {
            return true; // Skip verification when not configured or not available
        }

        $records = @dns_get_record($fqdn, DNS_A | DNS_CNAME) ?: [];
        foreach ($records as $rec) {
            $value = $rec['ip'] ?? ($rec['target'] ?? '');
            if (is_string($value)) {
                $left = rtrim(strtolower($value), '.');
                $right = rtrim(strtolower($expected), '.');
                if ($left === $right) {
                    return true;
                }
            }
        }
        return false;
    }

    private function fetchTenant(): ?Tenant
    {
        return Tenant::find($this->tenant->id);
    }

    private function validateConfigAndBuildDomains(Tenant $tenant): ?array
    {
        $baseDomain = (string) Config::get('exment.tenant.base_domain', '');
        if ($baseDomain === '' || empty($tenant->new_subdomain)) {
            Log::error('Subdomain change job missing configuration or new_subdomain', [
                'tenant_id' => $tenant->id,
            ]);
            return null;
        }

        $oldFqdn = $tenant->subdomain . '.' . $baseDomain;
        $newFqdn = $tenant->new_subdomain . '.' . $baseDomain;

        return [$baseDomain, $oldFqdn, $newFqdn];
    }

    private function logStart(Tenant $tenant, string $oldFqdn, string $newFqdn): void
    {
        Log::info('Starting subdomain change', [
            'tenant_id' => $tenant->id,
            'from' => $oldFqdn,
            'to' => $newFqdn,
        ]);
    }

    private function precheckOldRecord(Route53Service $route53Service, Tenant $tenant, string $oldFqdn, TenantService $tenantService): bool
    {
        $oldStatus = $route53Service->checkRecordStatus($oldFqdn);
        if (!$oldStatus['success'] || empty($oldStatus['data']['exists'])) {
            Log::error('Old DNS record not found on Route53 for subdomain change', [
                'tenant_id' => $tenant->id,
                'old_fqdn' => $oldFqdn,
                'status' => $oldStatus['data']['status'] ?? null,
                'message' => $oldStatus['message'] ?? null,
            ]);
            $tenantService->sendTenantStatusCallback($tenant, 'update', false, 'Old DNS record not found on Route53: ' . $oldFqdn);
            return false;
        }
        return true;
    }

    private function createNewRecordOrAbort(Route53Service $route53Service, Tenant $tenant, string $newFqdn, TenantService $tenantService): ?array
    {
        $create = $route53Service->createSubdomainRecord($newFqdn);
        if (!$create['success']) {
            Log::error('Failed to create new DNS record for subdomain change', [
                'tenant_id' => $tenant->id,
                'error' => $create['error'] ?? null,
                'message' => $create['message'] ?? null,
            ]);
            $tenantService->sendTenantStatusCallback($tenant, 'update', false, $create['message'] ?? 'Failed to create DNS record');
            return null;
        }
        return $create;
    }

    private function waitForChangeIfNeeded(Route53Service $route53Service, Tenant $tenant, array $createResult, TenantService $tenantService): bool
    {
        $changeId = (string) (($createResult['data']['change_id'] ?? ''));
        if ($changeId === '') {
            return true;
        }

        $waitSeconds = (int) Config::get('exment.tenant.subdomain_change_wait_seconds', 180);
        $waitResult = $route53Service->waitForChange($changeId, $waitSeconds);
        if (!$waitResult['success']) {
            Log::warning('Route53 change not INSYNC within wait window', [
                'tenant_id' => $tenant->id,
                'change_id' => $changeId,
                'message' => $waitResult['message'] ?? null,
            ]);
            $tenantService->sendTenantStatusCallback($tenant, 'update', false, $waitResult['message'] ?? 'DNS not INSYNC');
            return false;
        }
        return true;
    }

    private function verifyDnsIfEnabled(Tenant $tenant, string $newFqdn, TenantService $tenantService): bool
    {
        $enableDnsVerify = (bool) Config::get('exment.tenant.subdomain_change_enable_dns_verify', false);
        if (!$enableDnsVerify) {
            return true;
        }

        $expected = (string) Config::get('exment.tenant.route53.target_ip') ?: (string) Config::get('exment.tenant.route53.target_alias');
        $maxWaitSeconds = (int) Config::get('exment.tenant.subdomain_change_verify_wait_seconds', 120);
        $pollInterval = max(3, (int) Config::get('exment.tenant.subdomain_change_poll_interval', 5));

        $start = time();
        $verified = false;
        while ((time() - $start) < $maxWaitSeconds) {
            if ($this->isDnsPointing($newFqdn, $expected)) {
                $verified = true;
                break;
            }
            sleep($pollInterval);
        }

        if (!$verified) {
            Log::warning('DNS not propagated for new subdomain within wait window', [
                'tenant_id' => $tenant->id,
                'fqdn' => $newFqdn,
            ]);
            $tenantService->sendTenantStatusCallback($tenant, 'update', false, 'DNS not propagated within wait window');
            return false;
        }

        return true;
    }

    private function deleteOldRecordSafe(Route53Service $route53Service, string $oldFqdn): void
    {
        try {
            $route53Service->deleteSubdomainRecord($oldFqdn);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete old DNS record (continuing)', [
                'old_fqdn' => $oldFqdn,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function finalizeSuccess(Tenant $tenant, TenantService $tenantService): void
    {
        $tenant->update([
            'subdomain' => $tenant->new_subdomain,
            'new_subdomain' => null,
            'status' => TenantStatus::ACTIVE,
        ]);

        Log::info('Subdomain change completed', [
            'tenant_id' => $tenant->id,
            'subdomain' => $tenant->subdomain,
        ]);

        $tenantService->sendTenantStatusCallback($tenant, 'update', true, null);
    }

    private function handleFailure(Tenant $tenant, TenantService $tenantService, \Throwable $e): void
    {
        Log::error('Subdomain change job failed', [
            'tenant_id' => $tenant->id,
            'error' => $e->getMessage(),
        ]);
        $tenantService->sendTenantStatusCallback($tenant, 'update', false, $e->getMessage());
    }
}


