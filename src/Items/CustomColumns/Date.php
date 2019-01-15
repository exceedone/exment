<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Encore\Admin\Form\Field;

class Date extends CustomItem 
{
    public function text(){
        // if not empty format, using carbon
        $format = array_get($this->custom_column, 'options.format');
        if (!is_nullorempty($format)) {
            return (new \Carbon\Carbon($this->value()))->format($format) ?? null;
        }
        // else, return
        return $this->value();
    }

    protected function getAdminFieldClass(){
        return Field\Date::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options){
        $field->options(['useCurrent' => false]);
    }
}
