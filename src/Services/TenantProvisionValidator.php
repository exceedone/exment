<?php

namespace Exceedone\Exment\Services;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Exceedone\Exment\Model\Tenant;
use Exceedone\Exment\Services\Validators\TenantValidatorBase;

class TenantProvisionValidator extends TenantValidatorBase
{
    /**
     * Main validation entry point for tenant provisioning
     *
     * @param array $input
     * @return array
     */
    public function validate(array $input): array
    {
        // Use common validation logic (includes all fields: tenant_suuid, user fields, etc.)
        $commonValidation = $this->validateCommon($input, false, false); // skipUserFields=false, skipTenantSuuid=false
        if (!$commonValidation['success']) return $commonValidation;

        $data = $commonValidation['data'];
        $identifier = $data['subdomain'] ?: $data['tenant_path'];
        $type = $data['type'];

        $reserved = $this->validateReservedIdentifier($identifier, $type);
        if (!$reserved['success']) return $reserved;
        
        $rate = $this->validateRateLimit($input, 'provision');
        if (!$rate['success']) return $rate;

        $duplicate = $this->validateDuplicateIdentifier($identifier, $type);
        if (!$duplicate['success']) return $duplicate;

        return [
            'success' => true,
            'data' => $data
        ];
    }

    /**
     * Optional: Validate DNS propagation if auto DNS provisioning is enabled or hints provided
     */
    private function validateDNSPropagation(string $subdomain, array $input): array
    {
        $shouldCheck = (bool)Config::get('exment.tenant_provision_check_dns', false)
            || isset($input['dns_check_domain'])
            || isset($input['base_domain']);

        if (!$shouldCheck) {
            return ['success' => true];
        }

        $baseDomain = (string)($input['base_domain'] ?? Config::get('exment.tenant_base_domain', ''));
        $fqdn = $baseDomain !== '' ? $subdomain . '.' . $baseDomain : (string)($input['dns_check_domain'] ?? '');
        if ($fqdn === '') {
            // Not enough info to check; skip
            return ['success' => true];
        }

        $expected = (string)($input['dns_expected_target'] ?? Config::get('exment.tenant_expected_dns_target', ''));

        // Perform a lightweight DNS lookup. If function unavailable in env, skip.
        if (!function_exists('dns_get_record')) {
            return ['success' => true];
        }

        $records = @dns_get_record($fqdn, DNS_A | DNS_CNAME) ?: [];
        if (empty($records)) {
            return [
                'success' => false,
                'error' => 'DNS_NOT_PROPAGATED',
                'message' => 'DNS has not propagated yet.',
                'status' => 424 // Failed Dependency / Precondition-like
            ];
        }

        if ($expected !== '') {
            $matched = false;
            foreach ($records as $rec) {
                $value = $rec['ip'] ?? ($rec['target'] ?? '');
                if (is_string($value) && Str::lower(rtrim($value, '.')) === Str::lower(rtrim($expected, '.'))) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return [
                    'success' => false,
                    'error' => 'DNS_MISMATCH',
                    'message' => 'DNS target does not match the expected destination.',
                    'status' => 424
                ];
            }
        }

        return ['success' => true];
    }

    /**
     * Validate user permission to create tenant subdomain
     */
    private function validateUserPermission(): array
    {
        $user = \Exment::user();
        if ($user === null) {
            // If unauthenticated and using token-based provisioning, skip user permission
            return ['success' => true];
        }

        if (method_exists($user, 'isAdministrator') && $user->isAdministrator()) {
            return ['success' => true];
        }

        // If permission system is available, require at least SYSTEM or API access
        if (method_exists($user, 'hasPermission') && $user->hasPermission(\Exceedone\Exment\Enums\Permission::SYSTEM)) {
            return ['success' => true];
        }
        if (method_exists($user, 'hasPermission') && $user->hasPermission(\Exceedone\Exment\Enums\Permission::API_ALL)) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'error' => 'INSUFFICIENT_PERMISSION',
            'message' => 'You do not have permission to create a subdomain.',
            'status' => 403
        ];
    }

    /**
     * Validate environment restrictions in staging/demo
     */
    private function validateEnvironmentRestrictions(string $subdomain): array
    {
        $env = app()->environment();
        $isRestrictedEnv = in_array($env, ['staging', 'demo'], true) || (bool)Config::get('exment.tenant_provision_env_restrictions', false);
        if (!$isRestrictedEnv) {
            return ['success' => true];
        }

        // Optional allowed prefixes (e.g., demo-, test-)
        $prefixes = Config::get('exment.tenant_provision_allowed_prefixes', ['demo', 'test']);
        if (is_array($prefixes) && !empty($prefixes)) {
            $ok = false;
            foreach ($prefixes as $p) {
                $p = strtolower((string)$p);
                if ($p !== '' && str_starts_with($subdomain, rtrim($p, '-') . '-')) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return [
                    'success' => false,
                    'error' => 'ENVIRONMENT_RESTRICTION',
                    'message' => 'Subdomain does not satisfy environment restrictions (prefix).',
                    'status' => 400
                ];
            }
        }

        // Optional daily cap per IP in restricted envs
        $maxPerDay = (int)Config::get('exment.tenant_provision_daily_cap', 0);
        if ($maxPerDay > 0) {
            $ip = Request::ip() ?: 'noip';
            $dayKey = 'tenant_provision:daycap:' . $ip . ':' . date('Ymd');
            // Use RateLimiter as a simple counter with 24h decay
            $current = RateLimiter::attempt($dayKey, $maxPerDay, function () {
                // no-op; attempt reserves one slot
            }, 86400);

            if ($current === false) {
                return [
                    'success' => false,
                    'error' => 'DAILY_LIMIT_EXCEEDED',
                    'message' => 'Daily subdomain creation limit exceeded for this environment.',
                    'status' => 429
                ];
            }
        }

        return ['success' => true];
    }

}