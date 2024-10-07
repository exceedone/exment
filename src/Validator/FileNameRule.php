<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * FileNameRule.
 */
class FileNameRule implements Rule
{

    public function __construct() {}

    /**
     * Check Validation
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        if ($value instanceof \Illuminate\Http\UploadedFile) {
            $filename = $value->getClientOriginalName();
        } else {
            $filename = pathinfo($value, PATHINFO_BASENAME);
        }
        return !preg_match('/[\/\\\:\*\?\"<>\|]/', $filename);
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return exmtrans('validation.filename_not_allow');
    }
}
