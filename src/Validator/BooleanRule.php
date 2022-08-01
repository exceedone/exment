<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * BooleanRule.
 */
class BooleanRule implements Rule
{
    protected $options;

    public function __construct($parameters)
    {
        $this->options = $parameters;
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

        foreach ($this->options as $k => $v) {
            if (isMatchString($value, $k) || isMatchString($value, $v)) {
                return true;
            }
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
        $values = [];

        foreach ($this->options as $k => $v) {
            $values[] = $k;
            $values[] = $v;
        }

        return trans('validation.in', [
            'values' => implode(",", $values)
        ]);
    }
}
