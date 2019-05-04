<?php
namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;

/**
 * CustomValueRule.
 * Check contains target table 
 */
class CustomValueRule implements Rule
{
    protected $target_table;

    public function __construct($parameters)
    {
        $this->target_table = CustomTable::getEloquent($parameters);
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

        // if not number, return true. (Checking whether value is number, validate other rule)
        if(!is_numeric($value)){
            return true;
        }

        if(!isset($this->target_table)){
            return true;
        }

        // get target table's value (use request session)
        $key = sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALIDATION, $this->target_table->table_name, $value);
        $model = System::requestSession($key, function() use($value){
            return $this->target_table->getValueModel($value);
        });

        return isset($model);
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return str_replace(':min', $this->min, trans('validation.min.numeric'));
    }
}
