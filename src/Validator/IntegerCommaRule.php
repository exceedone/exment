<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

class IntegerCommaRule implements Rule
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
        if (is_list($value)) {
            return false;
        }
        return preg_match('/^[-]?[\d\s,]*$/', $value);
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return trans('validation.integer');
    }
}
