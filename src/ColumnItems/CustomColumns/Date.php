<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;
use Encore\Admin\Grid\Filter;
use Exceedone\Exment\Form\Field as ExmentField;

class Date extends CustomItem
{
    protected $format = 'Y-m-d';

    public function text()
    {
        // if not empty format, using carbon
        $format = array_get($this->custom_column, 'options.format');
        if (is_nullorempty($format)) {
            $format = array_get($this->options, 'format');
        }
        if (is_nullorempty($format)) {
            $format = $this->getDisplayFormat();
        }
        
        if (!isset($this->value)) {
            return null;
        }

        if (!is_nullorempty($format) && !boolval(array_get($this->options, 'summary'))) {
            return $this->getDateUseValue($format);
        }

        // else, return
        return $this->value();
    }

    protected function getDisplayFormat()
    {
        return config('admin.date_format');
    }

    public function saving()
    {
        if ($this->autoDate()) {
            $this->value = $this->getNowString();
            return $this->value;
        }

        if (!isset($this->value)) {
            return null;
        }

        return $this->getDateUseValue($this->format);
    }

    /**
     * Get date again use format
     *
     * @return void
     */
    protected function getDateUseValue($format)
    {
        if (is_array($this->value())) {
            return (new \Carbon\Carbon(array_get($this->value(), 'date')))->format($format) ?? null;
        }

        return (new \Carbon\Carbon($this->value()))->format($format) ?? null;
    }

    protected function getAdminFieldClass()
    {
        if ($this->displayDate()) {
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
        if ($this->displayDate()) {
            $field->default($this->getNowString());
        } else {
            $field->options(['useCurrent' => false]);
        }
    }
    
    protected function setValidates(&$validates)
    {
        $validates[] = 'date';
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
    protected function autoDate()
    {
        // if datetime_now_saving is true
        if (boolval(array_get($this->custom_column, 'options.datetime_now_saving'))) {
            return true;
        }
        // if not has id(creating) and datetime_now_creating is true
        elseif (!isset($this->id) && boolval(array_get($this->custom_column, 'options.datetime_now_creating'))) {
            return true;
        }
        
        return false;
    }

    /**
     * Whether only display
     *
     * @return void
     */
    protected function displayDate()
    {
        // if datetime_now_saving is true
        return boolval(array_get($this->custom_column, 'options.datetime_now_saving')) || boolval(array_get($this->custom_column, 'options.datetime_now_creating'));
    }

    /**
     * get now string for saving
     *
     * @return string now string
     */
    protected function getNowString()
    {
        return \Carbon\Carbon::now()->format($this->format);
    }

    /**
     * whether column is date
     *
     */
    public function isDate()
    {
        return true;
    }
}
