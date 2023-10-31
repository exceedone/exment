<?php

namespace Exceedone\Exment\Services\Login\Saml;

use Exceedone\Exment\Services\Login\CustomLoginUserBase;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\LoginSetting;

/**
 * SamlUser.
 * For OAuth
 * When get user info from provider, set this model.
 */
class SamlUser extends CustomLoginUserBase
{
    public static function with($provider_name, $samlUser, $isTest = false)
    {
        $user = new SamlUser();
        $user->id = $samlUser->getUserId();
        $user->provider_name = $provider_name;
        $user->login_type = LoginType::SAML;
        $user->login_setting = LoginSetting::getSamlSetting($provider_name, !$isTest);

        static::setMappingValue($user, $samlUser);

        // find key name for search value
        $user->mapping_user_column = $user->login_setting->getOption('mapping_user_column') ?? 'email';
        $user->login_id = array_get($user->mapping_values, $user->mapping_user_column);

        return $user;
    }

    /**
     * Mapping saml user value
     *
     * @param $samlUser
     * @param $mappingKey
     * @param $replaceMaps
     * @return array|mixed|string|string[]|null
     */
    protected static function getMappingItemValue($samlUser, $mappingKey, $replaceMaps)
    {
        // get attributes
        $samlAttibutes = $samlUser->getAttributes();

        $hasValue = false;
        foreach ($replaceMaps as $replaceKey => $replaceValue) {
            if (!array_has($samlAttibutes, $replaceKey)) {
                $mappingKey = str_replace($replaceValue, null, $mappingKey);
                continue;
            }

            $value = array_get($samlAttibutes, $replaceKey);
            if (is_array($value) && count($value) > 0) {
                $value = $value[0];
            }

            $mappingKey = str_replace($replaceValue, $value, $mappingKey);

            $hasValue = true;
        }

        // if not match all key, return null
        if (!$hasValue) {
            return null;
        }

        return $mappingKey;
    }
}
