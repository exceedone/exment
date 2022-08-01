<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Exceedone\Exment\Providers\LoginUserProvider;

/**
 * CurrentPasswordRule
 */
class CurrentPasswordRule implements Rule
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
        return LoginUserProvider::ValidateCredential(\Exment::user(), ['password' => $value]);
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return exmtrans('validation.current_password');
    }
}
