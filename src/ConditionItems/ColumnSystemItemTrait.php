<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model\CustomViewFilter;
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

        return array_get(FilterOption::FILTER_OPTIONS(), $column_type);
    }

    /**
     * Get change field
     *
     * @param string $key
     * @param bool $show_condition_key
     * @return \Encore\Admin\Form\Field|null
     */
    public function getChangeField($key, $show_condition_key = true)
    {
        if (!isset($this->target)) {
            return null;
        }

        $value_type = null;

        if (!is_nullorempty($key) && boolval($show_condition_key)) {
            $value_type = FilterOption::VALUE_TYPE($key);

            if ($value_type == 'none') {
                return null;
            }
        }

        // get column item
        $column_item = $this->getFormColumnItem();
        $column_item->options(["changefield" => true]);
        if (isset($this->label)) {
            $column_item->setLabel($this->label);
        }

        return $column_item->getFilterField($value_type);
    }

    protected function getFormColumnItem()
    {
        return CustomViewFilter::getColumnItem($this->target, $this->custom_table)
            ->options([
                'filterKind' => $this->filterKind,
            ]);
    }
}
