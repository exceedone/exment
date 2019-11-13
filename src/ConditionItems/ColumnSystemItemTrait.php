<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Enums\FilterKind;
use Exceedone\Exment\Enums\FilterOption;

trait ColumnSystemItemTrait
{
    public function getFilterOption()
    {
        // get column item
        $column_item = $this->getFormColumnItem();

        ///// get column_type
        $column_type = $column_item->getViewFilterType();

        // if null, return []
        if (!isset($column_type)) {
            return [];
        }

        return array_get($this->filterKind == FilterKind::VIEW ? FilterOption::FILTER_OPTIONS() : FilterOption::FILTER_CONDITION_OPTIONS(), $column_type);
    }

    /**
     * Get change field
     *
     * @param [type] $key
     * @return void
     */
    public function getChangeField($key, $show_condition_key = true)
    {
        if (!isset($this->target)) {
            return null;
        }

        $value_type = null;

        if (isset($key) && boolval($show_condition_key)) {
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

    protected function getFormColumnItem()
    {
        return CustomViewFilter::getColumnItem($this->target)
        ->options([
            'filterKind' => $this->filterKind,
        ]);
    }
}
