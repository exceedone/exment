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
        if (is_array($value)) {
            $value = array_filter($value);
            foreach ($value as $v) {
                if (!$this->validateFileName($attribute, $v)) {
                    return false;
                }
            }

            return true;
        } else {
            return $this->validateFileName($attribute, $value);
        }
    }

    protected function validateFileName($attribute, $value)
    {
        // not check null or empty. Check by other required rule.
        if (is_nullorempty($value)) {
            return true;
        } else if (is_string($value)) {
            $filename = pathinfo($value, PATHINFO_BASENAME);
        } else if ($value instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $filename = $value->getClientOriginalName();
        } else {
            return false;
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
