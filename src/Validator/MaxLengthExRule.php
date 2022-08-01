<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * MaxLengthExRule.
 * Consider comma.
 */
class MaxLengthExRule implements Rule
{
    protected $max_length;

    public function __construct($parameters)
    {
        $this->max_length = $parameters;
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

        $value = str_replace("\r\n", "\n", $value);

        return mb_strlen($value) <= $this->max_length;
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return str_replace(':max', $this->max_length, trans('validation.max.string'));
    }
}
