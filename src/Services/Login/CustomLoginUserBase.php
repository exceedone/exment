<?php

namespace Exceedone\Exment\Services\Login;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\SystemTableName;

/**
 * Custom Login User.
 * For OAuth, Saml, Plugin login.
 * When get user info from provider, set this model.
 *
 * @method static function getMappingItemValue($samlUser, $mappingKey, $replaceMaps)
 */
abstract class CustomLoginUserBase
{
    public $id;
    public $login_setting;
    public $login_id;
    public $mapping_user_column;

    public $provider_name;
    // public $email;
    // public $user_code;
    // public $user_name;
    public $login_type;

    /**
     * mapping error.
     *
     * @var array
     */
    public $mapping_errors = [];

    /**
     * mapped value.
     *
     * @var array
     */
    public $mapping_values = [];


    public function user_code()
    {
        return array_get($this->mapping_values, 'user_code');
    }

    public function user_name()
    {
        return array_get($this->mapping_values, 'user_name');
    }

    public function email()
    {
        return array_get($this->mapping_values, 'email');
    }

    public function domain()
    {
        if (is_nullorempty($email = $this->email())) {
            return null;
        }
        /** @phpstan-ignore-next-line If condition is always false. explode always one element in array */
        if (count($emails = explode("@", $email)) == 0) {
            return null;
        }

        return $emails[1];
    }

    /**
     * Mapping Exment and provider user value
     *
     * @return void
     */
    protected static function setMappingValue(CustomLoginUserBase $user, $providerUser)
    {
        $user_custom_columns = static::getUserColumns();

        // set values
        foreach ($user_custom_columns as $user_custom_column) {
            $key = $user_custom_column->column_name;

            $mappingKeys = $user->login_setting->getOption("mapping_column_$key");

            $mappingValue = null;
            foreach (stringToArray($mappingKeys) as $mappingKey) {
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
                if (!is_nullorempty($mappingValue)) {
                    break;
                }
            }

            // if cannot mapping, append error
            if (is_nullorempty($mappingValue) && $user_custom_column->required) {
                $user->mapping_errors[$key] = exmtrans('login.message.not_match_mapping', [
                    'key' => $key,
                    'mappingKey' => $mappingKeys,
                ]);
            } else {
                $user->mapping_values[$key] = $mappingValue;
            }
        }
    }
    protected static function getUserColumns()
    {
        return CustomTable::getEloquent(SystemTableName::USER)->custom_columns_cache;
    }
}
