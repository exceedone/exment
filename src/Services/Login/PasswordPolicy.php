<?php

namespace Exceedone\Exment\Services\Login;

use Exceedone\Exment\Model\System;

/**
 * The recommended password policy (Login settings).
 *
 * A new site starts with it when EXMENT_PASSWORD_POLICY_NEW_SITE is true (the
 * cloud service sets it for a new tenant). The expiration stays 0: changing
 * the password on a schedule makes people choose weaker ones (NIST SP 800-63B).
 */
class PasswordPolicy
{
    /**
     * system_name => value
     */
    public const RECOMMENDED = [
        'complex_password' => true,
        'first_change_password' => true,
        'password_history_cnt' => 5,
    ];

    /**
     * Save the recommended policy for a new site. A setting saved already is kept.
     *
     * @return bool true: the policy is on for this site (it was asked for)
     */
    public static function applyNewSite(): bool
    {
        if (!boolval(config('exment.password_policy_new_site', false))) {
            return false;
        }
        if (boolval(System::initialized())) {
            return false;
        }

        foreach (static::RECOMMENDED as $name => $value) {
            if (System::where('system_name', $name)->exists()) {
                continue;
            }
            System::{$name}($value);
        }
        return true;
    }
}
