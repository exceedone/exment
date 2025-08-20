<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Exceedone\Exment\Services\TenantService;
use Exceedone\Exment\Services\TenantUpdateValidator;
use Exceedone\Exment\Services\TenantSubdomainUpdateValidator;
use Exceedone\Exment\Model\Tenant;

class TenantProvisionController extends BaseController
{
    protected TenantService $tenantService;

    public function __construct()
    {
        $this->tenantService = new TenantService();
    }

    /**
     * API endpoint to receive tenant creation requests
     *
     * @param Request $request
     *
     * Example request:
     * {
     *   "tenant_suuid": "12345678abcd",
     *   "subdomain": "tenant2",
     *   "token": "YqUZtwSRajCC9x",
     *   "plan_info": {
     *     "name": "Free",
     *     "user_limit": 1,
     *     "db_size_gb": 1,
     *     "expired_at": "20251231"
     *   }
     * }
     *
     * @return JsonResponse
     */
    public function provision(Request $request): JsonResponse
    {
        // Use TenantProvisionValidator for validation
        $validator = new \Exceedone\Exment\Services\TenantProvisionValidator();
        $validation = $validator->validate($request->all());
        if (!$validation['success']) {
            return response()->json([
                'success' => false,
                'error' => $validation['error'],
                'message' => $validation['message'] ?? null,
                'messages' => $validation['messages'] ?? null,
                'status' => $validation['status'] ?? 422
            ], $validation['status'] ?? 422);
        }

        // Create pending tenant using service
        $result = $this->tenantService->createPendingTenant($request->all());

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
                'message' => $result['message'] ?? null,
                'status' => $result['status'] ?? 500
            ], $result['status'] ?? 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tenant provision request received successfully',
            'data' => $result['data']
        ], 200);
    }

    /**
     * Delete tenant and cleanup AWS resources
     *
     * @param Request $request
     * @param string $tenantSuuid
     * @return JsonResponse
     */
    public function delete(Request $request, string $tenantSuuid): JsonResponse
    {
        $result = $this->tenantService->deleteTenant($tenantSuuid);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error'   => $result['error'],
                'message' => $result['message'] ?? null,
            ], $result['status'] ?? 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tenant deleted successfully'
        ], 200);
    }

    /**
     * Get tenant status
     *
     * @param Request $request
     * @param string $tenantId
     * @return JsonResponse
     */
    public function status(Request $request, string $tenantId): JsonResponse
    {
        $result = $this->tenantService->getTenantStatus($tenantId);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error'   => $result['error'],
                'message' => $result['message'] ?? null,
            ], $result['status'] ?? 400);
        }

        return response()->json([
            'success' => true,
            'data'    => $result['data']
        ], 200);
    }

    /**
     * List all tenants
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function list(Request $request): JsonResponse
    {
        $result = $this->tenantService->listTenants();

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error'   => $result['error'],
                'message' => $result['message'] ?? null,
            ], $result['status'] ?? 400);
        }

        return response()->json([
            'success' => true,
            'data'    => $result['data']
        ], 200);
    }

    /**
     * Update tenant information
     *
     * @param Request $request
     * @param string $suuid
     * @return JsonResponse
     *
     * Example request (regular update):
     * {
     *   "token": "YqUZtwSRajCC9x",
     *   "plan_info": {
     *     "name": "Free",
     *     "user_limit": 1,
     *     "db_size_gb": 1,
     *     "expired_at": "20251231"
     *   }
     * }
     *
     * Example request (with subdomain change):
     * {
     *   "subdomain": "tenant3",
     *   "new_subdomain": "tenant4",
     *   "token": "YqUZtwSRajCC9x",
     *   "plan_info": {
     *     "name": "Free",
     *     "user_limit": 1,
     *     "db_size_gb": 1,
     *     "expired_at": "20251231"
     *   }
     * }
     */
    public function update(Request $request, string $suuid): JsonResponse
    {
        $isSubdomainChange = !empty($request->input('new_subdomain'));
        
        if ($isSubdomainChange) {
            $validator = new TenantSubdomainUpdateValidator();
        } else {
            $validator = new TenantUpdateValidator();
        }
        
        $validation = $validator->validate($request->all(), $suuid);
        
        if (!$validation['success']) {
            return response()->json([
                'success' => false,
                'error' => $validation['error'],
                'message' => $validation['message'] ?? null,
                'messages' => $validation['messages'] ?? null,
                'status' => $validation['status'] ?? 422
            ], $validation['status'] ?? 422);
        }

        // Update tenant using service
        $result = $this->tenantService->updateTenant($suuid, $request->all());

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
                'message' => $result['message'] ?? null,
                'status' => $result['status'] ?? 500
            ], $result['status'] ?? 500);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'] ?? 'Tenant updated successfully',
            'data' => $result['data']
        ], 200);
    }
}