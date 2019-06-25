<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\Field as ExmentField;

class Time extends Date
{
    protected function getAdminFieldClass()
    {
        if ($this->displayDate()) {
            return ExmentField\Display::class;
        }
        return Field\Time::class;
    }
    
    protected function setAdminFilterOptions(&$filter)
    {
        $filter->time();
    }

    /**
     * get now string for saving
     *
     * @return string now string
     */
    protected function getNowString()
    {
        return \Carbon\Carbon::now()->format('H:i:s');
    }
    
    /**
     * whether column is date
     *
     */
    public function isDate()
    {
        return false;
    }
}
