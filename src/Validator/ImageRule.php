<?php
namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Concerns\ValidatesAttributes;

/**
 * ImageRule.
 */
class ImageRule implements Rule
{
    use ValidatesAttributes;

    /**
    * Check Validation
    *
    * @param  string  $attribute
    * @param  mixed  $value
    * @return bool
    */
    public function passes($attribute, $value)
    {
        if(is_string($value)){
            $ext = pathinfo($value, PATHINFO_EXTENSION);
            return in_array($ext, ['jpeg', 'jpg', 'png', 'gif', 'bmp', 'svg']);
        }

        return $this->validateImage($attribute, $value);
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return trans('validation.image');
    }
}
