<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Encore\Admin\Form\Field;
use Encore\Admin\Grid\Filter;

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
    
    protected function getAdminFilterClass(){
        return Filter\Between::class;
    }

    protected function setAdminOptions(&$field, $form_column_options){
        $field->options(['useCurrent' => false]);
    }
    
    protected function setAdminFilterOptions(&$filter){
        $filter->date();
    }
}
