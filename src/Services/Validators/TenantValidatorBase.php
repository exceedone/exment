<?php

namespace Exceedone\Exment\Services\Validators;

use Illuminate\Support\Facades\Validator;
use Exceedone\Exment\Model\Tenant;
use Exceedone\Exment\Services\TenantService;

abstract class TenantValidatorBase
{
    /**
     * Validate token field
     *
     * @param array $input
     * @return array
     */
    protected function validateToken(array $input): array
    {
        $validator = Validator::make($input, [
            'token' => 'required|string|max:255',
        ], [
            'token.required' => 'Token is required',
            'token.string' => 'Token must be a string',
            'token.max' => 'Token must not exceed 255 characters',
        ]);

        if ($validator->fails()) {
            return [
                'success' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Token validation failed',
                'messages' => $validator->errors()->toArray(),
                'status' => 422
            ];
        }

        return ['success' => true];
    }

    /**
     * Validate plan_info field
     *
     * @param array $input
     * @return array
     */
    protected function validatePlanInfo(array $input): array
    {
        $validator = Validator::make($input, [
            'plan_info' => 'required|array',
            'plan_info.name' => 'required|string|max:100',
            'plan_info.user_limit' => 'required|integer|min:1',
            'plan_info.db_size_gb' => 'required|integer|min:1',
            'plan_info.expired_at' => 'required|string|max:20',
        ], [
            'plan_info.required' => 'Plan info is required',
            'plan_info.array' => 'Plan info must be an array',
            'plan_info.name.required' => 'Plan name is required',
            'plan_info.name.string' => 'Plan name must be a string',
            'plan_info.name.max' => 'Plan name must not exceed 100 characters',
            'plan_info.user_limit.required' => 'User limit is required',
            'plan_info.user_limit.integer' => 'User limit must be an integer',
            'plan_info.user_limit.min' => 'User limit must be at least 1',
            'plan_info.db_size_gb.required' => 'Database size is required',
            'plan_info.db_size_gb.integer' => 'Database size must be an integer',
            'plan_info.db_size_gb.min' => 'Database size must be at least 1 GB',
            'plan_info.expired_at.required' => 'Expiration date is required',
            'plan_info.expired_at.string' => 'Expiration date must be a string',
            'plan_info.expired_at.max' => 'Expiration date must not exceed 20 characters',
        ]);

        if ($validator->fails()) {
            return [
                'success' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Plan info validation failed',
                'messages' => $validator->errors()->toArray(),
                'status' => 422
            ];
        }

        // Validate expiration date format (YYYYMMDD)
        if (!preg_match('/^\d{8}$/', $input['plan_info']['expired_at'])) {
            return [
                'success' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Expiration date must be in YYYYMMDD format',
                'status' => 422
            ];
        }

        // Validate expiration date is in the future
        $expiredAt = $input['plan_info']['expired_at'];
        $expiredDate = \DateTime::createFromFormat('Ymd', $expiredAt);
        $today = new \DateTime();
        
        if ($expiredDate <= $today) {
            return [
                'success' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Expiration date must be in the future',
                'status' => 422
            ];
        }

        return ['success' => true];
    }

    /**
     * Validate tenant_suuid field
     *
     * @param array $input
     * @return array
     */
    protected function validateTenantSuuid(array $input): array
    {
        $validator = Validator::make($input, [
            'tenant_suuid' => 'required|string|max:255',
        ], [
            'tenant_suuid.required' => 'Tenant SUUID is required',
            'tenant_suuid.string' => 'Tenant SUUID must be a string',
            'tenant_suuid.max' => 'Tenant SUUID must be at most 255 characters',
        ]);

        if ($validator->fails()) {
            return [
                'success' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Tenant SUUID validation failed',
                'messages' => $validator->errors()->toArray(),
                'status' => 422
            ];
        }

        return ['success' => true];
    }

    /**
     * Check if tenant exists by SUUID
     *
     * @param string $tenantSuuid
     * @return array
     */
    protected function checkTenantExists(string $tenantSuuid): array
    {
        $tenant = TenantService::getTenantById($tenantSuuid);
        if (!$tenant) {
            return [
                'success' => false,
                'error' => 'TENANT_NOT_FOUND',
                'message' => 'Tenant not found',
                'status' => 404
            ];
        }

        return [
            'success' => true,
            'data' => ['tenant' => $tenant]
        ];
    }

    /**
     * Validate identifier (subdomain or tenant_path)
     *
     * @param array $input
     * @return array
     */
    protected function validateIdentifier(array $input): array
    {
        $errors = [];
        
        // Check if either subdomain or tenant_path is provided
        $hasSubdomain = isset($input['subdomain']) && !empty($input['subdomain']);
        
        if (!$hasSubdomain) {
            $errors['identifier'][] = 'Subdomain is required.';
        } else {
            $identifier = $input['subdomain'];
            if (!is_string($identifier)) {
                $errors['subdomain'][] = 'Subdomain must be a string.';
            } elseif (!preg_match('/^[a-z0-9](?:[a-z0-9\-]{1,61}[a-z0-9])?$/', strtolower($identifier))) {
                // 1-63 chars, start/end with alnum, allow hyphen inside
                $errors['subdomain'][] = 'Subdomain format is invalid (1-63 chars, lowercase alphanumerics and hyphens, cannot start/end with hyphen).';
            }
        }
        
        if (!empty($errors)) {
            return [
                'success' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Identifier validation failed',
                'messages' => $errors,
                'status' => 422
            ];
        }

        return ['success' => true];
    }

    /**
     * Common validation logic for both provision and update
     * This method contains the core validation logic that can be reused
     *
     * @param array $input
     * @param bool $skipToken 
     * @param bool $skipTenantSuuid Skip validation for tenant_suuid
     * @return array
     */
    protected function validateCommon(array $input, bool $skipToken = false, bool $skipTenantSuuid = false): array
    {
        // Validate basic fields (identifier, token, plan_info)
        $basic = $this->validateBasic($input, $skipToken, $skipTenantSuuid);
        if (!$basic['success']) return $basic;

        // Determine if this is a subdomain or tenant_path request
        $isSubdomain = isset($input['subdomain']);
        $isTenantPath = isset($input['tenant_path']);

        $identifier = $isSubdomain ? strtolower($input['subdomain']) : strtolower($input['tenant_path']);
        $type = $isSubdomain ? 'subdomain' : 'tenant_path';

        return [
            'success' => true,
            'data' => [
                'subdomain' => $isSubdomain ? $identifier : null,
                'tenant_path' => $isTenantPath ? $identifier : null,
                'type' => $type,
                'plan_info' => $input['plan_info'],
            ]
        ];
    }

    /**
     * Validate basic fields with optional skipping of certain fields
     *
     * @param array $input
     * @param bool $skipToken
     * @param bool $skipTenantSuuid
     * @return array
     */
    protected function validateBasic(array $input, bool $skipToken = false, bool $skipTenantSuuid = false): array
    {
        // Validate identifier using base class method
        $identifierValidation = $this->validateIdentifier($input);
        if (!$identifierValidation['success']) {
            return $identifierValidation;
        }

        // Validate token using base class method
        if (!$skipToken) {
            $tokenValidation = $this->validateToken($input);
            if (!$tokenValidation['success']) {
                return $tokenValidation;
            }
        }
        

        // Validate plan_info using base class method
        $planValidation = $this->validatePlanInfo($input);
        if (!$planValidation['success']) {
            return $planValidation;
        }

        // Validate tenant_suuid if not skipped
        if (!$skipTenantSuuid) {
            $suuidValidation = $this->validateTenantSuuid($input);
            if (!$suuidValidation['success']) {
                return $suuidValidation;
            }
        }
        
        return ['success' => true];
    }

    /**
     * Validate reserved identifier (subdomain or tenant_path)
     * This validation should apply to both provision and update
     *
     * @param string $identifier
     * @param string $type
     * @return array
     */
    protected function validateReservedIdentifier(string $identifier, string $type): array
    {
        $reserved = [
            'admin', 'www', 'api', 'root', 'mail', 'ftp', 'test', 'dev', 'staging', 'support', 'help', 'system', 'exment'
        ];
        if (in_array(strtolower($identifier), $reserved)) {
            return [
                'success' => false,
                'error' => 'RESERVED_IDENTIFIER',
                'message' => "This {$type} is reserved and cannot be used.",
                'status' => 400
            ];
        }
        return ['success' => true];
    }

    /**
     * Validate blacklist words contained in subdomain
     * This validation should apply to both provision and update
     *
     * @param string $subdomain
     * @return array
     */
    protected function validateBlacklistWords(string $subdomain): array
    {
        // Default blacklist; can be extended via config('exment.tenant_provision_blacklist')
        $default = ['fuck', 'spam', 'superadmin', 'gov', 'root', 'sex', 'porn', 'abuse'];
        $configured = \Config::get('exment.tenant_provision_blacklist');
        $blacklist = is_array($configured) ? array_map('strtolower', $configured) : $default;

        foreach ($blacklist as $word) {
            if ($word !== '' && str_contains($subdomain, $word)) {
                return [
                    'success' => false,
                    'error' => 'BLACKLISTED_SUBDOMAIN',
                    'message' => 'Subdomain contains prohibited words.',
                    'status' => 400
                ];
            }
        }
        return ['success' => true];
    }

    /**
     * Validate duplicate identifier (subdomain or tenant_path)
     * This validation should apply to both provision and update
     *
     * @param string $identifier
     * @param string $type
     * @param string|null $excludeTenantSuuid Exclude current tenant from duplicate check (for updates)
     * @return array
     */
    protected function validateDuplicateIdentifier(string $identifier, string $type, ?string $excludeTenantSuuid = null): array
    {
        $query = Tenant::query();
        if ($type === 'subdomain') {
            $query->where('subdomain', strtolower($identifier));
        } else {
            $query->where('tenant_path', strtolower($identifier));
        }

        // Ignore logically deleted tenants
        $query->where('status', '!=', \Exceedone\Exment\Enums\TenantStatus::DELETED);
        
        // Exclude current tenant for update operations
        if ($excludeTenantSuuid !== null) {
            $query->where('tenant_suuid', '!=', $excludeTenantSuuid);
        }
        
        $exists = $query->exists();

        if ($exists) {
            return [
                'success' => false,
                'error' => 'DUPLICATE_IDENTIFIER',
                'message' => "This {$type} already exists.",
                'status' => 409
            ];
        }
        return ['success' => true];
    }

    /**
     * Validate rate limit by IP/user/token to prevent spam
     * This validation should apply to both provision and update
     *
     * @param array $input
     * @param string $operationType 'provision' or 'update'
     * @return array
     */
    protected function validateRateLimit(array $input, string $operationType = 'provision'): array
    {
        $ip = \Request::ip();
        $token = (string)($input['token'] ?? '');
        $subdomain = strtolower((string)($input['subdomain'] ?? ''));

        $maxAttempts = (int)\Config::get('exment.tenant_' . $operationType . '_max_attempts', 5);
        $decaySeconds = (int)\Config::get('exment.tenant_' . $operationType . '_decay_seconds', 60);

        $keyParts = [
            'tenant_' . $operationType,
            $ip ?: 'noip',
            $token !== '' ? substr(hash('sha256', $token), 0, 10) : 'notoken',
        ];
        // Optionally include subdomain to guard burst attempts for the same name
        if ($subdomain !== '') {
            $keyParts[] = $subdomain;
        }
        $key = implode('|', $keyParts);

        if (\RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = \RateLimiter::availableIn($key);
            return [
                'success' => false,
                'error' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Too many attempts. Please try again later.',
                'status' => 429,
                'retry_after_seconds' => $retryAfter,
            ];
        }

        \RateLimiter::hit($key, $decaySeconds);
        return ['success' => true];
    }

    /**
     * Validate tenant conflict with alias/custom domains (if configured)
     * This validation should apply to both provision and update
     *
     * @param string $identifier
     * @param string $type
     * @return array
     */
    protected function validateTenantConflict(string $identifier, string $type): array
    {
        // If your system maintains alias/custom domains, validate here.
        // Example: config('exment.tenant_domain_aliases') returns list of hostnames or maps
        $aliases = \Config::get('exment.tenant_domain_aliases', []);
        if (is_array($aliases) && !empty($aliases)) {
            foreach ($aliases as $alias) {
                // Accept either plain hostname strings or arrays with 'host'
                $host = is_array($alias) ? ($alias['host'] ?? null) : $alias;
                if (!is_string($host) || $host === '') {
                    continue;
                }
                $leftLabel = explode('.', strtolower($host))[0] ?? '';
                if ($leftLabel === $identifier) {
                    return [
                        'success' => false,
                        'error' => 'TENANT_CONFLICT',
                        'message' => "{$type} conflicts with an existing alias/custom domain.",
                        'status' => 409
                    ];
                }
            }
        }
        return ['success' => true];
    }
}
