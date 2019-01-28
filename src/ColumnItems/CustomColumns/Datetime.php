<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Encore\Admin\Form\Field;

class Datetime extends Date
{
    protected function getAdminFieldClass()
    {
        return Field\DateTime::class;
    }
    
    protected function setAdminFilterOptions(&$filter)
    {
        $filter->datetime();
    }
}
