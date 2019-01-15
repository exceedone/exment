<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Encore\Admin\Form\Field;

class Boolean extends CustomItem 
{
    public function text(){
        if (array_get($this->custom_column, 'options.true_value') == $this->value) {
            return array_get($this->custom_column, 'options.true_label');
        } elseif (array_get($this->custom_column, 'options.false_value') == $this->value) {
            return array_get($this->custom_column, 'options.false_label');
        }
        return null;
    }

    protected function getAdminFieldClass(){
        return Field\SwitchField::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options){
        $options = $this->custom_column->options;
        
        // set options
        $states = [
            'on'  => ['value' => array_get($options, 'true_value'), 'text' => array_get($options, 'true_label')],
            'off' => ['value' => array_get($options, 'false_value'), 'text' => array_get($options, 'false_label')],
        ];
        $field->states($states);
    }
}
