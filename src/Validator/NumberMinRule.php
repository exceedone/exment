<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * NumberMinRule.
 * Consider comma.
 */
class NumberMinRule implements Rule
{
    protected $min;

    public function __construct($parameters)
    {
        $this->min = $parameters;
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

        return $this->min <= floatval($value);
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return str_replace(':min', $this->min, trans('validation.min.numeric'));
    }
}
