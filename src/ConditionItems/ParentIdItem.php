<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Enums;

class ParentIdItem extends SystemItem
{
    /**
     * Set query sort for custom value's sort
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param CustomViewSort $custom_view_sort
     * @return void
     */
    public function setQuerySort($query, CustomViewSort $custom_view_sort)
    {
        $view_column_target = 'parent_id';
        //set order
        $query->orderby($view_column_target, $custom_view_sort->sort == Enums\ViewColumnSort::ASC ? 'asc' : 'desc');
    }


    /**
     * get select column display text
     *
     * @param Model\CustomViewColumn|Model\CustomViewSummary $custom_view_column
     * @param Model\CustomTable $custom_table
     * @return string|null
     */
    public function getSelectColumnText($custom_view_column, Model\CustomTable $custom_table): ?string
    {
        $column_view_name = array_get($custom_view_column, 'view_column_name');

        $relation = Model\CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $custom_table->id)->first();
        ///// if this table is child relation(1:n), add parent table
        if (isset($relation)) {
            $column_view_name = array_get($relation, 'parent_custom_table.table_view_name');
        }

        return $column_view_name;
    }


    /**
     * get column and table id
     *
     * @return array offset 0 : column id, 1 : table id
     */
    public function getColumnAndTableId($column_name, $custom_table): array
    {
        $target_column_id = Define::CUSTOM_COLUMN_TYPE_PARENT_ID;
        // get parent table
        if (isset($custom_table)) {
            $target_table_id = $custom_table->id;
        }

        return [
            $target_column_id,
            $target_table_id ?? null,
        ];
    }
}
