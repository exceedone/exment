<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * Only Null Or Empty.
 */
class EmptyRule implements Rule
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
        return empty($value);
    }
    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return exmtrans('validation.empty');
    }
}
