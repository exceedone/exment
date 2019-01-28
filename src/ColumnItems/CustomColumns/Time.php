<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Encore\Admin\Form\Field;

class Time extends Date
{
    protected function getAdminFieldClass()
    {
        return Field\Time::class;
    }
    
    protected function setAdminFilterOptions(&$filter)
    {
        $filter->time();
    }
}
