<?php

namespace Exceedone\Exment\Services\Login;

/**
 * Custom Login User.
 * For OAuth, Saml, Plugin login.
 * When get user info from provider, set this model.
 */
abstract class CustomLoginUserBase
{
    public $login_setting;
    public $login_id;
    public $mapping_user_column;

    public $provider_name;
    public $id;
    public $email;
    public $user_code;
    public $user_name;
    public $login_type;
    /**
     * Dummy password.
     *
     * @var string
     */
    public $dummy_password;

    /**
     * mappng error.
     *
     * @var array
     */
    public $mapping_errors = [];

    /**
     * Get for validation array
     *
     * @return void
     */
    public function getValidateArray()
    {
        return [
            'id' => $this->id,
            'user_code' => $this->user_code,
            'user_name' => $this->user_name,
            'email' => $this->email,
        ];
    }

    /**
     * Mapping Exment and provider user value
     *
     * @return void
     */
    protected static function setMappingValue(CustomLoginUserBase $user, $providerUser){
        $keys = ['user_code', 'user_name', 'email'];

        // set values
        foreach ($keys as $key) {
            $mappingKeys = $user->login_setting->getOption("mapping_$key");
            
            $mappingValue = null;
            foreach(stringToArray($mappingKeys) as $mappingKey){
                // if has ${XXXXX}format, replace get items
                $replaceMaps = [];
                preg_match_all('/\${(?<key>.+?)}/', $mappingKey, $output_array);
                if (count(array_get($output_array, 'key')) > 0) {
                    foreach (array_get($output_array, 'key') as $regexIndex => $regexKey) {
                        $replaceMaps[$regexKey] = $output_array[0][$regexIndex];
                    }
                } else {
                    $replaceMaps[$mappingKey] = $mappingKey;
                }

                $mappingValue = static::getMappingItemValue($providerUser, $mappingKey, $replaceMaps);
                if(!is_nullorempty($mappingValue)){
                    break;
                }
            }

            // if cannot mapping, append error
            if(is_nullorempty($mappingValue)){
                $user->mapping_errors[$key] = exmtrans('login.message.not_match_mapping', [
                    'key' => $key,
                    'mappingKey' => $mappingKeys,
                ]);
            }
            else{
                $user->{$key} = $mappingValue;
            }
        }
    }
}
