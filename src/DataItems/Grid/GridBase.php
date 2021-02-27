<?php

namespace Exceedone\Exment\DataItems\Grid;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Form\Tools\ConditionHasManyTable;
use Exceedone\Exment\Form\Tools;

abstract class GridBase
{
    protected $custom_table;
    protected $custom_view;
    protected $modal = false;
    protected $callback;

    public static function getItem(...$args)
    {
        list($custom_table, $custom_view) = $args + [null, null];

        return new static($custom_table, $custom_view);
    }

    public function modal(bool $modal)
    {
        $this->modal = $modal;

        return $this;
    }

    public function callback($callback)
    {
        $this->callback = $callback;

        return $this;
    }

    public function renderModal($grid)
    {
        return [];
    }
    
    /**
     * Get database query
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param array $options
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder
     */
    public function getQuery($query, array $options = [])
    {
        return $query;
    }

    /**
     * set laravel-admin grid using custom_view
     */
    public function setGrid($grid)
    {
    }

    /**
     * Get callback filter function
     *
     * @return \Closure|null
     */
    public function getCallbackFilter()
    {
        $group_keys = json_decode(request()->query('group_key'));
        if (is_nullorempty($group_keys)) {
            return null;
        }
        $group_view = CustomView::findBySuuid(request()->query('group_view'));
        if (is_nullorempty($group_view)) {
            return null;
        }
        
        // replace view
        $this->custom_view = CustomView::getAllData($this->custom_table);
        $filters = [];
        foreach ($group_keys as $key => $value) {
            $custom_view_column = CustomViewColumn::find($key);
            $custom_view_filter = new CustomViewFilter;
            $custom_view_filter->custom_view_id = $custom_view_column->custom_view_id;
            $custom_view_filter->view_column_type = $custom_view_column->view_column_type;
            $custom_view_filter->view_column_target = $custom_view_column->view_column_target;
            $custom_view_filter->view_group_condition = $custom_view_column->view_group_condition;
            $custom_view_filter->view_filter_condition = FilterOption::EQ;
            $custom_view_filter->view_filter_condition_value_text = $value;
            $filters[] = $custom_view_filter;
            if ($custom_view_filter->view_column_target_id == SystemColumn::WORKFLOW_STATUS()->option()['id']) {
                System::setRequestSession(Define::SYSTEM_KEY_SESSION_WORLFLOW_STATUS_CHECK, true);
            }
            if ($custom_view_filter->view_column_target_id == SystemColumn::WORKFLOW_WORK_USERS()->option()['id']) {
                System::setRequestSession(Define::SYSTEM_KEY_SESSION_WORLFLOW_FILTER_CHECK, true);
            }
        }
        $filter_func = function ($model) use ($filters, $group_view) {
            $group_view->filterModel($model); // sort is false.
            $model->where(function ($query) use ($filters) {
                foreach ($filters as $filter) {
                    $filter->setValueFilter($query);
                }
            });
            return $model;
        };
        return $filter_func;
    }

    
    /**
     * Set filter fileds form
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @param boolean $is_aggregate
     * @return void
     */
    public static function setFilterFields(&$form, $custom_table, $is_aggregate = false)
    {
        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));

        // filter setting
        $hasManyTable = new ConditionHasManyTable($form, [
            'ajax' => admin_url("webapi/{$custom_table->table_name}/filter-value"),
            'name' => "custom_view_filters",
            'linkage' => json_encode(['view_filter_condition' => admin_urls('view', $custom_table->table_name, 'filter-condition')]),
            'targetOptions' => $custom_table->getColumnsSelectOptions(
                [
                    'append_table' => true,
                    'index_enabled_only' => true,
                    'include_parent' => $is_aggregate,
                    'include_child' => $is_aggregate,
                    'include_workflow' => true,
                    'include_workflow_work_users' => true,
                    'ignore_attachment' => true,
                ]
            ),
            'custom_table' => $custom_table,
            'filterKind' => Enums\FilterKind::VIEW,
            'condition_target_name' => 'view_column_target',
            'condition_key_name' => 'view_filter_condition',
            'condition_value_name' => 'view_filter_condition_value',
        ]);

        $hasManyTable->callbackField(function ($field) use ($manualUrl) {
            $field->descriptionHtml(sprintf(exmtrans("custom_view.description_custom_view_filters"), $manualUrl));
        });

        $hasManyTable->render();

        $form->radio('condition_join', exmtrans("condition.condition_join"))
            ->options(exmtrans("condition.condition_join_options"))
            ->default('and');
    }


    /**
     * Set column fields form
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @return void
     */
    public static function setColumnFields(&$form, $custom_table, array $column_options = []){
        // columns setting
        $column_options = array_merge([
            'append_table' => true,
            'include_parent' => true,
            'include_workflow' => true,
        ], $column_options);

        $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_columns"), function ($form) use ($custom_table, $column_options) {
            $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($custom_table->getColumnsSelectOptions($column_options));
            $form->text('view_column_name', exmtrans("custom_view.view_column_name"));
            $form->hidden('order')->default(0);
        })->required()->setTableColumnWidth(7, 3, 2)
        ->rowUpDown('order', 10)
        ->descriptionHtml(exmtrans("custom_view.description_custom_view_columns"));
    }

    
    /**
     * Set sort fileds form
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @param boolean $is_aggregate
     * @return void
     */
    public static function setSortFields(&$form, $custom_table, $is_aggregate = false)
    {
        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));
        
        // sort setting
        $form->hasManyTable('custom_view_sorts', exmtrans("custom_view.custom_view_sorts"), function ($form) use ($custom_table) {
            $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
            ->options($custom_table->getColumnsSelectOptions([
                'append_table' => true,
                'index_enabled_only' => true,
            ]));
            $form->select('sort', exmtrans("custom_view.sort"))->options(Enums\ViewColumnSort::transKeyArray('custom_view.column_sort_options'))
                ->required()
                ->default(1)
                ->help(exmtrans('custom_view.help.sort_type'));
            $form->hidden('priority')->default(0);
        })->setTableColumnWidth(7, 3, 2)
        ->rowUpDown('priority')
        ->descriptionHtml(sprintf(exmtrans("custom_view.description_custom_view_sorts"), $manualUrl));
    }

    /**
     * setTableMenuButton
     *
     * @return void
     */
    protected function setTableMenuButton(&$tools){
        if ($this->custom_table->enableTableMenuButton()) {
            $tools[] = \Exment::getRender(new Tools\CustomTableMenuButton('data', $this->custom_table));
        }
    }

    /**
     * setViewMenuButton
     *
     * @return void
     */
    protected function setViewMenuButton(&$tools){
        if ($this->custom_table->enableViewMenuButton()) {
            $tools[] = \Exment::getRender(new Tools\CustomViewMenuButton($this->custom_table, $this->custom_view));
        }
    }

    /**
     * setNewButton
     *
     * @return void
     */
    protected function setNewButton(&$tools){
        if ($this->custom_table->enableCreate(true) === true) {
            $tools[] = \Exment::getRender(view('exment::custom-value.new-button', ['table_name' => $this->custom_table->table_name]));
        }
    }


    abstract public function grid();
}
