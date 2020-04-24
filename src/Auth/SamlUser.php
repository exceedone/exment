<?php

namespace Exceedone\Exment\Auth;

use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\LoginSetting;

/**
 * SamlUser.
 * For OAuth
 * When get user info from provider, set this model.
 */
class SamlUser extends CustomLoginUserBase
{
    public static function with($provider_name, $samlUser){
        $user = new SamlUser;
        $user->provider_name = $provider_name;
        $user->login_type = LoginType::SAML;
        $user->login_setting = LoginSetting::getSamlSetting($provider_name);
        $user->id = $samlUser->getUserId();

        static::setSamlAttributeValue($user, $samlUser);

        // find key name for search value
        $user->mapping_user_column = $user->login_setting->getOption('mapping_user_column') ?? 'email';
        $user->login_id = $user->{$user->mapping_user_column};
        $user->dummy_password = $provider_user->id;

        return $user;
    }

    protected static function setSamlAttributeValue(CustomLoginUser $user, $samlUser){
        $errors = [];

        // get attributes
        $samlAttibutes = $samlUser->getAttributes();
        $keys = ['user_code', 'user_name', 'email'];

        // set values
        foreach($keys as $key){
            $samlMappingKey = $user->login_setting->getOption("mapping_$key");
            
            // if has ${XXXXX}format, replace get items
            $replaceMaps = [];
            preg_match_all('/\${(?<key>.+?)}/', $samlMappingKey, $output_array);
            if(count(array_get($output_array, 'key')) > 0){
                foreach(array_get($output_array, 'key') as $regexIndex => $regexKey){
                    $replaceMaps[$regexKey] = $output_array[0][$regexIndex];
                }
            }
            else{
                $replaceMaps[$samlMappingKey] = $samlMappingKey;
            }

            foreach($replaceMaps as $replaceKey => $replaceValue){
                if(!array_has($samlAttibutes, $replaceKey)){
                    //TODO: not found key
                }
    
                $value = array_get($samlAttibutes, $replaceKey);
                if(is_array($value) && count($value) > 0){
                    $value = $value[0];
                }

                $samlMappingKey = \str_replace($replaceValue, $value, $samlMappingKey);
            }

            $user->{$key} = $samlMappingKey;
        }
    }
}
