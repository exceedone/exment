<?php
namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * RequiredIfExRule.
 * Consider comma.
 */
class RequiredIfExRule implements Rule
{
    protected $settings = [];
    protected $target;
    protected $compares;

    public function __construct($parameters)
    {
        if(is_array($parameters)){
            $this->settings = $parameters;
        }else{
            $this->settings[] = [$parameters];
        }
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
        foreach($this->settings as $setting){
            $is_requied = false;
            $target = $setting[0];
            $compares = array_slice($setting, 1);

            if (request()->has($target)) {
                $other = request()->get($target);
    
                if (is_null($compares)) {
                    $is_requied = isset($other);
                } else {
                    if (is_array($other)) {
                        $other = array_filter($other);
                    } else {
                        $other = [$other];
                    }
                    foreach ($compares as $compare) {
                        if (in_array($compare, $other)) {
                            $is_requied = true;
                        }
                    }
                }
            }
            
            if (is_array($value)) {
                $value = array_filter($value);
            }
            if (!$is_requied || !empty($value)) {
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
        return trans('validation.required');
    }
}
