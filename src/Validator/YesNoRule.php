<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * YesNoRule.
 */
class YesNoRule implements Rule
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

        if ($value === true || $value === false) {
            return true;
        }

        return isMatchString($value, '0')
            || isMatchString($value, '1')
            || isMatchString($value, 'true')
            || isMatchString($value, 'false')
            || isMatchString(strtolower($value), 'yes')
            || isMatchString(strtolower($value), 'no');
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return trans('validation.in', [
            'values' => '0,1,YES,NO,yes,no,true,false'
        ]);
    }
}
