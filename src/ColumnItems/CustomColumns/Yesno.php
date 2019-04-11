<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Form\Field;
use Encore\Admin\Grid\Filter;

class Yesno extends CustomItem
{
    /**
     * laravel-admin set required. if false, always not-set required
     */
    protected $required = false;

    public function text()
    {
        return boolval($this->value) ? 'YES' : 'NO';
    }

    protected function getAdminFieldClass()
    {
        return Field\SwitchBoolField::class;
    }
    
    protected function getAdminFilterClass()
    {
        return Filter\Equal::class;
    }

    protected function setAdminFilterOptions(&$filter)
    {
        $filter->radio([
            ''   => 'All',
            0    => 'NO',
            1    => 'YES',
        ]);
    }
}
