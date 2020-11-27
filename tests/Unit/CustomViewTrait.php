<?php
namespace Exceedone\Exment\Tests\Unit;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Tests\TestDefine;

trait CustomViewTrait
{
    /**
     * Get custom view
     *
     * @param array $options
     * @return void
     */
    protected function getCustomView(array $options = []) : CustomView
    {
        $options = array_merge(
            [
                'target_table_name' => null,
                'condition_join' => 'and',
                'filter_settings' => [],
            ], 
            $options
        );

        $custom_table = CustomTable::getEloquent($options['target_table_name'] ?? TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);

        // create custom view
        $custom_view = $this->createCustomView(
            $custom_table, 
            ViewType::SYSTEM, 
            ViewKindType::DEFAULT, 
            $custom_table->table_name . '-view-unittest', 
            ['condition_join' => $options['condition_join'] ?? 'and']
        );

        foreach ($options['filter_settings'] as $filter_setting)
        {
            $column_id = $this->getColumnId($filter_setting, $custom_table);

            $custom_view_filter = $this->createCustomViewFilter(
                $custom_view->id,
                $filter_setting['condition_type'] ?? ConditionType::COLUMN,
                $custom_table->id,
                $column_id,
                $filter_setting['filter_condition'],
                $filter_setting['filter_value_text'] ?? null,
            );
        }

        return $custom_view;
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
        $custom_view_filter = new CustomViewFilter;
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
