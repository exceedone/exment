<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Encore\Admin\Grid;


/**
 * Custom view include parent or select table column test.
 */
class ViewWithParentTest extends TestCase
{
    use TestTrait, CustomViewTrait, DatabaseTransactions;

    protected function init()
    {
        $this->initAllTest();
//        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    /**
     * CustomViewColumn = parent : YES
     * CustomViewFilter = parent : YES
     */
    public function testFuncBothParent()
    {
        $this->init();

        $column_settings = [[
            'condition_type' => ConditionType::SYSTEM,
            'column_name' => 'id',
        ], [
            'column_name' => 'text',
        ], [
            'condition_type' => ConditionType::SYSTEM,
            'column_name' => 'id',
            'reference_table' => TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE,
            'reference_column' => SystemColumn::PARENT_ID,
        ], [
            'column_name' => 'text',
            'reference_table' => TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE,
            'reference_column' => SystemColumn::PARENT_ID,
        ], [
            'column_name' => 'integer',
            'reference_table' => TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE,
            'reference_column' => SystemColumn::PARENT_ID,
        ]];
        $filter_settings = [[
            'column_name' => 'text',
            'reference_table' => TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE,
            'reference_column' => SystemColumn::PARENT_ID,
            'filter_condition' => FilterOption::EQ,
            'filter_value_text' => 'test_2'
        ]];
        $array = $this->getColumnFilterData($column_settings, $filter_settings, function ($data, $custom_view, $filter_settings) {
            if ($data instanceof CustomValue) {
                $parent = $data->getParentValue();
                return $parent->getValue($filter_settings[0]['column_name']) == $filter_settings[0]['filter_value_text'];
            } else {
                $column_item = $custom_view->custom_view_columns[3]->column_item;
                $unique_name = $column_item->uniqueName();
                return array_get($data, $unique_name) == $filter_settings[0]['filter_value_text'];
            }
        }, [
            'target_table_name' => TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE,
        ]);
    }

    /**
     * CustomViewColumn = parent : NO
     * CustomViewFilter = parent : YES
     */
    public function testFuncFilterParent()
    {
        $this->init();

        $column_settings = [[
            'condition_type' => ConditionType::SYSTEM,
            'column_name' => 'id',
        ], [
            'column_name' => 'text',
        ]];
        $filter_settings = [[
            'column_name' => 'text',
            'reference_table' => TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE,
            'reference_column' => SystemColumn::PARENT_ID,
            'filter_condition' => FilterOption::EQ,
            'filter_value_text' => 'test_3'
        ]];
        $array = $this->getColumnFilterData($column_settings, $filter_settings, function ($data, $custom_view, $filter_settings) {
            if (!($data instanceof CustomValue)) {
                $id = array_get($data, 'id');
                $data = getModelName(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE)::find($id);
            }
            $parent = $data->getParentValue();
            return $parent->getValue($filter_settings[0]['column_name']) == $filter_settings[0]['filter_value_text'];
        }, [
            'target_table_name' => TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE,
        ]);
    }

    protected function getColumnFilterData(array $column_settings, array $filter_settings, \Closure $testCallback, array $options = [])
    {
        $options = array_merge(
            [
                'login_user_id' => TestDefine::TESTDATA_USER_LOGINID_ADMIN,
                'column_settings' => $column_settings,
                'filter_settings' => $filter_settings,
            ],
            $options
        );

        // create custom view
        list($custom_table, $custom_view) = $this->createCustomViewAll($options);

        $classname = getModelName($custom_table->table_name);
        $grid = new Grid(new $classname);
        $grid->paginate(100);

        $custom_view->filterSortModel($grid->model());
        $custom_view->setGrid($grid);

        $grid->build();
        $data = $grid->rows();

        $this->__testFilter($data, $custom_view, $filter_settings, $testCallback, $options);
    }

    protected function __testFilter(\Illuminate\Support\Collection $collection, $custom_view, array $filter_settings, \Closure $testCallback, array $options = [])
    {
        $options = array_merge(
            [
                'target_table_name' => TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST,
            ],
            $options
        );

        if ($collection->count() > 0) {
            foreach ($custom_view->custom_view_columns as $custom_view_column) {
                $column_item = $custom_view_column->column_item;
                $unique_name = $column_item->uniqueName();
                $matchResult = array_key_exists($unique_name, $collection[0]->model());
                $this->assertTrue($matchResult, 'matchResult is false. Target column is notfound.' . $custom_view_column->id);
            }
        }

        // get filter matched count.
        foreach ($collection as $data) {
            $matchResult = $testCallback($data->model(), $custom_view, $filter_settings);
            
            $this->assertTrue($matchResult, 'matchResult is false. Target id is ' . $data->id);
        }

        // check not getted values.
        $custom_table = CustomTable::getEloquent($options['target_table_name']);
        $ids = $collection->map(function($data) {
            return array_get($data->model(), 'id');
        })->toArray();
        $notMatchedValues = $custom_table->getValueQuery()->whereNotIn('id', $ids)->get();
        
        foreach ($notMatchedValues as $data) {
            $matchResult = $testCallback($data, $custom_view, $filter_settings);
            
            $this->assertTrue(!$matchResult, 'Expect matchResult is false, but matched. Target id is ' . $data->id);
        }
    }
}
