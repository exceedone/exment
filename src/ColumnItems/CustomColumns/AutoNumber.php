<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Form\Field;

class AutoNumber extends CustomItem
{
    protected $required = false;

    protected function getAdminFieldClass()
    {
        return Field\Display::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options)
    {
        if(!isset($this->id)){
            $field->default(exmtrans('custom_value.auto_number_create'));
        }
    }
    
    /**
     * get auto number value
     */
    public function getAutoNumber()
    {
        // already set value, break
        if (isset($this->value)) {
            return null;
        }
        
        $options = $this->custom_column->options;
        if (!isset($options)) {
            return null;
        }
        
        if (array_get($options, 'auto_number_type') == 'format') {
            return $this->createAutoNumberFormat($options);
        }
        // if auto_number_type is random25, set value
        elseif (array_get($options, 'auto_number_type') == 'random25') {
            return make_licensecode();
        }
        // if auto_number_type is UUID, set value
        elseif (array_get($options, 'auto_number_type') == 'random32') {
            return make_uuid();
        }

        return null;
    }
    
    /**
     * Create Auto Number value using format.
     */
    protected function createAutoNumberFormat($options)
    {
        // get format
        $format = array_get($options, "auto_number_format");
        // get value
        $value = getModelName($this->custom_column->custom_table)::find($this->id);
        $auto_number = replaceTextFromFormat($format, $value);
        return $auto_number;
    }
}
