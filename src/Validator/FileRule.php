<?php
namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Concerns\ValidatesAttributes;
use Exceedone\Exment\Model\Define;

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
            
            foreach($value as $v){
                if(!$this->validateExntion($attribute, $v)){
                    return false;
                }
            }

            return true;
        }
        else{
            return $this->validateExtension($attribute, $value);
        }
    }

    protected function validateExtension($attribute, $value){
        if (is_string($value)) {
            $ext = pathinfo($value, PATHINFO_EXTENSION);
            return in_array($ext, $this->extensions);
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
        return trans('validation.file');
    }
}
