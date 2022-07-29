<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * TimeRule.
 * HHiiss
 * HHii
 * HH:ii:ss
 * HH:ii
 */
class TimeRule implements Rule
{
    protected const TIME_FORMATS = [
        '^(0[0-9]|1[0-9]|2[0-3]|[0-9]):[0-5][0-9]$',
        '^(0[0-9]|1[0-9]|2[0-3])[0-5][0-9]$',
        '^(0[0-9]|1[0-9]|2[0-3]|[0-9]):[0-5][0-9]:[0-5][0-9]$',
        '^(0[0-9]|1[0-9]|2[0-3])[0-5][0-9][0-5][0-9]$',
    ];

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
        if (is_list($value)) {
            return false;
        }

        if ($value instanceof \Carbon\Carbon) {
            return true;
        }

        foreach (static::TIME_FORMATS as $time_format) {
            if (preg_match("/{$time_format}/u", $value)) {
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
        return trans('validation.regex');
    }
}
