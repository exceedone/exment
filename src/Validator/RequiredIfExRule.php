<?php
namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * RequiredIfExRule.
 * Consider comma.
 */
class RequiredIfExRule implements Rule
{
    protected $target;
    protected $compares;

    public function __construct($parameters)
    {
        if (count($parameters) > 0) {
            $this->target = $parameters[0];
        }

        if (count($parameters) > 1) {
            $this->compares = array_slice($parameters, 1);
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
        $is_requied = false;
        if (request()->has($this->target)) {
            $other = request()->get($this->target);

            if (is_null($this->compares)) {
                $is_requied = isset($other);
            } else {
                if (is_array($other)) {
                    $other = array_filter($other);
                } else {
                    $other = [$other];
                }
                foreach($this->compares as $compare) {
                    if (in_array($compare, $other)) {
                        $is_requied = true;
                    }
                }
            }
        }
        
        if (is_array($value)) {
            $value = array_filter($value);
        }
        if ($is_requied && empty($value)) {
            return false;
        }

        return true;
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
