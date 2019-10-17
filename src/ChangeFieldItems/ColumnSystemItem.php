<?php

namespace Exceedone\Exment\ChangeFieldItems;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterOption;

class ColumnSystemItem extends ChangeFieldItem
{
    public function getFilterOption(){
        // get column item
        $column_item = $this->getFormColumnItem();

        ///// get column_type
        $column_type = $column_item->getViewFilterType();

        // if null, return []
        if (!isset($column_type)) {
            return [];
        }

        return array_get(FilterOption::FILTER_OPTIONS(), $column_type);
    }

    /**
     * Get change field
     *
     * @param [type] $target_val
     * @param [type] $key
     * @return void
     */
    public function getChangeField($key){

        if (!isset($this->target)) {
            return null;
        }

        $value_type = null;

        if (isset($key)) {
            $value_type = FilterOption::VALUE_TYPE($key);

            if ($value_type == 'none') {
                return null;
            }
        }
    
        // get column item
        $column_item = $this->getFormColumnItem();
        if (isset($this->label)) {
            $column_item->setLabel($this->label);
        }

        return $column_item->getFilterField($value_type);
    }

    protected function getFormColumnItem(){
        return CustomViewFilter::getColumnItem($this->target)
        ->options([
            //'view_column_target' => true,
        ]);
    }
}
