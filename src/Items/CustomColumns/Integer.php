<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Validator;

class Integer extends CustomItem 
{
    public function text(){
        // if not empty format, using carbon
        $format = array_get($this->custom_column, 'options.format');
        if (!is_nullorempty($format)) {
            return (new \Carbon\Carbon($this->value))->format($format) ?? null;
        }
        // else, return
        return $this->value;
    }
    
    protected function getAdminFieldClass(){
        return Field\Number::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options){
        $options = $this->custom_column->options;
        
        if (!boolval(array_get($options, 'updown_button'))) {
            $field->disableUpdown();
            $field->defaultEmpty();
        }

        if (!is_null(array_get($options, 'number_min'))) {
            $field->attribute(['min' => array_get($options, 'number_min')]);
        }
        if (!is_null(array_get($options, 'number_max'))) {
            $field->attribute(['max' => array_get($options, 'number_max')]);
        }
    }

    protected function setValidates(&$validates){
        $options = $this->custom_column->options;
        
        // value size
        if (array_get($options, 'number_min')) {
            $validates[] = 'min:'.array_get($options, 'number_min');
        }
        if (array_get($options, 'number_max')) {
            $validates[] = 'max:'.array_get($options, 'number_max');
        }

        $validates[] = new Validator\IntegerCommaRule;
    }
}
