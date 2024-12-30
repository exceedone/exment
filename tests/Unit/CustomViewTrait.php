<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Tests\TestDefine;

trait CustomViewTrait
{
    protected function getCustomViewData(array $options = [], $view_kind_type = ViewKindType::DEFAULT)
    {
        list($custom_view, $data) = $this->getCustomView($options, $view_kind_type);

        return $data;
    }

    protected function createCustomViewAll(array $options = [], $view_kind_type = ViewKindType::DEFAULT)
    {
        $options = array_merge(
            [
                'login_user_id' => TestDefine::TESTDATA_USER_LOGINID_ADMIN,
                'target_table_name' => TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST,
                'condition_join' => 'and',
                'condition_reverse' => '0',
                'filter_settings' => [],
                'column_settings' => [],
                'sort_settings' => [],
                'summary_settings' => [],
            ],
            $options
        );
        $login_user_id = array_get($options, 'login_user_id');
        $target_table_name = array_get($options, 'target_table_name');
        $condition_join = array_get($options, 'condition_join');
        $condition_reverse = array_get($options, 'condition_reverse');
        $filter_settings = array_get($options, 'filter_settings');
        $column_settings = array_get($options, 'column_settings');
        $sort_settings = array_get($options, 'sort_settings');
        $summary_settings = array_get($options, 'summary_settings');

        // Login user.
        $this->be(LoginUser::find($login_user_id));

        $custom_table = CustomTable::getEloquent($target_table_name);

        $custom_view = CustomView::create([
            'custom_table_id' => $custom_table->id,
            'view_view_name' => $custom_table->table_name . '-view-unittest',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => $view_kind_type,
            'options' => [
                'condition_join' => $condition_join?? 'and',
                'condition_reverse' => $condition_reverse?? '0'
            ],
        ]);

        foreach ($column_settings as $index => $column_setting) {
            $custom_view_column = CustomViewColumn::create($this->getViewColumnInfo(
                $custom_table,
                $custom_view,
                $column_setting,
                $index
            ));
        }

        foreach ($summary_settings as $summary_setting) {
            $custom_view_summary = CustomViewSummary::create($this->getViewSummaryInfo(
                $custom_table,
                $custom_view,
                $summary_setting
            ));
        }

        foreach ($filter_settings as $filter_setting) {
            $custom_view_filter = CustomViewFilter::create($this->getViewFilterInfo(
                $custom_table,
                $custom_view,
                $filter_setting
            ));
        }

        foreach ($sort_settings as $sort_setting) {
            $custom_view_filter = CustomViewSort::create($this->getViewSortInfo(
                $custom_table,
                $custom_view,
                $sort_setting
            ));
        }
        return [$custom_table, $custom_view];
    }

    protected function getCustomView(array $options = [], $view_kind_type = ViewKindType::DEFAULT)
    {
        $get_count = array_get($options, 'get_count')?? false;

        list($custom_table, $custom_view) = $this->createCustomViewAll($options, $view_kind_type);

        $query = $custom_table->getValueQuery();
        if ($view_kind_type == ViewKindType::AGGREGATE) {
            $grid = new \Exceedone\Exment\DataItems\Grid\SummaryGrid($custom_table, $custom_view);
            $query = $grid->getQuery($query);
            if (!is_null($offset = array_get($options, 'offset'))) {
                $query->offset($offset);
            }
            if (!is_null($limit = array_get($options, 'limit'))) {
                $query->limit($limit);
            }
            $data = $query->get();
        } else {
            $custom_view->setValueFilters($query);
            $query = $custom_view->getSearchService()->query();
            //$custom_view->filterSortModel($query);
            if ($get_count) {
                $data = $query->count();
            } else {
                $data = $query->get();
            }
        }

        return [$custom_view, $data];
    }

    protected function getTargetColumnId($setting, $custom_table, $is_pivot = false)
    {
        if (!isset($setting['column_name'])) return null;

        if ($setting['column_name'] == SystemColumn::PARENT_ID) {
            $column_id = $is_pivot ? $setting['column_name'] : Define::CUSTOM_COLUMN_TYPE_PARENT_ID;
        } elseif (!isset($setting['condition_type']) || $setting['condition_type'] == ConditionType::COLUMN) {
            $custom_column = CustomColumn::getEloquent($setting['column_name'], $custom_table);
            $column_id = $custom_column->id;
        } else {
            $column_id = SystemColumn::getOption(['name' => $setting['column_name']])['id'];
        }
        return $column_id;
    }

    protected function getViewSummaryInfo($custom_table, $custom_view, $column_setting)
    {
        $options = $this->getViewColumnBase($custom_table, $custom_view, $column_setting);
        $options['view_summary_condition'] = $column_setting['summary_condition'] ?? SummaryCondition::MAX;
        return $options;
    }

    protected function getViewColumnInfo($custom_table, $custom_view, $column_setting, $index)
    {
        $options = $this->getViewColumnBase($custom_table, $custom_view, $column_setting);
        $options['order'] = $column_setting['order']?? $index + 1;
        return $options;
    }

    protected function getViewFilterInfo($custom_table, $custom_view, $column_setting)
    {
        $options = $this->getViewColumnBase($custom_table, $custom_view, $column_setting);
        unset($options['view_column_name']);
        $options['view_filter_condition'] = $column_setting['filter_condition']?? null;
        $options['view_filter_condition_value_text'] = $column_setting['filter_value_text']?? null;
        return $options;
    }

    protected function getViewSortInfo($custom_table, $custom_view, $column_setting)
    {
        $options = $this->getViewColumnBase($custom_table, $custom_view, $column_setting);
        unset($options['view_column_name']);
        $options['sort'] = $column_setting['sort']?? 1;
        $options['priority'] = $column_setting['priority']?? 1;
        return $options;
    }

    protected function getViewColumnBase($custom_table, $custom_view, $column_setting)
    {
        if (isset($column_setting['reference_table'])) {
            $refer_table = CustomTable::getEloquent($column_setting['reference_table']);
            $view_column_table_id = $refer_table->id;
            $view_column_target_id = $this->getTargetColumnId($column_setting, $refer_table);
            if (isset($column_setting['reference_column'])) {
                $column_setting['options']['view_pivot_table_id'] = $custom_table->id;
                $column_setting['options']['view_pivot_column_id'] = $this->getTargetColumnId([
                    'column_name' => $column_setting['reference_column'],
                ], boolval(array_get($column_setting, 'is_refer'))? $refer_table: $custom_table, true);
            }
        } else {
            $view_column_table_id = $custom_table->id;
            $view_column_target_id = $this->getTargetColumnId($column_setting, $custom_table);
        }
        return [
            'custom_view_id' => $custom_view->id,
            'view_column_type' => $column_setting['condition_type'] ?? ConditionType::COLUMN,
            'view_column_table_id' => $view_column_table_id,
            'view_column_target_id' => $view_column_target_id,
            'view_column_name' => $column_setting['view_column_name']?? null,
            'options' => $column_setting['options']?? null,
        ];
    }

    protected function createCustomView($custom_table, $view_type, $view_kind_type, $view_view_name = null, array $options = [])
    {
        return CustomView::create([
            'custom_table_id' => $custom_table->id,
            'view_view_name' => $view_view_name,
            'view_type' => $view_type,
            'view_kind_type' => $view_kind_type,
            'options' => $options,
        ]);
    }

    protected function createCustomViewFilter($custom_view_id, $view_column_type, $view_column_table_id, $view_column_target_id, $view_filter_condition, $view_filter_condition_value_text = null)
    {
        $custom_view_filter = new CustomViewFilter();
        $custom_view_filter->custom_view_id = $custom_view_id;
        $custom_view_filter->view_column_type = $view_column_type;
        $custom_view_filter->view_column_table_id = $view_column_table_id;
        $custom_view_filter->view_column_target_id = $view_column_target_id;
        $custom_view_filter->view_filter_condition = $view_filter_condition;
        $custom_view_filter->view_filter_condition_value_text = $view_filter_condition_value_text;
        $custom_view_filter->save();
        return $custom_view_filter;
    }


    /**
     * Get column id for filter, column, etc
     *
     * @param array $setting
     * @param CustomTable $custom_table
     * @return string
     */
    protected function getColumnId(array $setting, CustomTable $custom_table)
    {
        if (!isset($setting['condition_type']) || $setting['condition_type'] == ConditionType::COLUMN) {
            $custom_column = CustomColumn::getEloquent($setting['column_name'], $custom_table);
            return $custom_column->id;
        } else {
            return SystemColumn::getOption(['name' => $setting['column_name']])['id'];
        }
    }
}
