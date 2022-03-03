<?php

namespace Exceedone\Exment\Database\Schema\Grammars;

use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Enums\ConditionType;

trait GrammarTrait
{
    /**
     * Get creating custom view's index info
     *
     * @param CustomView $custom_view
     * @return array
     */
    public function getCustomViewIndexColumnInfo(CustomView $custom_view) : array
    {
        $custom_table = $custom_view->custom_table;
        $db_table_name = $this->wrapTable(getDBTableName($custom_table));
        
        // get sort and filter columns
        $columnsFunc = function($custom_view_childs){
            return $custom_view_childs
            ->filter(function($custom_view_child){
                return ($custom_view_child->view_column_type == ConditionType::COLUMN || $custom_view_child->view_column_type == ConditionType::SYSTEM);
            })->map(function($custom_view_child){
                $index = $custom_view_child->column_item->index();
                if($custom_view_child instanceof CustomViewSort && $custom_view_child->sort == -1){
                    $index .= ' desc';
                }
                return $index;
            });
        };
        $custom_view_filter_columns = $columnsFunc($custom_view->custom_view_filters);
        $custom_view_sort_columns = $columnsFunc($custom_view->custom_view_sorts);

        return [
            'custom_table' => $custom_table,
            'db_table_name' => $db_table_name,
            'pure_db_table_name' => getDBTableName($custom_table),
            'custom_view_filter_columns' => $custom_view_filter_columns,
            'custom_view_sort_columns' => $custom_view_sort_columns,
            'custom_view_filter_indexname' => $custom_view->getIndexNameFilter(),
            'custom_view_sort_indexname' => $custom_view->getIndexNameSort(),

            'has_filter_columns' => count($custom_view_filter_columns) > 0,
            'has_sort_columns' => count($custom_view_sort_columns) > 0,
        ];
    }
}
