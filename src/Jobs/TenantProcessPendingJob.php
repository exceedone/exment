<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Model\Tenant;
use Exceedone\Exment\Enums\TenantStatus;
use Exceedone\Exment\Services\TenantDatabaseService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Exception;

class TenantProcessPendingJob
{
    use JobTrait;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('TenantProcessPendingJob: Starting to process pending tenants');

        try {
            $pendingTenants = Tenant::where('status', TenantStatus::PENDING)->get();

            if ($pendingTenants->isEmpty()) {
                Log::info('TenantProcessPendingJob: No pending tenants found');
                return;
            }

            Log::info('TenantProcessPendingJob: Found ' . $pendingTenants->count() . ' pending tenants');

            foreach ($pendingTenants as $tenant) {
                $this->processTenant($tenant);
            }

            Log::info('TenantProcessPendingJob: Completed processing all pending tenants');

        } catch (Exception $e) {
            Log::error('TenantProcessPendingJob: Failed to process pending tenants', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Process individual tenant
     *
     * @param Tenant $tenant
     * @return void
     */
    protected function processTenant(Tenant $tenant)
    {
        try {
            Log::info('TenantProcessPendingJob: Processing tenant', [
                'tenant_id' => $tenant->id,
                'tenant_suuid' => $tenant->tenant_suuid,
                'subdomain' => $tenant->subdomain
            ]);

            $tenant->update(['status' => TenantStatus::PROVISIONING]);

            $settings = $tenant->getEnvironmentSettings();
            
            if (empty($settings)) {
                throw new Exception('No environment settings found for tenant');
            }

            $this->setupTenantDatabase($tenant, $settings);

            $this->runExmentInstall($tenant);

            $tenant->update(['status' => TenantStatus::ACTIVE]);

            Log::info('TenantProcessPendingJob: Successfully processed tenant', [
                'tenant_id' => $tenant->id,
                'tenant_suuid' => $tenant->tenant_suuid,
                'subdomain' => $tenant->subdomain
            ]);

        } catch (Exception $e) {
            Log::error('TenantProcessPendingJob: Failed to process tenant', [
                'tenant_id' => $tenant->id,
                'tenant_suuid' => $tenant->tenant_suuid,
                'subdomain' => $tenant->subdomain,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $tenant->update(['status' => TenantStatus::ACTIVATION_FAILED]);
            
            throw $e;
        }
    }

    /**
     * Setup tenant database and user
     *
     * @param Tenant $tenant
     * @param array $settings
     * @return void
     */
    protected function setupTenantDatabase(Tenant $tenant, array $settings)
    {
        Log::info('TenantProcessPendingJob: Setting up database for tenant', [
            'tenant_id' => $tenant->id,
            'db_name' => $settings['db_name'] ?? null,
            'db_username' => $settings['db_username'] ?? null
        ]);


        TenantDatabaseService::createTenantDatabase($tenant);

        TenantDatabaseService::createTenantDatabaseUser($tenant);

        Log::info('TenantProcessPendingJob: Database setup completed for tenant', [
            'tenant_id' => $tenant->id
        ]);
    }

    /**
     * Run exment:install command with settings
     *
     * @param Tenant $tenant
     * @param array $settings
     * @return void
     */
    protected function runExmentInstall(Tenant $tenant)
    {
        try {
            $settings = $tenant->getEnvironmentSettings();
            Log::info('TenantProcessPendingJob: Running exment:install for tenant', [
                'tenant_id' => $tenant->id,
                'db_name' => $settings['db_name'],
                'db_username' => $settings['db_username']
            ]);
            $installSettings = [
                'db_host' => $settings['db_host'] ?? '127.0.0.1',
                'db_port' => $settings['db_port'] ?? '3306',
                'db_name' => $settings['db_name'],
                'db_username' => $settings['db_username'],
                'db_password' => $settings['db_password'],
            ];

            $settingsJson = json_encode($installSettings, JSON_UNESCAPED_UNICODE);

            $exitCode = Artisan::call('exment:install', [
                '--settings' => $settingsJson
            ]);

            if ($exitCode !== 0) {
                throw new Exception('exment:install command failed with exit code: ' . $exitCode);
            }

            Log::info('TenantProcessPendingJob: exment:install completed successfully for tenant', [
                'tenant_id' => $tenant->id,
                'exit_code' => $exitCode
            ]);

        } catch (Exception $e) {
            Log::error('TenantProcessPendingJob: exment:install failed for tenant', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param Exception $exception
     * @return void
     */
    public function failed(Exception $exception)
    {
        Log::error('TenantProcessPendingJob: Job failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}