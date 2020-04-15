<?php

namespace Exceedone\Exment\Auth;

use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\LoginSetting;

class SSOUser
{
    public $login_setting;
    public $login_id;

    public $provider_name;
    public $id;
    public $email;
    public $user_code;
    public $user_name;
    public $login_type;
    public $avatar;
    public $token;
    public $refreshToken;
    public $expiresIn;

    public $dummy_password;
    
    public static function withOAuth($provider_name, $provider_user){
        $user = new SSOUser;
        $user->provider_name = $provider_name;
        $user->login_type = LoginType::OAUTH;
        $user->login_setting = LoginSetting::getOAuthSetting($provider_name);

        $user->id = $provider_user->id;
        $user->email = $provider_user->email;
        $user->user_code = $provider_user->id;
        $user->user_name = $provider_user->name ?: $provider_user->email;

        $user->avatar = isset($provider_user->avatar) ? $provider_user->avatar : null;
        $user->token = isset($provider_user->token) ? $provider_user->token : null;
        $user->refreshToken = isset($provider_user->refreshToken) ? $provider_user->refreshToken : null;
        $user->expiresIn = isset($provider_user->expiresIn) ? $provider_user->expiresIn : null;

        // find key name for search value
        $mapping_user_column = $user->login_setting->getOption('mapping_user_column') ?? 'email';
        $user->login_id = $user->{$mapping_user_column};
        $user->dummy_password = $provider_user->id;

        return $user;
    }

    public static function withSaml($provider_name, $samlUser){
        $user = new SSOUser;
        $user->provider_name = $provider_name;
        $user->login_type = LoginType::SAML;
        $user->login_setting = LoginSetting::getSamlSetting($provider_name);
        $user->id = $samlUser->getUserId();

        static::setSamlAttributeValue($user, $samlUser);

        // find key name for search value
        $mapping_user_column = $user->login_setting->getOption('mapping_user_column') ?? 'email';
        $user->login_id = $user->{$mapping_user_column};
        $user->dummy_password = $samlUser->getUserId();

        return $user;
    }

    protected static function setSamlAttributeValue(SSOUser $user, $samlUser){
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
