<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\ViewColumnSort;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Services\Plugin\PluginInstaller;

trait CustomValueSummary
{
    protected function gridSummary()
    {
        $classname = $this->getModelNameDV();
        $grid = new Grid(new $classname);
        PluginInstaller::pluginPreparing($this->plugins, 'loading');

        $this->setSummaryGrid($grid);

        $grid->disableFilter();
        $grid->disableActions();
        $grid->disableRowSelector();
        $grid->disableExport();

        $grid->tools(function (Grid\Tools $tools) use ($grid){
            //$tools->append(new Tools\ExportImportButton($this->custom_table->table_name, $grid, true));
            $tools->append(new Tools\GridChangePageMenu('data', $this->custom_table, false));
            $tools->append(new Tools\GridChangeView($this->custom_table, $this->custom_view));
        });

        PluginInstaller::pluginPreparing($this->plugins, 'loaded');
        return $grid;
    }

    /**
     * set summary grid
     */
    protected function setSummaryGrid($grid) {
        // get target table
        $db_table_name = getDBTableName($this->custom_table);

        $view = $this->custom_view;

        $group_columns = [];
        $select_columns = [];
        $custom_tables = [];
        $index = 0;
        // set grouping columns
        foreach ($view->custom_view_columns as $custom_view_column) {
            $item = $custom_view_column->column_item;

            // first, set group_column. this column's name uses index.
            $group_columns[] = $item->sqlname();

            $this->setSummaryGridItem($item, $index, $grid, $select_columns, $custom_tables);

            $index++;
        }
        // set summary columns
        foreach ($view->custom_view_summaries as $custom_view_summary) {
            $item = $custom_view_summary->column_item;

            $this->setSummaryGridItem($item, $index, $grid, $select_columns, $custom_tables, $custom_view_summary->view_summary_condition);

            $index++;
        }

        // create query
        $query = $grid->model();

        // get join tables
        $relations = CustomRelation::getRelationsByParent($this->custom_table);
        foreach($relations as $relation){
            // if not contains group or select column, continue
            if(!in_array($relation->child_custom_table->id, $custom_tables)){
                continue;
            }

            $child_name = getDBTableName($relation->child_custom_table);
            $query->join($child_name, $db_table_name.'.id', "$child_name.parent_id");
            $query->where("$child_name.parent_type", $this->custom_table->table_name);
        }

        // set filter
        $query = $view->setValueFilter($query, $db_table_name);

        // set sql select columns
        $query->select($select_columns);
 
        // set sql grouping columns
        $query->groupBy($group_columns);
    }

    /**
     * set summary grid item
     */
    protected function setSummaryGridItem($item, $index, &$grid, &$select_columns, &$custom_tables, $summary_condition = null){
        $item->options([
            'summary' => true,
            'summary_condition' => $summary_condition,
            'summary_index' => $index
        ]);

        $grid->column($item->name(), $item->label())
            ->sort($item->sortable())
            ->display(function ($v) use ($index, $item) {
                return $item->setCustomValue($this)->html();
        });

        $select_columns[] = $item->sqlname();

        // set custom_tables
        $custom_tables[] = $item->getCustomTable()->id;
    }
}
