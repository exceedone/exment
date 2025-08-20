<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Enums\TenantStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Model\Tenant;
use Exceedone\Exment\Services\TenantService;

class TenantProvisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Tenant $tenant;

    /**
     * Create a new job instance.
     *
     * @param Tenant $tenant
     * @return void
     */
    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    /**
     * Execute the job.
     *
     * @param TenantService $tenantService
     * @return void
     */
    public function handle(TenantService $tenantService)
    {
        try {
            Log::info('Starting tenant provision job', [
                'tenant_id' => $this->tenant->id,
                'tenant_suuid' => $this->tenant->tenant_suuid,
                'subdomain' => $this->tenant->subdomain
            ]);

            // Create tenant using existing service logic
            $result = $tenantService->createTenantFromPending($this->tenant);

            if (!$result['success']) {
                Log::error('Tenant provision failed', [
                    'tenant_id' => $this->tenant->id,
                    'error' => $result['error'],
                    'message' => $result['message']
                ]);

                // Update status to failed
                $this->tenant->update(['status' => TenantStatus::ACTIVATION_FAILED]);
                // Callback on failure
                $tenantService->sendTenantStatusCallback($this->tenant, 'create', false, $result['message'] ?? '');
                return;
            }

            Log::info('Tenant provision completed successfully', [
                'tenant_id' => $this->tenant->id,
                'tenant_suuid' => $this->tenant->tenant_suuid,
                'subdomain' => $this->tenant->subdomain
            ]);

            // Callback on success
            $tenantService->sendTenantStatusCallback($this->tenant, 'create', true, null);

        } catch (\Exception $e) {
            Log::error('Tenant provision job failed with exception', [
                'tenant_id' => $this->tenant->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update status to failed
            $this->tenant->update(['status' => TenantStatus::ACTIVATION_FAILED]);
            // Callback on exception
            $tenantService->sendTenantStatusCallback($this->tenant, 'create', false, $e->getMessage());
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('Tenant provision job failed', [
            'tenant_id' => $this->tenant->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Update status to failed
        $this->tenant->update(['status' => TenantStatus::ACTIVATION_FAILED]);
    }
}

