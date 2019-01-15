<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Encore\Admin\Form\Field;

class Select extends CustomItem 
{
    public function value(){
        return $this->getResultForSelect(false);
    }

    public function text(){
        return $this->getResultForSelect(true);
    }

    protected function getResultForSelect($label){
        $select_options = $this->custom_column->createSelectOptions();
        // if $value is array
        $multiple = true;
        if (!is_array($this->value)) {
            $val = [$this->value];
            $multiple = false;
        }else{
            $val = $this->value;   
        }
        // switch column_type and get return value
        $returns = $this->getReturnsValue($select_options, $val, $label);
        
        if ($multiple) {
            return $label ? implode(exmtrans('common.separate_word'), $returns) : $returns;
        } else {
            return $returns[0];
        }
    }

    protected function getReturnsValue($select_options, $val, $label){
        return $val;
    }
    
    protected function getAdminFieldClass(){
        if (boolval(array_get($this->custom_column, 'options.multiple_enabled'))) {
            return Field\MultipleSelect::class;
        } else {
            return Field\Select::class;
        }
    }
    
    protected function setAdminOptions(&$field, $form_column_options){
        $field->options($this->custom_column->createSelectOptions());
    }
}
