<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * NumberMaxRule.
 * Consider comma.
 */
class NumberMaxRule implements Rule
{
    protected $max;

    public function __construct($parameters)
    {
        $this->max = $parameters;
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

        // remove comma
        $value = rmcomma($value);

        if (!is_numeric($value)) {
            return true;
        }

        return $this->max >= floatval($value);
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return str_replace(':max', $this->max, trans('validation.max.numeric'));
    }
}
