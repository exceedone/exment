<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\LoginType;

/**
 * SamlNameRule.
 */
class SamlNameUniqueRule implements Rule
{
    public function __construct()
    {
    }

    /**
    * Check Validation
    *
    * @param  string  $attribute
    * @param  mixed  $value
    * @return bool
    */
    public function passes($attribute, $value)
    {
        if (is_null($value)) {
            return true;
        }

        return \DB::table(SystemTableName::LOGIN_SETTINGS)
            ->where('options->saml_name', $value)
            ->where('login_type', LoginType::SAML)
            ->count() == 0;
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return trans('validation.unique');
    }
}
