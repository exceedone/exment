<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;
use Encore\Admin\Grid\Filter;
use Exceedone\Exment\Form\Field as ExmentField;

class Date extends CustomItem
{
    public function text()
    {
        // if not empty format, using carbon
        $format = array_get($this->custom_column, 'options.format');
        if (!is_nullorempty($format)) {
            return (new \Carbon\Carbon($this->value()))->format($format) ?? null;
        }
        // else, return
        return $this->value();
    }

    public function saving(){
        $update = $this->autoDate();

        if($update){
            $this->value = $this->getNowString();
            return $this->value;
        }

        return null;
    }

    protected function getAdminFieldClass()
    {
        if($this->autoDate()){
            return ExmentField\Display::class;
        }
        return Field\Date::class;
    }
    
    protected function getAdminFilterClass()
    {
        return Filter\Between::class;
    }

    protected function setAdminOptions(&$field, $form_column_options)
    {
        if ($this->autoDate()) {
            $field->default(exmtrans('custom_value.auto_number_create'));
        }else{
            $field->options(['useCurrent' => false]);
        }
    }
    
    protected function setAdminFilterOptions(&$filter)
    {
        $filter->date();
    }

    /**
     * Whether this is autodate
     *
     * @return void
     */
    protected function autoDate(){
        // if datetime_now_saving is true
        if(boolval(array_get($this->custom_column, 'options.datetime_now_saving'))){
            return true;
        }
        // if not has id(creating) and datetime_now_creating is true
        elseif(!isset($this->id) && boolval(array_get($this->custom_column, 'options.datetime_now_creating'))){
            return true;
        }
        
        return false;
    }

    /**
     * get now string for saving
     *
     * @return string now string
     */
    protected function getNowString(){
        return \Carbon\Carbon::now()->format('Y-m-d');
    }
}
