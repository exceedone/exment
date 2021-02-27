<?php
namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Concerns\ValidatesAttributes;
use Exceedone\Exment\Model\Define;

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
        // not check null or empty. Check by other required rule.
        if (is_nullorempty($value)) {
            return true;
        }

        if (is_array($value)) {
            foreach($value as $v){
                if(!$this->validateImage($attribute, $v)){
                    return false;
                }
            }

            return true;
        }
        else{
            return $this->validateImage($attribute, $value);
        }
    }

    protected function validateImage($attribute, $value){
        if (is_string($value)) {
            $ext = pathinfo($value, PATHINFO_EXTENSION);
            return in_array($ext, Define::IMAGE_RULE_EXTENSIONS);
        }

        return $this->validateMimes($attribute, $value, Define::IMAGE_RULE_EXTENSIONS);
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
