<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\TenantStatus;
use Exceedone\Exment\Enums\TenantType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Exceedone\Exment\Model\Tenant;
use Exceedone\Exment\Services\TenantProvisionValidator;
use Exceedone\Exment\Services\Aws\Route53Service;
use Exceedone\Exment\Services\Aws\IamService;

class TenantService
{
    protected Route53Service $route53Service;
    protected IamService $iamService;
    protected TenantProvisionValidator $validator;

    public function __construct()
    {
        $this->route53Service = new Route53Service();
        $this->iamService = new IamService();
        $this->validator = new TenantProvisionValidator();
    }

    /**
     * Send status callback to external endpoint
     *
     * @param Tenant $tenant
     * @param string $action  'create' | 'update'
     * @param bool $success
     * @param string|null $message
     * @return void
     */
    public function sendTenantStatusCallback(Tenant $tenant, string $action, bool $success, ?string $message = null): void
    {
        try {
            $config = Config::get('exment.tenant.status_callback', []);
            $urlTemplate = (string) ($config['url'] ?? '');
            if ($urlTemplate === '') {
                Log::warning('Tenant status callback URL not configured; skipping callback', [
                    'tenant_suuid' => $tenant->tenant_suuid,
                ]);
                return;
            }

            $url = str_replace(['{tenant_suuid}', '{tenantId}'], [$tenant->tenant_suuid, $tenant->tenant_suuid], $urlTemplate);

            $timeout = (int) ($config['timeout'] ?? 10);
            $token = (string) (env('EXMENT_TENANT_API_TOKEN') ?? '');

            if ($token === '') {
                Log::warning('Tenant status callback token empty; skipping callback', [
                    'tenant_suuid' => $tenant->tenant_suuid,
                ]);
                return;
            }

            $payload = [
                'action' => $action,
                'success' => $success,
            ];
            if ($message !== null && $message !== '') {
                $payload['message'] = $message;
            }

            $response = Http::timeout($timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ])
                ->put($url, $payload);

            if (!$response->ok()) {
                Log::warning('Tenant status callback responded non-2xx', [
                    'tenant_suuid' => $tenant->tenant_suuid,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            } else {
                Log::info('Tenant status callback sent', [
                    'tenant_suuid' => $tenant->tenant_suuid,
                    'action' => $action,
                    'success' => $success,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Tenant status callback failed', [
                'tenant_suuid' => $tenant->tenant_suuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Main method to create a new tenant with subdomain or tenant_path
     *
     * @param array $input
     * @return array
     */
    public function createTenant(array $input): array
    {
        // Validate input using existing validator
        $validation = $this->validator->validate($input);
        if (!$validation['success']) {
            return $validation;
        }

        $subdomain = $validation['data']['subdomain'];
        $tenantPath = $validation['data']['tenant_path'];
        $type = $validation['data']['type'];
        $planLimits = $validation['data']['plan_info'];

        try {
            // 1) Create tenant record in its own transaction, initial status 'provisioning'
            $tenant = DB::transaction(function () use ($subdomain, $tenantPath, $type, $planLimits) {
                return $this->createTenantRecord($subdomain, $tenantPath, $type, $planLimits);
            });

            // 2) Set status to 'active' for all tenants (no AWS provisioning needed)
            DB::transaction(function () use ($tenant) {
                $tenant->update(['status' => 'active']);
            });

            return [
                'success' => true,
                'data' => [
                    'tenant_id' => $tenant->id,
                    'subdomain' => $subdomain,
                    'tenant_path' => $tenantPath,
                    'type' => $type,
                    'aws_resources' => null, // No AWS resources needed
                    'message' => 'Tenant created successfully'
                ]
            ];

        } catch (\Exception $e) {
            $identifier = $subdomain ?: $tenantPath;
            Log::error('Tenant creation failed', [
                'identifier' => $identifier,
                'type' => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'TENANT_CREATION_FAILED',
                'message' => 'Failed to create tenant: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Provision AWS resources for tenant
     *
     * @param string $subdomain
     * @return array
     */
    protected function provisionAwsResources(string $subdomain): array
    {
        $baseDomain = Config::get('exment.tenant.base_domain');
        if (empty($baseDomain)) {
            return [
                'success' => false,
                'error' => 'MISSING_BASE_DOMAIN',
                'message' => 'Base domain not configured for tenant provisioning',
                'status' => 500
            ];
        }

        $fqdn = $subdomain . '.' . $baseDomain;

        try {
            // Create Route53 record
            $route53Result = $this->route53Service->createSubdomainRecord($fqdn);
            if (!$route53Result['success']) {
                return $route53Result;
            }

            return [
                'success' => true,
                'data' => [
                    'route53' => $route53Result['data'],
                    'fqdn' => $fqdn
                ]
            ];

        } catch (\Exception $e) {
            Log::error('AWS resource provisioning failed', [
                'subdomain' => $subdomain,
                'fqdn' => $fqdn,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'AWS_PROVISIONING_FAILED',
                'message' => 'Failed to provision AWS resources: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Create tenant record in database
     *
     * @param string|null $subdomain
     * @param string|null $tenantPath
     * @param string $type
     * @param array $planLimits
     * @return Tenant
     */
    protected function createTenantRecord(?string $subdomain, ?string $tenantPath, string $type, array $planLimits): Tenant
    {
        // Create tenant using the Tenant model
        $tenant = Tenant::create([
            'subdomain' => $subdomain,
            'tenant_path' => $tenantPath,
            'type' => $type,
            'plan_info' => $planLimits,
            'status' => 'provisioning',
        ]);

        // Cache tenant info for rollback purposes
        Cache::put("tenant_provisioning_{$tenant->id}", $tenant, 300);

        return $tenant;
    }

    /**
     * Update tenant with AWS information
     *
     * @param Tenant $tenant
     * @param array $awsData
     * @return void
     */
    protected function updateTenantWithAwsInfo(Tenant $tenant, array $awsData): void
    {
        // Update tenant record with AWS information (called inside an outer transaction)
        $tenant->update([
            'aws_resources' => $awsData,
            'status' => 'active',
        ]);

        // Remove from provisioning cache
        Cache::forget("tenant_provisioning_{$tenant->id}");
    }

    /**
     * Rollback tenant creation on failure
     *
     * @param Tenant $tenant
     * @return void
     */
    protected function rollbackTenantCreation(Tenant $tenant): void
    {
        // Delete tenant and clear cache (should be wrapped by a transaction at the call site)
        $tenant->delete();
        Cache::forget("tenant_provisioning_{$tenant->id}");
    }

    /**
     * Delete tenant and cleanup resources
     *
     * @param string $tenantSuuid
     * @return array
     */
    public function deleteTenant(string $tenantSuuid): array
    {
        try {
            // Get tenant from database
            $tenant = $this->getTenantById($tenantSuuid);
            if (!$tenant) {
                return [
                    'success' => false,
                    'error' => 'TENANT_NOT_FOUND',
                    'message' => 'Tenant not found',
                    'status' => 404
                ];
            }

            $tenant->delete();

            return [
                'success' => true,
                'message' => 'Tenant deleted successfully',
                'data' => [
                    'tenant_suuid' => $tenantSuuid
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Tenant deletion failed', [
                'tenant_suuid' => $tenantSuuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'TENANT_DELETION_FAILED',
                'message' => 'Failed to delete tenant: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Get tenant by ID
     *
     * @param string $tenantId
     * @return Tenant|null
     */
    public static function getTenantById(string $tenantId): ?Tenant
    {
        // First try to get from database
        return Tenant::where('tenant_suuid', $tenantId)->first();
    }

    /**
     * List all tenants
     *
     * @return array
     */
    public function listTenants(): array
    {
        try {
            // Get all tenants from database
            $tenants = Tenant::all();

            return [
                'success' => true,
                'data' => $tenants
            ];

        } catch (\Exception $e) {
            Log::error('Failed to list tenants', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'LIST_TENANTS_FAILED',
                'message' => 'Failed to list tenants: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Get tenant status
     *
     * @param string $tenantId
     * @return array
     */
    public function getTenantStatus(string $tenantId): array
    {
        try {
            $tenant = $this->getTenantById($tenantId);
            if (!$tenant) {
                return [
                    'success' => false,
                    'error' => 'TENANT_NOT_FOUND',
                    'message' => 'Tenant not found',
                    'status' => 404
                ];
            }

            // Check AWS resource status
            $awsStatus = $this->checkAwsResourceStatus($tenant);

            return [
                'success' => true,
                'data' => [
                    'tenant' => $tenant,
                    'aws_status' => $awsStatus
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get tenant status', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'GET_STATUS_FAILED',
                'message' => 'Failed to get tenant status: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Check AWS resource status for tenant
     *
     * @param Tenant $tenant
     * @return array
     */
    protected function checkAwsResourceStatus(Tenant $tenant): array
    {
        // Only check AWS resources for subdomain tenants
        if (!$tenant->usesSubdomain() || !$tenant->subdomain) {
            return [
                'route53' => ['status' => 'not_applicable'],
                'iam' => ['status' => 'not_applicable']
            ];
        }

        $baseDomain = Config::get('exment.tenant.base_domain');
        $fqdn = $tenant->subdomain . '.' . $baseDomain;

        $route53Status = $this->route53Service->checkRecordStatus($fqdn);
        $iamStatus = $this->iamService->checkIamResourceStatus($tenant, $tenant->subdomain);

        return [
            'route53' => $route53Status,
            'iam' => $iamStatus
        ];
    }

    /**
     * Create pending tenant record
     *
     * @param array $input
     * @return array
     */
    public function createPendingTenant(array $input): array
    {
        try {

            // Create tenant record with status 'pending'
            $tenant = Tenant::create([
                'tenant_suuid' => $input['tenant_suuid'],
                'subdomain' => $input['subdomain'],
                'type' => TenantType::SUBDOMAIN,
                'status' => TenantStatus::PENDING,
                'plan_info' => $input['plan_info'],
                'token' => $input['token'],
            ]);

            Log::info('Pending tenant created successfully', [
                'tenant_id' => $tenant->id,
                'tenant_suuid' => $tenant->tenant_suuid,
                'subdomain' => $tenant->subdomain
            ]);

            return [
                'success' => true,
                'data' => [
                    'tenant_suuid' => $tenant->tenant_suuid,
                    'subdomain' => $tenant->subdomain
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Failed to create pending tenant', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'TENANT_CREATION_FAILED',
                'message' => 'Failed to create pending tenant: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Create tenant from pending record
     *
     * @param Tenant $tenant
     * @return array
     */
    public function createTenantFromPending(Tenant $tenant): array
    {
        try {
            Log::info('Creating tenant from pending record', [
                'tenant_id' => $tenant->id,
                'tenant_suuid' => $tenant->tenant_suuid,
                'subdomain' => $tenant->subdomain
            ]);

            DB::beginTransaction();
            try {
                // Provision AWS resources (Route53 record)
                $provisionResult = $this->provisionAwsResources($tenant->subdomain);
                if (!$provisionResult['success']) {
                    throw new \RuntimeException($provisionResult['message'] ?? 'Failed to provision AWS resources');
                }

                // If we have a change id from Route53, optionally wait for INSYNC
                $changeId = $provisionResult['data']['route53']['change_id'] ?? null;
                if (!empty($changeId)) {
                    $waitResult = $this->route53Service->waitForChange($changeId, 180);
                    if (!$waitResult['success']) {
                        Log::warning('Route53 change not INSYNC within wait window; continuing to verify by record lookup', [
                            'tenant_id' => $tenant->id,
                            'change_id' => $changeId,
                            'wait_error' => $waitResult['error'] ?? null,
                            'wait_message' => $waitResult['message'] ?? null,
                        ]);
                    }
                }

                // Verify the record exists; if not, rollback by throwing
                $baseDomain = Config::get('exment.tenant.base_domain');
                $fqdn = $tenant->subdomain . '.' . $baseDomain;
                $recordStatus = $this->route53Service->checkRecordStatus($fqdn);
                if (!$recordStatus['success'] || empty($recordStatus['data']['exists'])) {
                    throw new \RuntimeException('Route53 record not found after provisioning: ' . $fqdn);
                }

                // Mark tenant active after successful verification
                $tenant->update(['status' => TenantStatus::ACTIVE]);

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            Log::info('Tenant created successfully from pending record', [
                'tenant_id' => $tenant->id,
                'tenant_suuid' => $tenant->tenant_suuid,
                'subdomain' => $tenant->subdomain
            ]);

            return [
                'success' => true,
                'data' => [
                    'tenant_suuid' => $tenant->tenant_suuid,
                    'subdomain' => $tenant->subdomain,
                    'message' => 'Tenant created successfully from pending record'
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Failed to create tenant from pending record', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'TENANT_CREATION_FAILED',
                'message' => 'Failed to create tenant from pending record: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Update tenant information
     *
     * @param string $tenantSuuid
     * @param array $input
     * @return array
     */
    public function updateTenant(string $tenantSuuid, array $input): array
    {
        try {
            // Find tenant
            $tenant = $this->getTenantById($tenantSuuid);
            if (!$tenant) {
                return [
                    'success' => false,
                    'error' => 'TENANT_NOT_FOUND',
                    'message' => 'Tenant not found',
                    'status' => 404
                ];
            }

            // Store old values for logging
            $oldPlanInfo = $tenant->plan_info;
            $oldSubdomain = $tenant->subdomain;

            // Check if this is a subdomain change request
            $isSubdomainChange = isset($input['new_subdomain']) && !empty($input['new_subdomain']);
            
            if ($isSubdomainChange) {
                return $this->updateTenantWithSubdomainChange($tenant, $input,  $oldPlanInfo, $oldSubdomain);
            } else {
                return $this->updateTenantRegular($tenant, $input, $oldPlanInfo);
            }

        } catch (\Exception $e) {
            Log::error('Failed to update tenant', [
                'tenant_suuid' => $tenantSuuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'TENANT_UPDATE_FAILED',
                'message' => 'Failed to update tenant: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Update tenant with subdomain change
     *
     * @param Tenant $tenant
     * @param array $input
     * @param array $oldPlanInfo
     * @param string $oldSubdomain
     * @return array
     */
    private function updateTenantWithSubdomainChange($tenant, array $input, array $oldPlanInfo, string $oldSubdomain): array
    {
        $newSubdomain = strtolower(trim($input['new_subdomain']));

        // Update tenant with new data and set status to indicate subdomain change
        $tenant->update([
            'subdomain' => $newSubdomain,
            'plan_info' => $input['plan_info']
        ]);

        // Log the changes
        Log::info('Tenant updated with subdomain change request', [
            'tenant_id' => $tenant->id,
            'tenant_suuid' => $tenant->tenant_suuid,
            'changes' => [
                'plan_info' => [
                    'old' => $oldPlanInfo,
                    'new' => $input['plan_info']
                ],
                'subdomain' => [
                    'old' => $oldSubdomain,
                    'new' => $newSubdomain
                ]
            ],
        ]);

        return [
            'success' => true,
            'message' => 'Tenant updated successfully. Subdomain change request submitted.',
            'data' => [
                'tenant_suuid' => $tenant->tenant_suuid,
                'current_subdomain' => $oldSubdomain,
                'new_subdomain' => $newSubdomain,
                'note' => 'Subdomain change will be processed asynchronously'
            ]
        ];
    }

    /**
     * Update tenant without subdomain change
     *
     * @param Tenant $tenant
     * @param array $input
     * @param array $oldPlanInfo
     * @return array
     */
    private function updateTenantRegular($tenant, array $input, array $oldPlanInfo): array
    {
        // Update tenant with new data
        $tenant->update([
            'plan_info' => $input['plan_info']
        ]);

        // Log the changes
        Log::info('Tenant updated successfully', [
            'tenant_id' => $tenant->id,
            'tenant_suuid' => $tenant->tenant_suuid,
            'changes' => [
                'plan_info' => [
                    'old' => $oldPlanInfo,
                    'new' => $input['plan_info']
                ]
            ]
        ]);

        return [
            'success' => true,
            'message' => 'Tenant updated successfully',
            'data' => [
                'tenant_suuid' => $tenant->tenant_suuid,
                'subdomain' => $tenant->subdomain
            ]
        ];
    }

    /**
     * Check if subdomain exists in cache or database
     *
     * @param string $subdomain
     * @return array
     */
    public function checkSubdomainExists(string $subdomain): array
    {
        try {
            // Normalize subdomain
            $normalizedSubdomain = strtolower(trim($subdomain));
            
            // Check if subdomains are cached
            $cachedSubdomains = Cache::get('tenant_subdomains');
            
            if ($cachedSubdomains === null) {
                // Cache not found, get subdomains from database and cache them
                $cachedSubdomains = $this->cacheTenantSubdomains();
            }
            
            // Check if subdomain exists in cached list
            $exists = in_array($normalizedSubdomain, $cachedSubdomains);
            
            return [
                'success' => true,
                'data' => [
                    'subdomain' => $normalizedSubdomain,
                    'exists' => $exists,
                    'cached' => true
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to check subdomain existence', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => 'SUBDOMAIN_CHECK_FAILED',
                'message' => 'Failed to check subdomain existence: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Cache tenant subdomains from database
     *
     * @return array
     */
    protected function cacheTenantSubdomains(): array
    {
        try {
            // Get all active subdomains from tenant table
            $subdomains = Tenant::whereNotNull('subdomain')
                ->pluck('subdomain')
                ->map(function ($subdomain) {
                    return strtolower(trim($subdomain));
                })
                ->filter()
                ->unique()
                ->values()
                ->toArray();
            
            // Cache subdomains for 1 hour (3600 seconds)
            // Cache::put('tenant_subdomains', $subdomains, 3600);
            
            Log::info('Tenant subdomains cached successfully', [
                'count' => count($subdomains),
                'subdomains' => $subdomains
            ]);
            
            return $subdomains;
            
        } catch (\Exception $e) {
            Log::error('Failed to cache tenant subdomains', [
                'error' => $e->getMessage()
            ]);
            
            // Return empty array if caching fails
            return [];
        }
    }

    /**
     * Clear subdomain cache (useful for testing or manual cache refresh)
     *
     * @return array
     */
    public function clearSubdomainCache(): array
    {
        try {
            Cache::forget('tenant_subdomains');
            
            Log::info('Tenant subdomain cache cleared successfully');
            
            return [
                'success' => true,
                'message' => 'Subdomain cache cleared successfully'
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to clear subdomain cache', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => 'CACHE_CLEAR_FAILED',
                'message' => 'Failed to clear subdomain cache: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Refresh subdomain cache from database
     *
     * @return array
     */
    public function refreshSubdomainCache(): array
    {
        try {
            // Clear existing cache
            Cache::forget('tenant_subdomains');
            
            // Cache fresh data
            $subdomains = $this->cacheTenantSubdomains();
            
            return [
                'success' => true,
                'message' => 'Subdomain cache refreshed successfully',
                'data' => [
                    'count' => count($subdomains),
                    'subdomains' => $subdomains
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to refresh subdomain cache', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => 'CACHE_REFRESH_FAILED',
                'message' => 'Failed to refresh subdomain cache: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }
}


