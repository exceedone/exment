<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * EmailMultiline.
 */
class EmailMultiline implements Rule
{
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

        $array = explodeBreak($value);

        foreach ($array as $a) {
            if (is_nullorempty($a)) {
                continue;
            }

            if (filter_var($a, FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }
        }

        return true;
    }


    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return exmtrans('validation.email_multiline');
    }
}
