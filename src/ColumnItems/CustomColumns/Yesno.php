<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Model\Define;
use Encore\Admin\Grid\Filter;

class Yesno extends CustomItem
{
    use ImportValueTrait;
    
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
        $filter->radio(Define::YESNO_RADIO);
    }
    
    /**
     * replace value for import
     *
     * @param mixed $value
     * @param array $setting
     * @return void
     */
    public function getImportValueOption(){
        return [
            0    => 'NO',
            1    => 'YES',
        ];
    }
}
