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

    public function saving()
    {
        if (is_null($this->value)) {
            return 0;
        }
        if (strtolower($this->value) === 'yes') {
            return 1;
        }
        if (strtolower($this->value) === 'no') {
            return 0;
        }
        return boolval($this->value) ? 1 : 0;
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
     * @return void
     */
    public function getImportValueOption()
    {
        return [
            0    => 'NO',
            1    => 'YES',
        ];
    }

    /**
     * Get pure value. If you want to change the search value, change it with this function.
     *
     * @param string $label
     * @return ?string string:matched, null:not matched
     */
    public function getPureValue($label)
    {
        $option = $this->getImportValueOption();

        foreach ($option as $value => $l) {
            if (strtolower($label) == strtolower($l)) {
                return $value;
            }
        }
        return null;
    }
}
