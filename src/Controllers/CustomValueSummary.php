<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Grid;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomRelation;
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

        $grid->tools(function (Grid\Tools $tools) use ($grid) {
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
    protected function setSummaryGrid($grid)
    {
        // get target table
        $db_table_name = getDBTableName($this->custom_table);

        $view = $this->custom_view;

        $custom_table_id = $this->custom_table->id;

        $group_columns = [];
        $custom_tables = [];
        $index = 0;
        // set grouping columns
        foreach ($view->custom_view_columns as $custom_view_column) {
            $item = $custom_view_column->column_item;

            // first, set group_column. this column's name uses index.
            $group_columns[] = $item->sqlname();
            // parent_id need parent_type
            if ($item instanceof \Exceedone\Exment\ColumnItems\ParentItem) {
                $group_columns[] = $item->sqltypename();
            }
            $column_label = array_get($custom_view_column, 'view_column_name')?? $item->label();
            $this->setSummaryGridItem($item, $index, $column_label, $grid, $custom_tables);

            $index++;
        }
        // set summary columns
        foreach ($view->custom_view_summaries as $custom_view_summary) {
            $item = $custom_view_summary->column_item;
            $column_label = array_get($custom_view_summary, 'view_column_name')?? $item->label();

            $this->setSummaryGridItem($item, $index, $column_label, $grid, $custom_tables, $custom_view_summary->view_summary_condition);

            $index++;
        }

        // create query
        $query = $grid->model();

        // set filter columns
        foreach ($view->custom_view_filters as $custom_view_filter) {
            $target_table_id = array_get($custom_view_filter, 'view_column_table_id');

            if (array_key_exists($target_table_id, $custom_tables)) {
                $custom_tables[$target_table_id]['filter'][] = $custom_view_filter;
            } else {
                $custom_tables[$target_table_id] = [
                    'table_name' => getDBTableName($target_table_id),
                    'filter' => [$custom_view_filter]
                ];
            }
        }

        $sub_queries = [];

        // get relation parent tables
        $parent_relations = CustomRelation::getRelationsByChild($this->custom_table);
        // get relation child tables
        $child_relations = CustomRelation::getRelationsByParent($this->custom_table);
        // join select table refered from this table.
        $select_table_columns = $this->custom_table->getSelectTables();
        // join table refer to this table as select.
        $selected_table_columns = $this->custom_table->getSelectedTables();

        foreach ($custom_tables as $table_id => $custom_table) {
            // add select column and filter
            if ($table_id == $custom_table_id) {
                $this->addQuery($query, $db_table_name, $custom_table);
                continue;
            }
            // join parent table
            if ($parent_relations->contains(function ($value, $key) use ($table_id) {
                return $value->parent_custom_table->id == $table_id;
            })) {
                $this->addQuery($query, $db_table_name, $custom_table, 'parent_id', 'id');
                continue;
            }
            // create subquery grouping child table
            if ($child_relations->contains(function ($value, $key) use ($table_id) {
                return $value->child_custom_table->id == $table_id;
            })) {
                $sub_query = $this->getSubQuery($db_table_name, 'id', 'parent_id', $custom_table);
                if (array_key_exists('select_group', $custom_table)) {
                    $query->addSelect($custom_table['select_group']);
                }
                $sub_queries[] = $sub_query;
                continue;
            }
            // join table refered from target table
            if (in_array($table_id, $select_table_columns)) {
                $column_key = array_search($table_id, $select_table_columns);
                $this->addQuery($query, $db_table_name, $custom_table, $column_key, 'id');
                continue;
            }
            // create subquery grouping table refer to target table
            if (in_array($table_id, $selected_table_columns)) {
                $column_key = array_search($table_id, $selected_table_columns);
                $sub_query = $this->getSubQuery($db_table_name, 'id', $column_key, $custom_table);
                if (array_key_exists('select_group', $custom_table)) {
                    $query->addSelect($custom_table['select_group']);
                }
                $sub_queries[] = $sub_query;
                continue;
            }
        }

        // join subquery
        foreach ($sub_queries as $table_no => $sub_query) {
            $query->leftjoin(\DB::raw('('.$sub_query->toSql().") As table_$table_no"), $db_table_name.'.id', "table_$table_no.id");
            $query->mergeBindings($sub_query);
        }
        // set sql grouping columns
        $query->groupBy($group_columns);
    }

    /**
     * add select column and filter and join table to main query
     */
    protected function addQuery(&$query, $table_main, $custom_table, $key_main = null, $key_sub = null)
    {
        $table_name = array_get($custom_table, 'table_name');
        if ($table_name != $table_main) {
            $query->join($table_name, "$table_main.$key_main", "$table_name.$key_sub");
            $query->whereNull("$table_name.deleted_at");
        }
        if (array_key_exists('select', $custom_table)) {
            $query->addSelect($custom_table['select']);
        }
        if (array_key_exists('filter', $custom_table)) {
            foreach ($custom_table['filter'] as $filter) {
                $filter->setValueFilter($query, $table_name);
            }
        }
    }
    /**
     * add select column and filter and join table to sub query
     */
    protected function getSubQuery($table_main, $key_main, $key_sub, $custom_table)
    {
        $table_name = array_get($custom_table, 'table_name');
        $sub_query = \DB::table($table_name)
            ->select("$table_name.$key_sub as id")
            ->whereNull("$table_name.deleted_at")
            ->groupBy("$table_name.$key_sub");
        if (array_key_exists('select', $custom_table)) {
            $sub_query->addSelect($custom_table['select']);
        }
        if (array_key_exists('filter', $custom_table)) {
            foreach ($custom_table['filter'] as $filter) {
                $filter->setValueFilter($sub_query, $table_name);
            }
        }
        return $sub_query;
    }

    /**
     * set summary grid item
     */
    protected function setSummaryGridItem($item, $index, $column_label, &$grid, &$custom_tables, $summary_condition = null)
    {
        $item->options([
            'summary' => true,
            'summary_condition' => $summary_condition,
            'summary_index' => $index,
            'disable_currency_symbol' => ($summary_condition == SummaryCondition::COUNT)
        ]);

        $grid->column('column_'.$index, $column_label)
            ->sort($item->sortable())
            ->display(function ($id) use ($item) {
                $option = SystemColumn::getOption(['name' => $item->name()]);
                if (array_get($option, 'type') == 'user') {
                    return esc_html(getUserName($id));
                } else {
                    return $item->setCustomValue($this)->html();
                }
            });

        $table_id = $item->getCustomTable()->id;
        $db_table_name = getDBTableName($table_id);

        // set sql parts for custom table
        if (!array_key_exists($table_id, $custom_tables)) {
            $custom_tables[$table_id] = [ 'table_name' => $db_table_name ];
        }

        $custom_tables[$table_id]['select'][] = $item->sqlname();
        if ($item instanceof \Exceedone\Exment\ColumnItems\ParentItem) {
            $custom_tables[$table_id]['select'][] = $item->sqltypename();
        }

        if (isset($summary_condition)) {
            $custom_tables[$table_id]['select_group'][] = $item->getGroupName();
        }
    }
}
