<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\Field as ExmentField;

class Datetime extends Date
{
    protected function getAdminFieldClass()
    {
        if ($this->displayDate()) {
            return ExmentField\Display::class;
        }
        return Field\Datetime::class;
    }
    
    protected function setAdminFilterOptions(&$filter)
    {
        $filter->datetime();
    }
    
    /**
     * get now string for saving
     *
     * @return string now string
     */
    protected function getNowString()
    {
        return \Carbon\Carbon::now()->format('Y-m-d H:i:s');
    }
}
