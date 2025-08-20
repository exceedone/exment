<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Services\Validators\TenantValidatorBase;

class TenantUpdateValidator extends TenantValidatorBase
{
    /**
     * Validate tenant update request
     * Uses the same validation logic as TenantProvisionValidator but skips tenant_suuid and user fields
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

        // Use common validation logic but skip tenant_suuid and user fields
        $commonValidation = $this->validateCommon($input, true, true); // skipUserFields=true, skipTenantSuuid=true
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
                'tenant' => $tenantCheck['data']['tenant'],
                'validated_data' => $input,
                'validation_data' => $data
            ]
        ];
    }
}
