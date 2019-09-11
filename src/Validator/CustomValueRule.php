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
    protected $custom_table;
    public function __construct($parameters)
    {
        $this->custom_table = CustomTable::getEloquent($parameters);
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
        if(!isset($this->custom_table)){
            return true;
        }
        // get target table's value (use request session)
        $key = sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALIDATION, $this->custom_table->table_name, $value);
        $model = System::requestSession($key, function() use($value){
            return $this->custom_table->getValueModel($value);
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
        return str_replace(':table_view_name', $this->custom_table->table_view_name, exmtrans('validation.not_has_custom_value'));
    }
}
