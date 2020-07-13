<?php

namespace Exceedone\Exment\DataItems\Grid;

use Encore\Admin\Grid;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemColumn;

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
        
        // save summary view
        $custom_view = $this->custom_view;
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
        $filter_func = function ($model) use ($filters, $custom_view) {
            $model->where(function ($query) use ($filters) {
                foreach ($filters as $filter) {
                    $filter->setValueFilter($query);
                }
            })->where(function ($query) use ($custom_view) {
                $custom_view->setValueFilters($query);
            });
            return $model;
        };
        return $filter_func;
    }

    abstract public function grid();
}
