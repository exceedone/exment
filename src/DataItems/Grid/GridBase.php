<?php

namespace Exceedone\Exment\DataItems\Grid;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomColumn;
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

        /** Unsafe usage of new static(). */
        /** @phpstan-ignore-next-line */
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
        $group_keys = json_decode_ex(request()->query('group_key'));
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
            $custom_view_column = CustomViewColumn::findByCkey($key);
            $custom_view_filter = new CustomViewFilter();
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
            $filter_raws = [];
            foreach ($filters as $filter) {
                if (isset($filter->view_group_condition)) {
                    $filter_raws[] = $filter;
                } else {
                    $group_view->custom_view_filters->push($filter);
                }
            }
            $group_view->filterModel($model);
            foreach ($filter_raws as $filter_raw) {
                $column_item = $filter_raw->column_item;
                $value_table_column = $column_item->getTableColumn();
                $column = \DB::getQueryGrammar()->getDateFormatString($filter_raw->view_group_condition, $value_table_column);
                $query_value = $column_item->convertFilterValue($filter_raw->view_filter_condition_value_text);
                $model->whereRaw("$column = '$query_value'");
            }
            return $model;
        };
        return $filter_func;
    }


    protected static function setViewInfoboxFields(&$form)
    {
        // view input area ----------------------------------------------------
        $form->switchbool('use_view_infobox', exmtrans("custom_view.use_view_infobox"))
            ->help(exmtrans("custom_view.help.use_view_infobox"))
            ->default(false)
            ->attribute(['data-filtertrigger' =>true]);

        $form->text('view_infobox_title', exmtrans("custom_view.view_infobox_title"))
            ->help(exmtrans("custom_view.help.view_infobox_title"))
            ->attribute(['data-filter' => json_encode(['key' => 'use_view_infobox', 'value' => '1'])]);

        $form->tinymce('view_infobox', exmtrans("custom_view.view_infobox"))
            ->help(exmtrans("custom_view.help.view_infobox"))
            ->disableImage()
            ->attribute(['data-filter' => json_encode(['key' => 'use_view_infobox', 'value' => '1'])]);
    }

    protected static function convertGroups($targetOptions, $defaultCustomTable)
    {
        $options = collect($targetOptions)->mapToDictionary(function ($item, $query) {
            $keys = preg_split('/\?/', $query, 2);
            $items = preg_split('/\:/', $item);
            return [$keys[1] => [$query => trim($items[count($items)-1])]];
        })->map(function ($item, $key) use ($defaultCustomTable) {
            if (empty($key)) {
                $label = $defaultCustomTable->table_view_name;
            } else {
                parse_str($key, $view_column_query_array);
                $column_table_id = array_get($view_column_query_array, 'table_id', $defaultCustomTable->id ?? null);
                $view_pivot_column_id = array_get($view_column_query_array, 'view_pivot_column_id');
                $view_pivot_table_id = array_get($view_column_query_array, 'view_pivot_table_id');
                $label = CustomTable::getEloquent($column_table_id)->table_view_name;
                if (isset($view_pivot_column_id) && !is_nullorempty($view_pivot_column = CustomColumn::getEloquent($view_pivot_column_id))) {
                    $label .= ' : ' . $view_pivot_column->column_view_name;
                }
            }
            return [
                'label' => $label,
                'options' => call_user_func_array("array_merge", $item)
            ];
        })->toArray();
        return $options;
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
        $targetOptions = $custom_table->getColumnsSelectOptions(
            [
                'append_table' => true,
                'index_enabled_only' => true,
                'include_parent' => true,
                'include_child' => $is_aggregate,
                'include_workflow' => true,
                'include_workflow_work_users' => true,
                'ignore_attachment' => true,
                'ignore_many_to_many' => true,
                'ignore_multiple_refer' => true,
            ]
        );
        if (boolval(config('exment.form_column_option_group', false))) {
            $targetGroups = static::convertGroups($targetOptions, $custom_table);
        }

        // filter setting
        $hasManyTable = new ConditionHasManyTable($form, [
            'ajax' => admin_url("webapi/{$custom_table->table_name}/filter-value"),
            'name' => "custom_view_filters",
            'linkage' => json_encode(['view_filter_condition' => admin_urls('view', $custom_table->table_name, 'filter-condition')]),
            'targetOptions' => $targetOptions,
            'targetGroups' => $targetGroups ?? null,
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
    public static function setColumnFields(&$form, $custom_table, array $column_options = [])
    {
        // columns setting
        $column_options = array_merge([
            'append_table' => true,
            'include_parent' => true,
            'include_workflow' => true,
        ], $column_options);

        $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_columns"), function ($form) use ($custom_table, $column_options) {
            $targetOptions = $custom_table->getColumnsSelectOptions($column_options);

            $field = $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($targetOptions);

            if (boolval(config('exment.form_column_option_group', false))) {
                $targetGroups = static::convertGroups($targetOptions, $custom_table);
                $field->groups($targetGroups);
            }

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
     * @param boolean $include_parent
     * @return void
     */
    public static function setSortFields(&$form, $custom_table, $include_parent = false)
    {
        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));

        // sort setting
        $form->hasManyTable('custom_view_sorts', exmtrans("custom_view.custom_view_sorts"), function ($form) use ($custom_table, $include_parent) {
            $targetOptions = $custom_table->getColumnsSelectOptions([
                'append_table' => true,
                'index_enabled_only' => true,
                'include_parent' => $include_parent,
                'ignore_multiple' => true,
                'ignore_many_to_many' => true,
            ]);

            $field = $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($targetOptions);

            if (boolval(config('exment.form_column_option_group', false))) {
                $targetGroups = static::convertGroups($targetOptions, $custom_table);
                $field->groups($targetGroups);
            }

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
    protected function setTableMenuButton(&$tools)
    {
        if ($this->custom_table->enableTableMenuButton()) {
            $tools[] = \Exment::getRender(new Tools\CustomTableMenuButton('data', $this->custom_table));
        }
    }

    /**
     * setViewMenuButton
     *
     * @return void
     */
    protected function setViewMenuButton(&$tools)
    {
        if ($this->custom_table->enableViewMenuButton()) {
            $tools[] = \Exment::getRender(new Tools\CustomViewMenuButton($this->custom_table, $this->custom_view));
        }
    }

    /**
     * setNewButton
     *
     * @return void
     */
    protected function setNewButton(&$tools)
    {
        if ($this->custom_table->enableCreate(true) === true) {
            $tools[] = \Exment::getRender(view('exment::custom-value.new-button', ['table_name' => $this->custom_table->table_name]));
        }
    }


    abstract public function grid();
}
