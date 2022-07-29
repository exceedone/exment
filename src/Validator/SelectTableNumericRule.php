<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * SelectTableRule.
 * numeric or array.
 * *not consider allow multiple.
 */
class SelectTableNumericRule implements Rule
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
        if (is_nullorempty($value)) {
            return true;
        }

        // if numeric, return true
        if (!is_list($value) && is_numeric($value)) {
            return true;
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if ($value instanceof \Exceedone\Exment\Model\CustomValue) {
            return true;
        }

        if (is_list($value)) {
            $value = array_filter(toArray($value));
            foreach ($value as $v) {
                if (!is_numeric($v)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return trans('validation.numeric');
    }
}
