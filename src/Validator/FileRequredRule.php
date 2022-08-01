<?php

namespace Exceedone\Exment\Validator;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomValue;
use Illuminate\Contracts\Validation\ImplicitRule;

/**
 * FileRequredRule.
 * Required file. If has $custom_value, then alway return true.
 */
class FileRequredRule implements ImplicitRule
{
    protected $custom_column;

    protected $custom_value;

    public function __construct(CustomColumn $custom_column, ?CustomValue $custom_value)
    {
        $this->custom_column = $custom_column;
        $this->custom_value = $custom_value;
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
        if (!is_null($value)) {
            return true;
        }

        // if has custom_value, checking value
        if (isset($this->custom_value) && $this->custom_value->exists) {
            $v = array_get($this->custom_value->value, $this->custom_column->column_name);

            return !is_nullorempty($v);
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
