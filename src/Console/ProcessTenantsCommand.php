<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Enums\TenantStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Exceedone\Exment\Model\Tenant;
use Exceedone\Exment\Jobs\TenantProvisionJob;
use Exceedone\Exment\Jobs\TenantSubdomainChangeJob;

class ProcessTenantsCommand extends Command
{
    use CommandTrait;
    
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:process-tenants {--limit=10 : Maximum number of tenants to process per status in one run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process tenants and dispatch provision jobs';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $limit = (int) $this->option('limit');
        
        $this->info("Processing tenants (limit: {$limit})...");

        try {
            // Get tenants by status
            $pendingTenants = Tenant::where('status', TenantStatus::PENDING)
                ->orderBy('created_at', 'asc')
                ->limit($limit)
                ->get();

            $subdomainChangeTenants = Tenant::where('status', TenantStatus::SUBDOMAIN_CHANGE_PENDING)
                ->orderBy('updated_at', 'asc')
                ->limit($limit)
                ->get();

            if ($pendingTenants->isEmpty() && $subdomainChangeTenants->isEmpty()) {
                $this->info('No tenants found to process.');
                return 0;
            }

            $this->info("Found {$pendingTenants->count()} pending tenants and {$subdomainChangeTenants->count()} subdomain-change tenants.");

            $processed = 0;
            $failed = 0;

            // Dispatch for PENDING tenants
            foreach ($pendingTenants as $tenant) {
                try {
                    $this->info("Provisioning tenant: {$tenant->subdomain} (ID: {$tenant->id})");
                    TenantProvisionJob::dispatch($tenant);
                    $processed++;
                    $this->info("✓ Provision job dispatched: {$tenant->subdomain}");
                } catch (\Exception $e) {
                    $failed++;
                    $this->error("✗ Failed to dispatch provision job for {$tenant->subdomain}: {$e->getMessage()}");
                    Log::error('Failed to dispatch tenant provision job', [
                        'tenant_id' => $tenant->id,
                        'tenant_suuid' => $tenant->tenant_suuid,
                        'subdomain' => $tenant->subdomain,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Dispatch for SUBDOMAIN_CHANGE_PENDING tenants
            foreach ($subdomainChangeTenants as $tenant) {
                try {
                    $this->info("Updating subdomain for tenant: {$tenant->subdomain} -> {$tenant->new_subdomain} (ID: {$tenant->id})");
                    TenantSubdomainChangeJob::dispatch($tenant);
                    $processed++;
                    $this->info("✓ Subdomain-change job dispatched: {$tenant->subdomain} -> {$tenant->new_subdomain}");
                } catch (\Exception $e) {
                    $failed++;
                    $this->error("✗ Failed to dispatch subdomain-change job for {$tenant->subdomain}: {$e->getMessage()}");
                    Log::error('Failed to dispatch tenant subdomain change job', [
                        'tenant_id' => $tenant->id,
                        'tenant_suuid' => $tenant->tenant_suuid,
                        'subdomain' => $tenant->subdomain,
                        'new_subdomain' => $tenant->new_subdomain,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->info("\nDispatching completed:");
            $this->info("- Dispatched: {$processed}");
            $this->info("- Failed: {$failed}");
            $this->info("- Total: " . ($processed + $failed));

            return $failed > 0 ? 1 : 0;

        } catch (\Exception $e) {
            $this->error("Command failed: {$e->getMessage()}");
            Log::error('ProcessTenantsCommand failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}

