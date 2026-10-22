<?php

namespace Exceedone\Exment\Services\Login\Ldap;

use Exceedone\Exment\Services\Login\CustomLoginUserBase;
use Exceedone\Exment\Enums\LoginType;

/**
 * LdapUser.
 * For Ldap login
 * When get user info from provider, set this model.
 */
class LdapUser extends CustomLoginUserBase
{
    /**
     * @param \Exceedone\Exment\Model\LoginSetting $login_setting
     * @param LdapEntry $ldapUser
     * @return LdapUser
     */
    public static function with($login_setting, $ldapUser)
    {
        $user = new LdapUser();
        $user->provider_name = $login_setting->getOption('ldap_name');
        $user->login_type = LoginType::LDAP;
        $user->login_setting = $login_setting;

        static::setMappingValue($user, $ldapUser);

        // find key name for search value
        $user->mapping_user_column = $user->login_setting->getOption('mapping_user_column') ?? 'email';
        $user->login_id = array_get($user->mapping_values, $user->mapping_user_column);
        $user->id = $ldapUser->getAuthIdentifier();

        return $user;
    }

    /**
     * Mapping ldap user value
     *
     * @param LdapEntry $ldapuser
     * @param string $mappingKey
     * @param array<string, string> $replaceMaps
     * @return mixed
     */
    protected static function getMappingItemValue($ldapuser, $mappingKey, $replaceMaps)
    {
        $hasValue = false;
        foreach ($replaceMaps as $replaceKey => $replaceValue) {
            [$found, $value] = $ldapuser->resolve($replaceKey);
            $mappingKey = str_replace($replaceValue, (string) $value, $mappingKey);
            if ($found) {
                $hasValue = true;
            }
        }

        // if not match all key, return null
        if (!$hasValue) {
            return null;
        }

        return $mappingKey;
    }
}
