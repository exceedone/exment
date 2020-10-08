<?php

namespace Exceedone\Exment\DataItems\Grid;

use Encore\Admin\Grid;
use Encore\Admin\Form;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\GroupCondition;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Enums\ViewColumnSort;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\DataItems\Grid\Summary\SummaryOption;

class SummaryGrid extends GridBase
{
    public function __construct($custom_table, $custom_view)
    {
        $this->custom_table = $custom_table;
        $this->custom_view = $custom_view;
    }

    public function grid()
    {
        $classname = getModelName($this->custom_table);
        $grid = new Grid(new $classname);
        Plugin::pluginExecuteEvent(PluginEventTrigger::LOADING, $this->custom_table);

        $this->setSummaryGrid($grid);

        $this->setGrid($grid);

        $grid->disableCreateButton();
        $grid->disableFilter();
        //$grid->disableActions();
        $grid->disableRowSelector();
        $grid->disableExport();

        $table_name = $this->custom_table->table_name;
        $custom_view = $this->custom_view;
        $isShowViewSummaryDetail = $this->isShowViewSummaryDetail();
        if (!$isShowViewSummaryDetail) {
            $grid->disableActions();
        }

        $grid->actions(function (Grid\Displayers\Actions $actions) use ($isShowViewSummaryDetail, $custom_view, $table_name) {
            $actions->disableDelete();
            $actions->disableEdit();
            $actions->disableView();

            $params = [];
            foreach ($actions->row->toArray() as $key => $value) {
                $keys = explode('_', $key);
                if (count($keys) == 3 && $keys[1] == ViewKindType::DEFAULT) {
                    $params[$keys[2]] = $value;
                }
            }

            if ($isShowViewSummaryDetail) {
                $linker = (new Grid\Linker)
                ->url(admin_urls_query('data', $table_name, ['view' => CustomView::getAllData($table_name)->suuid, 'group_view' => $custom_view->suuid, 'group_key' => json_encode($params)]))
                ->icon('fa-list')
                ->tooltip(exmtrans('custom_value.view_summary_detail'));
                $actions->prepend($linker);
            }
        });

        // create exporter
        $service = (new DataImportExport\DataImportExportService())
            ->exportAction(new DataImportExport\Actions\Export\SummaryAction(
                [
                    'grid' => $grid,
                    'custom_table' => $this->custom_table,
                    'custom_view' => $this->custom_view,
                    'is_summary' => true,
                ]
            ));
        $grid->exporter($service);
        
        $grid->tools(function (Grid\Tools $tools) use ($grid) {
            // have edit flg
            $edit_flg = $this->custom_table->enableEdit(true) === true;
            if ($edit_flg && $this->custom_table->enableExport() === true) {
                $button = new Tools\ExportImportButton(admin_urls('data', $this->custom_table->table_name), $grid, false, true, false);
                $tools->append($button->setCustomTable($this->custom_table));
            }
            
            // if user have edit permission, add button
            if ($edit_flg) {
                $tools->append(view('exment::custom-value.new-button', ['table_name' => $this->custom_table->table_name]));
            }
            
            if ($this->custom_table->enableTableMenuButton()) {
                $tools->append(new Tools\CustomTableMenuButton('data', $this->custom_table));
            }
            if ($this->custom_table->enableViewMenuButton()) {
                $tools->append(new Tools\CustomViewMenuButton($this->custom_table, $this->custom_view));
            }
        });

        Plugin::pluginExecuteEvent(PluginEventTrigger::LOADED, $this->custom_table);
        return $grid;
    }

    /**
     * set summary grid
     */
    protected function setSummaryGrid($grid)
    {
        $query = $grid->model();
        return $this->getQuery($query, ['grid' => $grid]);
    }


    /**
     * set laravel-admin grid using custom_view
     */
    public function setGrid($grid)
    {
        // set with
        $this->custom_table->setQueryWith($grid->model(), $this->custom_view);
    }


    /**
     * get query for summary
     *
     * @return \Encore\Admin\Grid\Model|\Illuminate\Database\Eloquent\Builder query for summary
     */
    public function getQuery($query, array $options = [])
    {
        $options = array_merge([
            'grid' => null,
        ], $options);
        $grid = $options['grid'];

        $table_name = $this->custom_table->table_name;
        // get table id
        $db_table_name = getDBTableName($this->custom_table);

        // get relation child tables
        $child_relations = CustomRelation::getRelationsByParent($this->custom_table);
        // join table refer to this table as select.
        $selected_table_columns = $this->custom_table->getSelectedTables();

        $group_columns = [];
        $sort_columns = [];
        $summary_options = [];

        // set grouping columns
        $view_column_items = $this->custom_view->getSummaryIndexAndViewColumns();
        foreach ($view_column_items as $view_column_item) {
            $item = array_get($view_column_item, 'item');
            $index = array_get($view_column_item, 'index');
            $column_item = $item->column_item;
            // set order column
            if (!empty(array_get($item, 'sort_order'))) {
                $sort_order = array_get($item, 'sort_order');
                $sort_type = array_get($item, 'sort_type');
                $sort_columns[] = ['key' => $sort_order, 'sort_type' => $sort_type, 'column_name' => "column_$index"];
            }

            // check child item
            $is_child = $child_relations->contains(function ($child_relation, $key) use ($item) {
                return isset($item->custom_table) && $child_relation->child_custom_table_id == $item->custom_table_cache->id;
            }) || in_array($item->custom_table_cache->id, $selected_table_columns);

            if ($item instanceof CustomViewColumn) {
                // first, set group_column. this column's name uses index.
                $column_item->options(['groupby' => true, 'group_condition' => array_get($item, 'view_group_condition'), 'summary_index' => $index, 'is_child' => $is_child]);
                $groupSqlName = $column_item->sqlname();
                $groupSqlAsName = $column_item->sqlAsName();
                $group_columns[] = $is_child ? $groupSqlAsName : $groupSqlName;
                $column_item->options(['groupby' => false, 'group_condition' => null]);

                // parent_id need parent_type
                if ($column_item instanceof \Exceedone\Exment\ColumnItems\ParentItem) {
                    $group_columns[] = $column_item->sqltypename();
                } elseif ($column_item instanceof \Exceedone\Exment\ColumnItems\WorkflowItem) {
                    \Exceedone\Exment\ColumnItems\WorkflowItem::getStatusSubquery($query, $item->custom_table);
                }

                $this->setSummaryItem($column_item, $index, $summary_options, $grid, [
                    'column_label' => array_get($item, 'view_column_name')?? $column_item->label(),
                    'custom_view_column' => $item,
                    'is_child' => $is_child,
                ]);
                
                // if this is child table, set as sub group by
                if ($is_child) {
                    $summary_options[$item->custom_table_cache->id]->addSubGroupby($groupSqlAsName);
                    $summary_options[$item->custom_table_cache->id]->addSelectGroup($groupSqlAsName);
                }
            }
            // set summary columns
            else {
                $this->setSummaryItem($column_item, $index, $summary_options, $grid, [
                    'column_label' => array_get($item, 'view_column_name')?? $column_item->label(),
                    'summary_condition' => $item->view_summary_condition,
                    'is_child' => $is_child,
                ]);
            }
        }

        // set filter columns
        foreach ($this->custom_view->custom_view_filters_cache as $custom_view_filter) {
            $target_table_id = array_get($custom_view_filter, 'view_column_table_id');

            if (array_key_exists($target_table_id, $summary_options)) {
                $summary_options[$target_table_id]->addFilter($custom_view_filter);
            } else {
                $summary_options[$target_table_id] = new SummaryOption([
                    'table_name' => getDBTableName($target_table_id),
                    'filter' => $custom_view_filter
                ]);
            }
        }

        // set relation
        $this->setRelationQuery($query, $summary_options);

        if (count($sort_columns) > 0) {
            $orders = collect($sort_columns)->sortBy('key')->all();
            foreach ($orders as $order) {
                $sort = ViewColumnSort::getEnum(array_get($order, 'sort_type'), ViewColumnSort::ASC)->lowerKey();
                $query->orderBy(array_get($order, 'column_name'), $sort);
            }
        }
        // set sql grouping columns
        $query->groupBy($group_columns);

        return $query;
    }
    

    /**
     * Set relation query. consider for relation 1:n, n:n, select_table
     *
     * @param [type] $query
     * @param array $summary_options use cusrom tables in this query
     * @return void
     */
    protected function setRelationQuery($query, $summary_options)
    {
        $db_table_name = getDBTableName($this->custom_table);

        $custom_table_id = $this->custom_table->id;

        $sub_queries = [];

        // get relation parent tables
        $parent_relations = CustomRelation::getRelationsByChild($this->custom_table);
        // get relation child tables
        $child_relations = CustomRelation::getRelationsByParent($this->custom_table);
        // join select table refered from this table.
        $select_table_columns = $this->custom_table->getSelectTables();
        // join table refer to this table as select.
        $selected_table_columns = $this->custom_table->getSelectedTables();
        
        
        foreach ($summary_options as $table_id => $summary_option) {
            // add select column and filter
            if ($table_id == $custom_table_id) {
                $this->addQuery($query, $db_table_name, $summary_option);
                continue;
            }
            // join parent table
            if ($this->setCustomRelationQueryParent($query, $parent_relations, $table_id, $summary_option, $db_table_name)) {
                continue;
            }

            // create subquery grouping child table
            if ($this->setCustomRelationQueryChildren($query, $child_relations, $table_id, $summary_option, $db_table_name, $sub_queries)) {
                continue;
            }

            // join table refered from target table
            if (in_array($table_id, $select_table_columns)) {
                $column_key = array_search($table_id, $select_table_columns);
                $this->addQuery($query, $db_table_name, $summary_option, $column_key, 'id');
                continue;
            }
            // create subquery grouping table refer to target table
            if (in_array($table_id, $selected_table_columns)) {
                $column_key = array_search($table_id, $selected_table_columns);
                $sub_query = $this->getSubQuery($db_table_name, 'id', $column_key, $summary_option);
                $query->addSelect($summary_option->getSelectGroups());
                $sub_queries[] = $sub_query;
                continue;
            }
        }

        // join subquery
        foreach ($sub_queries as $table_no => $sub_query) {
            //$query->leftjoin(\DB::raw('('.$sub_query->toSql().") As table_$table_no"), $db_table_name.'.id', "table_$table_no.id");
            $alter_name = is_string($table_no)? $table_no : 'table_'.$table_no;
            $query->leftjoin(\DB::raw('('.$sub_query->toSql().") As $alter_name"), $db_table_name.'.id', "$alter_name.id");
            $query->addBinding($sub_query->getBindings(), 'join');
        }
    }

    /**
     * Set custom relation query to parent. consider 1:n or n:n
     *
     * @param [type] $query
     * @param array $parent_relations
     * @param int $table_id
     * @param array $summary_option
     * @param string $db_table_name
     * @return boolean if set, return true
     */
    protected function setCustomRelationQueryParent($query, $parent_relations, $table_id, $summary_option, $db_table_name) : bool
    {
        // join parent table
        $parent_relation = $parent_relations->first(function ($parent_relation) use ($table_id) {
            return $parent_relation->parent_custom_table_id == $table_id;
        });
        if (empty($parent_relation)) {
            return false;
        }

        // 1:n
        if ($parent_relation->relation_type == RelationType::ONE_TO_MANY) {
            $this->addQuery($query, $db_table_name, $summary_option, 'parent_id', 'id');
        }
        // n:n
        else {
            $this->addManyManyQuery($query, $parent_relation, $db_table_name, $summary_option);
        }

        return true;
    }

    
    /**
     * Set custom relation query to children. consider 1:n or n:n
     *
     * @param [type] $query
     * @param array $parent_relations
     * @param int $table_id
     * @param array $summary_option
     * @param string $db_table_name
     * @return boolean if set, return true
     */
    protected function setCustomRelationQueryChildren($query, $child_relations, $table_id, $summary_option, $db_table_name, &$sub_queries) : bool
    {
        // join children table
        $child_relation = $child_relations->first(function ($child_relation) use ($table_id) {
            return $child_relation->child_custom_table_id == $table_id;
        });
        if (empty($child_relation)) {
            return false;
        }

        // 1:n
        if ($child_relation->relation_type == RelationType::ONE_TO_MANY) {
            $sub_query = $this->getSubQuery($db_table_name, 'id', 'parent_id', $summary_option);
        }
        // n:n
        else {
            $sub_query = $this->getManyManySubQuery($child_relation, $db_table_name, $summary_option);
        }

        $query->addSelect($summary_option->getSelectGroups());
        $sub_queries[] = $sub_query;
        return true;
    }


    /**
     * set summary item
     */
    protected function setSummaryItem($item, $index, &$summary_options, $grid, $options = [])
    {
        $options = array_merge(
            [
                'column_label' => null,
                'summary_condition' => null,
                'custom_view_column' => null,
                'is_child' => false,
            ],
            $options
        );
    
        $column_label = $options['column_label'];
        $summary_condition = $options['summary_condition'];
        $custom_view_column = $options['custom_view_column'];

        $item->options([
            'summary' => true,
            'summary_condition' => $summary_condition,
            'summary_index' => $index,
            'disable_currency_symbol' => ($summary_condition == SummaryCondition::COUNT),
            'group_condition' => array_get($custom_view_column, 'view_group_condition'),
        ]);

        $table_id = $item->getCustomTable()->id;
        $db_table_name = getDBTableName($table_id);

        // set sql parts for custom table
        if (!array_key_exists($table_id, $summary_options)) {
            $summary_options[$table_id] = new SummaryOption([ 'table_name' => $db_table_name ]);
        }

        $summary_options[$table_id]->addSelect($item->sqlname());
        if ($item instanceof \Exceedone\Exment\ColumnItems\ParentItem) {
            $summary_options[$table_id]->addSelect($item->sqltypename());
        }

        // if has sumamry condition, set select
        if (isset($summary_condition)) {
            if ($options['is_child']) {
                // if child, set as normal sql name
                $summary_options[$table_id]->addSelectGroup($item->sqlAsName());
            } else {
                // if not child, set as group name(ex. count, sum, max)
                $summary_options[$table_id]->addSelectGroup($item->getGroupName());
            }
        }
        
        if (isset($grid)) {
            $grid->column("column_".$index, $column_label)
            ->sort($item->sortable())
            ->display(function ($id, $column, $custom_value) use ($item) {
                $option = SystemColumn::getOption(['name' => $item->name()]);
                if (array_get($option, 'type') == 'user') {
                    return esc_html(getUserName($id));
                } else {
                    return $item->setCustomValue($custom_value)->html();
                }
            });
        }
    }

    /**
     * add select column and filter and join table to main query
     */
    protected function addQuery(&$query, $table_main, $summary_option, $key_main = null, $key_sub = null)
    {
        $table_name = $summary_option->getTableName();

        if ($table_name != $table_main) {
            $query->join($table_name, "$table_main.$key_main", "$table_name.$key_sub");
            $query->whereNull("$table_name.deleted_at");
        }

        $query->addSelect($summary_option->getSelects());
        foreach ($summary_option->getFilters() as $filter) {
            $filter->setValueFilter($query, $table_name, $this->custom_view->filter_is_or);
        }
    }
    
    /**
     * add query for n:n relation. join child to parent.
     */
    protected function addManyManyQuery(&$query, $relation, $db_table_name, $summary_option)
    {
        $pivot_table_name = $relation->getRelationName();

        // join to pivot rable
        $query->join($pivot_table_name, "$pivot_table_name.child_id", "$db_table_name.id");

        // join to relation rable
        $parent_table_name = getDBTableName($relation->parent_custom_table_id);
        
        $query->join($parent_table_name, "$parent_table_name.id", "$pivot_table_name.parent_id")
            ->whereNull("$parent_table_name.deleted_at");

        $query->addSelect($summary_option->getSelects());
        foreach ($summary_option->getFilters() as $filter) {
            $filter->setValueFilter($query, $summary_option->getTableName(), $this->custom_view->filter_is_or);
        }
    }


    /**
     * add select column and filter and join table to sub query
     */
    protected function getSubQuery($table_main, $key_main, $key_sub, $summary_option)
    {
        $child_table_name = $summary_option->getTableName();
        // get subquery groupbys
        $groupBy = $summary_option->getSelectGroupBys();
        $groupBy[] = "$child_table_name.$key_sub";

        $sub_query = \DB::table($table_main)
            ->select("$child_table_name.$key_sub as id")
            ->join($child_table_name, "$table_main.$key_main", "$child_table_name.$key_sub")
            ->whereNull("$child_table_name.deleted_at")
            ->groupBy($groupBy);

        $sub_query->addSelect($summary_option->getSelects());
        
        $custom_filter = $summary_option->getFilters();
        $sub_query->where(function ($query) use ($child_table_name, $custom_filter) {
            foreach ($custom_filter as $filter) {
                $filter->setValueFilter($query, $child_table_name, $this->custom_view->filter_is_or);
            }
        });

        return $sub_query;
    }


    /**
     * add sub query for n:n relation. join parent to child
     */
    protected function getManyManySubQuery($relation, $db_table_name, $summary_option)
    {
        $pivot_table_name = $relation->getRelationName();
        $child_table_name = $summary_option->getTableName();

        // get subquery groupbys
        $groupBy = $summary_option->getSelectGroupBys();
        $groupBy[] = "$db_table_name.id";

        $sub_query = \DB::table($db_table_name);

        // join to pivot rable
        $sub_query->join($pivot_table_name, "$pivot_table_name.parent_id", "$db_table_name.id");

        // join to relation rable
        $sub_query->join($child_table_name, "$child_table_name.id", "$pivot_table_name.child_id");

        $sub_query->whereNull("$child_table_name.deleted_at")
            ->select("$db_table_name.id as id")
            ->groupBy($groupBy);

        $sub_query->addSelect($summary_option->getSelects());
    
        $custom_filter = $summary_option->getFilters();
        $sub_query->where(function ($query) use ($child_table_name, $custom_filter) {
            foreach ($custom_filter as $filter) {
                $filter->setValueFilter($query, $child_table_name, $this->custom_view->filter_is_or);
            }
        });
    
        return $sub_query;
    }



    protected function isShowViewSummaryDetail()
    {
        return !$this->custom_view->custom_view_columns->contains(function ($custom_view_column) {
            return $this->custom_table->id != $custom_view_column->view_column_table_id;
        }) && !$this->custom_view->custom_view_summaries->contains(function ($custom_view_summary) {
            return $this->custom_table->id != $custom_view_summary->view_column_table_id;
        }) && !$this->custom_view->custom_view_filters->contains(function ($custom_view_filter) {
            return $this->custom_table->id != $custom_view_filter->view_column_table_id;
        });
    }


    /**
     * Set custom view columns( group columns )form. For controller.
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @return void
     */
    public static function setViewForm($view_kind_type, $form, $custom_table)
    {
        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));
        
        // group columns setting
        $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_groups"), function ($form) use ($custom_table) {
            $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($custom_table->getColumnsSelectOptions([
                    'append_table' => true,
                    'index_enabled_only' => true,
                    'include_parent' => true,
                    'include_child' => true,
                    'include_workflow' => true,
                ]))
                ->attribute([
                    'data-linkage' => json_encode(['view_group_condition' => admin_urls('view', $custom_table->table_name, 'group-condition')]),
                    'data-change_field_target' => 'view_column_target',
                ]);
            
            $form->text('view_column_name', exmtrans("custom_view.view_column_name"))->icon(null);

            $form->select('view_group_condition', exmtrans("custom_view.view_group_condition"))
                ->options(function ($val, $form) {
                    if (is_null($data = $form->data())) {
                        return [];
                    }
                    if (is_null($view_column_target = array_get($data, 'view_column_target'))) {
                        return [];
                    }
                    return collect(SummaryGrid::getGroupCondition($view_column_target))->pluck('text', 'id')->toArray();
                });

            $form->select('sort_order', exmtrans("custom_view.sort_order"))
                ->options(array_merge([''], range(1, 5)))
                ->help(exmtrans('custom_view.help.sort_order_summaries'));
            $form->select('sort_type', exmtrans("custom_view.sort"))
            ->help(exmtrans('custom_view.help.sort_type'))
                ->options(Enums\ViewColumnSort::transKeyArray('custom_view.column_sort_options'))
                ->config('allowClear', false)->default(Enums\ViewColumnSort::ASC);
                
            $form->hidden('order')->default(0);
        })->required()->rowUpDown('order')->setTableColumnWidth(4, 2, 2, 1, 2, 1)
        ->descriptionHtml(sprintf(exmtrans("custom_view.description_custom_view_groups"), $manualUrl));

        // summary columns setting
        $form->hasManyTable('custom_view_summaries', exmtrans("custom_view.custom_view_summaries"), function ($form) use ($custom_table) {
            $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($custom_table->getSummaryColumnsSelectOptions())
                ->attribute(['data-linkage' => json_encode(['view_summary_condition' => admin_urls('view', $custom_table->table_name, 'summary-condition')])]);
            $form->select('view_summary_condition', exmtrans("custom_view.view_summary_condition"))
                ->options(function ($val, $form) {
                    $view_column_target = array_get($form->data(), 'view_column_target');
                    if (isset($view_column_target)) {
                        $columnItem = CustomViewColumn::getColumnItem($view_column_target);
                        if (isset($columnItem)) {
                            // only numeric
                            if ($columnItem->isNumeric()) {
                                $options = SummaryCondition::getOptions();
                            } else {
                                $options = SummaryCondition::getOptions(['numeric' => false]);
                            }

                            return array_map(function ($array) {
                                return exmtrans('custom_view.summary_condition_options.'.array_get($array, 'name'));
                            }, $options);
                        }
                    }
                    return [];
                })
                ->required()->rules('summaryCondition');
            $form->text('view_column_name', exmtrans("custom_view.view_column_name"))->icon(null);
            $form->select('sort_order', exmtrans("custom_view.sort_order"))
                ->help(exmtrans('custom_view.help.sort_order_summaries'))
                ->options(array_merge([''], range(1, 5)));
            $form->select('sort_type', exmtrans("custom_view.sort"))
                ->help(exmtrans('custom_view.help.sort_type'))
                ->options(Enums\ViewColumnSort::transKeyArray('custom_view.column_sort_options'))
                ->config('allowClear', false)->default(Enums\ViewColumnSort::ASC);
        })->setTableColumnWidth(4, 2, 2, 1, 2, 1)
        ->descriptionHtml(sprintf(exmtrans("custom_view.description_custom_view_summaries"), $manualUrl));

        // filter setting
        static::setFilterFields($form, $custom_table, true);
    }

    
    /**
     * get group condition
     */
    public static function getGroupCondition($view_column_target = null)
    {
        if (!isset($view_column_target)) {
            return [];
        }

        // get column item from $view_column_target
        $columnItem = CustomViewColumn::getColumnItem($view_column_target);
        if (!isset($columnItem)) {
            return [];
        }

        if (!$columnItem->isDate()) {
            return [];
        }

        // if date, return option
        $options = GroupCondition::getOptions();
        return collect($options)->map(function ($array) {
            return ['id' => array_get($array, 'id'), 'text' => exmtrans('custom_view.group_condition_options.'.array_get($array, 'name'))];
        });
    }
}
