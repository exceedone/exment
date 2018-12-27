<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\ViewColumnType;
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
        $grid->disableCreateButton();
        $grid->disableActions();
        $grid->disableRowSelector();
        $grid->disableExport();

        $grid->tools(function (Grid\Tools $tools) use ($grid){
            //$tools->append(new Tools\ExportImportButton($this->custom_table->table_name, $grid, true));
            $tools->append(new Tools\GridChangeView($this->custom_table, $this->custom_view));
        });

        PluginInstaller::pluginPreparing($this->plugins, 'loaded');
        return $grid;
    }
    protected function setSummaryGrid($grid) {
        // get target table
        $table_name = $this->custom_table->table_name;
        $table_id = getDBTableName($table_name);

        $view = $this->custom_view;
        $query = $grid->model();

        // get join tables
        $relations = CustomRelation::getRelationsByParent($table_name);
        foreach($relations as $relation){
            $child_name = getDBTableName($relation->child_custom_table);
            $query->join($child_name, $table_id.'.id', "$child_name.parent_id");
            $query->where("$child_name.parent_type", $table_name);
        }

        // set filter
        $query = $view->setValueFilter($query, $table_id);
        // // whereはcustom_viewのfilterで実施する
        // $column = new CustomColumn;
        // $query->where($column->getIndexColumnName(), 'aaaa');

        $group_columns = [];
        $select_columns = [];
        $grid_columns = [];
        $index = 0;
        
        // set grouping columns
        foreach ($view->custom_view_columns as $custom_view_column) {
            $view_column_type = array_get($custom_view_column, 'view_column_type');
            if ($view_column_type == ViewColumnType::COLUMN) {
                $column = $custom_view_column->custom_column;
                if(!isset($column)){
                    continue;
                }
                // get virtual column name
                $column_name = $column->getIndexColumnName();

                $group_columns[] = $column_name;
                $select_columns[] = "$column_name as column_$index";
                $grid_columns[] = $column->id;
                $index++;
            }
        }
        // set summary columns
        foreach ($view->custom_view_summaries as $custom_view_summary) {
            $column_id = $custom_view_summary->view_column_target_id;
            $column = CustomColumn::find($column_id);
            if (!isset($column)) {
                continue;
            }
            $column_table_name = getDBTableName($column->custom_table);
            $column_name = $column->column_name;
            $summary = 'sum';
            switch($custom_view_summary->view_summary_condition) {
                case 1:
                    $summary = 'sum';
                    break;
                case 2:
                    $summary = 'avg';
                    break;
                case 3:
                    $summary = 'count';
                    break;
            }
            $select_columns[] = \DB::raw("$summary($column_table_name.value->'$.$column_name') AS column_$index");
            $grid_columns[] = $column_id;
            $index++;
        }
 
        // set sql select columns
        $query->select($select_columns);
 
        // set sql grouping columns
        $query->groupBy($group_columns);

        foreach ($grid_columns as $index => $column_id) {
            $column = CustomColumn::find($column_id);
            $grid->column("column_$index", $column->column_view_name)->display(function ($v) use ($column, $index) {
                if (is_null($this)) {
                    return '';
                }
                $val = array_get($this, "column_$index");
                return esc_html($this->editValue($column, $val, true));
            });
        }

    }
}
