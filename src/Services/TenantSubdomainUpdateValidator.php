<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Services\Validators\TenantValidatorBase;
use Exceedone\Exment\Model\Tenant;

class TenantSubdomainUpdateValidator extends TenantValidatorBase
{
    /**
     * Validate tenant subdomain update request
     * Handles both regular updates and subdomain changes
     *
     * @param array $input
     * @param string $tenantSuuid
     * @return array
     */
    public function validate(array $input, string $tenantSuuid): array
    {
        // Check if tenant exists
        $tenantCheck = $this->checkTenantExists($tenantSuuid);
        if (!$tenantCheck['success']) {
            return $tenantCheck;
        }

        /** @var Tenant $tenant */
        $tenant = $tenantCheck['data']['tenant'];

        // Validate that suuid matches the tenant's tenant_suuid
        if ($tenant->tenant_suuid !== $tenantSuuid) {
            return [
                'success' => false,
                'error' => 'INVALID_SUUID',
                'message' => 'Tenant SUUID does not match',
                'status' => 400
            ];
        }

        // Check if this is a subdomain change request
        $isSubdomainChange = isset($input['new_subdomain']) && !empty($input['new_subdomain']);
        
        if ($isSubdomainChange) {
            // Validate that the current subdomain in request matches the tenant's current subdomain
            if (!isset($input['subdomain']) || empty($input['subdomain'])) {
                return [
                    'success' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Current subdomain is required when changing subdomain',
                    'status' => 422
                ];
            }

            if (strtolower(trim($input['subdomain'])) !== strtolower($tenant->subdomain)) {
                return [
                    'success' => false,
                    'error' => 'SUBDOMAIN_MISMATCH',
                    'message' => 'Current subdomain in request does not match tenant\'s current subdomain',
                    'status' => 400
                ];
            }

            // Validate subdomain change request
            $subdomainChangeValidation = $this->validateSubdomainChange($input, $tenant);
            if (!$subdomainChangeValidation['success']) {
                return $subdomainChangeValidation;
            }
        }

        // Use common validation logic but skip tenant_suuid and token
        $commonValidation = $this->validateCommon($input, true, true);
        if (!$commonValidation['success']) {
            return $commonValidation;
        }

        $data = $commonValidation['data'];
        $identifier = $data['subdomain'] ?: $data['tenant_path'];
        $type = $data['type'];

        // Apply the same validations as provision (using base class methods)
        $reserved = $this->validateReservedIdentifier($identifier, $type);
        if (!$reserved['success']) return $reserved;
        
        $rate = $this->validateRateLimit($input, 'update');
        if (!$rate['success']) return $rate;

        $duplicate = $this->validateDuplicateIdentifier($identifier, $type, $tenantSuuid); // Exclude current tenant
        if (!$duplicate['success']) return $duplicate;

        return [
            'success' => true,
            'data' => [
                'tenant' => $tenant,
                'validated_data' => $input,
                'validation_data' => $data,
                'is_subdomain_change' => $isSubdomainChange
            ]
        ];
    }

    /**
     * Validate subdomain change request
     *
     * @param array $input
     * @param Tenant $tenant
     * @return array
     */
    private function validateSubdomainChange(array $input, Tenant $tenant): array
    {
        $newSubdomain = strtolower(trim($input['new_subdomain']));

        // Validate new_subdomain format
        if (!is_string($newSubdomain) || empty($newSubdomain)) {
            return [
                'success' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'New subdomain must be a non-empty string',
                'status' => 422
            ];
        }

        // Validate new_subdomain format (same as subdomain validation)
        if (!preg_match('/^[a-z0-9](?:[a-z0-9\-]{1,61}[a-z0-9])?$/', $newSubdomain)) {
            return [
                'success' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'New subdomain format is invalid (1-63 chars, lowercase alphanumerics and hyphens, cannot start/end with hyphen)',
                'status' => 422
            ];
        }

        // Check if new_subdomain is the same as current subdomain
        if ($newSubdomain === strtolower($tenant->subdomain)) {
            return [
                'success' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'New subdomain must be different from current subdomain',
                'status' => 422
            ];
        }

        // Validate new_subdomain is not reserved
        $reserved = $this->validateReservedIdentifier($newSubdomain, 'subdomain');
        if (!$reserved['success']) {
            return [
                'success' => false,
                'error' => 'RESERVED_IDENTIFIER',
                'message' => 'New subdomain is reserved and cannot be used',
                'status' => 400
            ];
        }

        // Validate new_subdomain is not blacklisted
        $blacklist = $this->validateBlacklistWords($newSubdomain);
        if (!$blacklist['success']) {
            return [
                'success' => false,
                'error' => 'BLACKLISTED_SUBDOMAIN',
                'message' => 'New subdomain contains prohibited words',
                'status' => 400
            ];
        }

        // Validate new_subdomain is not duplicate (exclude current tenant)
        $duplicate = $this->validateDuplicateIdentifier($newSubdomain, 'subdomain', $tenant->tenant_suuid);
        if (!$duplicate['success']) {
            return [
                'success' => false,
                'error' => 'DUPLICATE_IDENTIFIER',
                'message' => 'New subdomain already exists',
                'status' => 409
            ];
        }

        // Validate new_subdomain doesn't conflict with alias/custom domains
        $conflict = $this->validateTenantConflict($newSubdomain, 'subdomain');
        if (!$conflict['success']) {
            return [
                'success' => false,
                'error' => 'TENANT_CONFLICT',
                'message' => 'New subdomain conflicts with an existing alias/custom domain',
                'status' => 409
            ];
        }

        return ['success' => true];
    }
}
