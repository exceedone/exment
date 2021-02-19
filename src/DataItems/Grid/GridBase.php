<?php

namespace Exceedone\Exment\DataItems\Grid;

use Encore\Admin\Grid;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Form\Tools\ConditionHasManyTable;

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
            $group_view->filterModel($model, ['sort' => false]);
            $model->where(function ($query) use ($filters) {
                foreach ($filters as $filter) {
                    $filter->setValueFilter($query);
                }
            });
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
    
    
    protected static function setFilterFields(&$form, $custom_table, $is_aggregate = false)
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


    abstract public function grid();
}
