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
     * Mapping saml user value
     *
     * @param \Adldap\Models\User $ldapuser
     * @param string $mappingKey
     * @param array $replaceMaps
     * @return mixed
     */
    protected static function getMappingItemValue($ldapuser, $mappingKey, $replaceMaps)
    {
        $hasValue = false;
        foreach ($replaceMaps as $replaceKey => $replaceValue) {
            $ldap_attr = $replaceKey;
            $method = 'get' . $ldap_attr;
            if (method_exists($ldapuser, $method)) {
                $mappingKey = str_replace($replaceValue, $ldapuser->$method(), $mappingKey);
                $hasValue = true;
                continue;
            }

            if (!isset($ldapuser_attrs)) {
                $ldapuser_attrs = self::accessProtected($ldapuser, 'attributes');
            }

            $ldap_attr = strtolower($ldap_attr);

            if (!isset($ldapuser_attrs[$ldap_attr])) {
                $mappingKey = str_replace($replaceValue, null, $mappingKey);
                continue;
            }

            if (!is_array($ldapuser_attrs[$ldap_attr])) {
                $mappingKey = str_replace($replaceValue, $ldapuser_attrs[$ldap_attr], $mappingKey);
                $hasValue = true;
                continue;
            }

            if (count($ldapuser_attrs[$ldap_attr]) == 0) {
                $mappingKey = str_replace($replaceValue, null, $mappingKey);
                continue;
            }

            // now it returns the first item, but it could return
            // a comma-separated string or any other thing that suits you better
            $mappingKey = str_replace($replaceValue, $ldapuser_attrs[$ldap_attr][0], $mappingKey);
            $hasValue = true;
        }

        // if not match all key, return null
        if (!$hasValue) {
            return null;
        }

        return $mappingKey;
    }

    protected static function accessProtected($obj, $prop)
    {
        $reflection = new \ReflectionClass($obj);
        $property = $reflection->getProperty($prop);
        $property->setAccessible(true);
        return $property->getValue($obj);
    }
}
