<?php
namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Exceedone\Exment\Providers\CustomUserProvider;

/**
 * OldPasswordRule
 */
class OldPasswordRule implements Rule
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
        return CustomUserProvider::ValidateCredential(\Exment::user(), ['password' => $value]);
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return exmtrans('error.old_password');
    }
}
