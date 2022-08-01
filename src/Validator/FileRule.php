<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Concerns\ValidatesAttributes;

/**
 * FileRule.
 */
class FileRule implements Rule
{
    use ValidatesAttributes;

    protected $extensions = [];

    public function __construct(array $extensions = [])
    {
        $this->extensions = $extensions;
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
        // not check null or empty. Check by other required rule.
        if (is_nullorempty($value)) {
            return true;
        }

        if (is_array($value)) {
            $value = array_filter($value);
            if (is_nullorempty($value)) {
                return true;
            }

            foreach ($value as $v) {
                if (!$this->validateExtension($attribute, $v)) {
                    return false;
                }
            }

            return true;
        } else {
            return $this->validateExtension($attribute, $value);
        }
    }

    protected function validateExtension($attribute, $value)
    {
        if (is_string($value)) {
            $ext = pathinfo($value, PATHINFO_EXTENSION);
            return collect($this->extensions)->contains(function ($val) use ($ext) {
                return strcasecmp($val, $ext) == 0;
            });
        }

        if ($value instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $original_name = $value->getClientOriginalName();
            if (!is_nullorempty($original_name)) {
                $ext = pathinfo($original_name, PATHINFO_EXTENSION);
                return collect($this->extensions)->contains(function ($val) use ($ext) {
                    return strcasecmp($val, $ext) == 0;
                });
            }
        }

        return $this->validateMimes($attribute, $value, $this->extensions);
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return trans('validation.mimes', ['values' => arrayToString($this->extensions)]);
    }
}
