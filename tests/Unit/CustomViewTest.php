<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Encore\Admin\Grid;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\TestDefine;

class CustomViewTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    public function testFuncGetMatchedCustomView1()
    {
        $array = $this->getData('custom_value_edit_all', 'custom_value_edit_all-view-and');
        foreach ($array as $data) {
            $this->assertTrue($this->andWhere($data));
        }
    }

    public function testFuncGetMatchedCustomView2()
    {
        $array = $this->getData('custom_value_edit_all', 'custom_value_edit_all-view-or');
        foreach ($array as $data) {
            $this->assertTrue($this->orWhere($data));
        }
        $andCount = $array->filter(function ($a) {
            return $this->andWhere($a);
        })->count();
        $this->assertTrue($andCount != $array->count());
    }

    public function testFuncGetSortedByParent1()
    {
        $array = $this->getData('child_table', 'child_table-parent-sort');
        $prev_data = null;
        foreach ($array as $data) {
            if (isset($prev_data)) {
                $this->assertTrue($this->sortParent($prev_data, $data));
            }
            $prev_data = $data;
        }
    }

    public function testFuncGetSortedByParent2()
    {
        $array = $this->getData('child_table', 'child_table-parent-sort-mix');
        $prev_data = null;
        foreach ($array as $data) {
            if (isset($prev_data)) {
                $this->assertTrue($this->sortParentMix($prev_data, $data));
            }
            $prev_data = $data;
        }
    }

    public function testFuncGetSortedBySelectTable()
    {
        $array = $this->getData('all_columns_table_fortest', 'all_columns_table_fortest-select-table-1');
        $prev_data = null;
        foreach ($array as $data) {
            if (isset($prev_data)) {
                $this->assertTrue($this->sortSelectTable($prev_data, $data));
            }
            $prev_data = $data;
        }
    }

    /**
     * show all columns refered parent_table
     */
    public function testFuncParentTableAllColumns()
    {
        $this->initAllTest();

        $options = [
            'target_table_name' => TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE,
            'column_settings' => [],
            'filter_settings' => [[
                'column_name' => 'user',
                'filter_condition' => FilterOption::USER_EQ_USER,
            ]]
        ];
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $relations = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $custom_table->id)->get();

        foreach ($relations as $rel) {
            $parent = array_get($rel, 'parent_custom_table');
            foreach ($parent->custom_columns_cache as $custom_column) {
                $options['column_settings'][] = [
                    'reference_table' => TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE,
                    'reference_column' => SystemColumn::PARENT_ID,
                    'column_name' => $custom_column->column_name,
                ];
            }
            foreach (SystemColumn::getOptions() as $option) {
                if (boolval(array_get($option, 'header')) || boolval(array_get($option, 'footer'))) {
                    $options['column_settings'][] = [
                        'reference_table' => TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE,
                        'reference_column' => SystemColumn::PARENT_ID,
                        'condition_type' => ConditionType::SYSTEM,
                        'column_name' => array_get($option, 'name'),
                    ];
                }
            }
        }
        list($custom_view, $array) = $this->getCustomView($options);

        $this->checkSelectColumns($custom_table, $custom_view, $array, $relations->first());
    }

    /**
     * show all columns refered parent_table
     */
    public function testFuncParentTableNNAllColumns()
    {
        $this->initAllTest();

        $options = [
            'target_table_name' => TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE_MANY_TO_MANY,
            'column_settings' => [],
            'filter_settings' => [[
                'column_name' => 'user',
                'filter_condition' => FilterOption::USER_EQ_USER,
            ]]
        ];
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE_MANY_TO_MANY);
        $relations = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $custom_table->id)->get();

        foreach ($relations as $rel) {
            $parent = array_get($rel, 'parent_custom_table');
            foreach ($parent->custom_columns_cache as $custom_column) {
                $options['column_settings'][] = [
                    'reference_table' => TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_MANY_TO_MANY,
                    'reference_column' => SystemColumn::PARENT_ID,
                    'column_name' => $custom_column->column_name,
                ];
            }
            foreach (SystemColumn::getOptions() as $option) {
                if (boolval(array_get($option, 'header')) || boolval(array_get($option, 'footer'))) {
                    $options['column_settings'][] = [
                        'reference_table' => TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_MANY_TO_MANY,
                        'reference_column' => SystemColumn::PARENT_ID,
                        'condition_type' => ConditionType::SYSTEM,
                        'column_name' => array_get($option, 'name'),
                    ];
                }
            }
        }
        list($custom_view, $array) = $this->getCustomView($options);

        $this->checkSelectColumns($custom_table, $custom_view, $array, $relations->first());
    }

    protected function checkSelectColumns($custom_table, $custom_view, $array, $relation = null)
    {
        foreach ($array as $index => $data) {
            $custom_value = $custom_table->getValueModel()->find($data->id);
            $parent_value = null;
            if (isset($relation)) {
                $parent_value = $custom_value->getParentValue($relation);
            }
            /** @var CustomViewColumn $custom_view_column */
            foreach ($custom_view->custom_view_columns as $custom_view_column) {
                // get grid show value
                $text = $custom_view_column->column_item->options([
                    'view_pivot_column' => $custom_view_column->view_pivot_column_id ?? null,
                    'view_pivot_table' => $custom_view_column->view_pivot_table_id ?? null,
                ])->setCustomValue($data)->text();

                if (isset($custom_view_column->view_pivot_column_id)) {
                    if ($custom_view_column->view_pivot_column_id == SystemColumn::PARENT_ID) {
                        $compare_value = $parent_value;
                    } else {
                        $pivot_info = $custom_view_column->getPivotUniqueKeyValues();
                        $compare_value = $custom_value->getValue($pivot_info['column_name']);
                    }
                } else {
                    $compare_value = $custom_value;
                }
                if (!isset($compare_value)) {
                    $compare = null;
                } elseif (is_list($compare_value)) {
                    $compare = collect($compare_value)->map(function ($v) use ($custom_view_column) {
                        return $this->getCompareValue($custom_view_column, $v);
                    })->filter()->implode('、');
                } else {
                    $compare = $this->getCompareValue($custom_view_column, $compare_value);
                }
                $this->assertEquals($text, $compare);
            }
        }
    }

    protected function getCompareValue($custom_view_column, $compare_value)
    {
        if ($custom_view_column->view_column_type == ConditionType::COLUMN) {
            $column_name = $custom_view_column->custom_column->column_name;
            $compare = $compare_value->getValue($column_name, true);
        } else {
            $column_name = SystemColumn::getOption(['id' => $custom_view_column->view_column_target_id])['name'] ?? null;
            $compare = array_get($compare_value, $column_name);
        }
        if ($compare instanceof \Carbon\Carbon) {
            $compare = $compare->toDateTimeString();
        }
        return $compare;
    }

    /**
     * show all columns refered by select_table
     */
    public function testFuncSelectTableAllColumns()
    {
        $this->initAllTest();

        $options = [
            'column_settings' => [],
            'filter_settings' => [[
                'column_name' => 'user',
                'filter_condition' => FilterOption::USER_EQ,
                'filter_value_text' => 1
            ]]
        ];
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        foreach (SystemColumn::getOptions() as $option) {
            if (boolval(array_get($option, 'header')) || boolval(array_get($option, 'footer'))) {
                $options['column_settings'][] = [
                    'condition_type' => ConditionType::SYSTEM,
                    'column_name' => array_get($option, 'name'),
                ];
            }
        }
        foreach ($custom_table->custom_columns_cache as $custom_column) {
            $options['column_settings'][] = [
                'column_name' => $custom_column->column_name,
            ];
        }
        $select_table_columns = $custom_table->getSelectTableColumns(null, true);
        foreach ($select_table_columns as $select_table_column) {
            $select_table = $select_table_column->column_item->getSelectTable();
            foreach ($select_table->custom_columns_cache as $custom_column) {
                $options['column_settings'][] = [
                    'reference_table' => $select_table->table_name,
                    'reference_column' => $select_table_column->column_name,
                    'column_name' => $custom_column->column_name,
                ];
            }
        }
        list($custom_view, $array) = $this->getCustomView($options);

        $this->checkSelectColumns($custom_table, $custom_view, $array);
    }

    /**
     * show select table id in custom view
     * -- bug fixed confirm test
     */
    public function testFuncSelectTableId()
    {
        $this->initAllTest();

        $options = [
            'column_settings' => [[
                'column_name' => 'id',
                'condition_type' => ConditionType::SYSTEM,
            ], [
                'reference_table' => 'custom_value_view_all',
                'reference_column' => 'select_table',
                'condition_type' => ConditionType::SYSTEM,
                'column_name' => 'id',
            ], [
                'reference_table' => 'custom_value_edit',
                'reference_column' => 'select_table_2',
                'condition_type' => ConditionType::SYSTEM,
                'column_name' => 'id',
            ]],
        ];

        list($custom_view, $array) = $this->getCustomView($options);

        foreach ($custom_view->custom_view_columns as $colno => $custom_view_column) {
            foreach ($array as $index => $data) {
                $text = $custom_view_column->column_item->options([
                    'view_pivot_column' => $custom_view_column->view_pivot_column_id ?? null,
                    'view_pivot_table' => $custom_view_column->view_pivot_table_id ?? null,
                ])->setCustomValue($data)->text();
                switch ($colno) {
                    case 0:
                    case 1:
                        $this->assertEquals($text, $index + 1);
                        break;
                    case 2:
                        $this->assertEquals($text, intdiv($index, 2) + 1);
                        break;
                }
            }
        }
    }

    /**
     * show select table text in custom view
     * -- bug fixed confirm test
     */
    public function testFuncSelectTableText()
    {
        $this->initAllTest();

        $options = [
            'column_settings' => [[
                'column_name' => 'text',
            ], [
                'reference_table' => 'custom_value_view_all',
                'reference_column' => 'select_table',
                'column_name' => 'text',
            ], [
                'reference_table' => 'custom_value_edit',
                'reference_column' => 'select_table_2',
                'column_name' => 'text',
            ]],
        ];

        list($custom_view, $array) = $this->getCustomView($options);

        $null_exists = false;

        foreach ($custom_view->custom_view_columns as $colno => $custom_view_column) {
            foreach ($array as $index => $data) {
                $text = $custom_view_column->column_item->options([
                    'view_pivot_column' => $custom_view_column->view_pivot_column_id ?? null,
                    'view_pivot_table' => $custom_view_column->view_pivot_table_id ?? null,
                ])->setCustomValue($data)->text();
                switch ($colno) {
                    case 0:
                        if (is_null($text)) {
                            $null_exists = true;
                        } else {
                            $this->assertEquals($text, 'text_' . (($index % 10) + 1));
                        }
                        break;
                    case 1:
                        $this->assertEquals($text, 'test_' . (intdiv($index, 10) + 1));
                        break;
                    case 2:
                        $this->assertEquals($text, 'test_' . (intdiv($index, 20) + 1));
                        break;
                }
            }
        }
        $this->assertTrue($null_exists);
    }

    /**
     * show select table created_at in custom view
     * -- bug fixed confirm test
     */
    public function testFuncSelectTableCreatedAt()
    {
        $this->initAllTest();

        $options = [
            'column_settings' => [[
                'column_name' => 'created_at',
                'condition_type' => ConditionType::SYSTEM,
            ], [
                'reference_table' => 'custom_value_view_all',
                'reference_column' => 'select_table',
                'condition_type' => ConditionType::SYSTEM,
                'column_name' => 'created_at',
            ], [
                'reference_table' => 'custom_value_edit',
                'reference_column' => 'select_table_2',
                'condition_type' => ConditionType::SYSTEM,
                'column_name' => 'created_at',
            ]],
        ];

        list($custom_view, $array) = $this->getCustomView($options);

        foreach ($array as $index => $data) {
            $list = [];
            foreach ($custom_view->custom_view_columns as $colno => $custom_view_column) {
                $list[] = $custom_view_column->column_item->options([
                    'view_pivot_column' => $custom_view_column->view_pivot_column_id ?? null,
                    'view_pivot_table' => $custom_view_column->view_pivot_table_id ?? null,
                ])->setCustomValue($data)->text();
            }
            $this->assertEquals(count($list), 3);
            $this->assertNotEquals($list[0], $list[1]);
            $this->assertNotEquals($list[0], $list[2]);
            $this->assertNotEquals($list[1], $list[2]);
        }
    }

    protected function getData($table_name, $view_name, $page_count = 100)
    {
        $this->be(LoginUser::find(1));
        $classname = getModelName($table_name);
        $grid = new Grid(new $classname());
        $grid->paginate($page_count);

        $custom_view = CustomView::where('view_view_name', $view_name)->first();

        if (isset($custom_view)) {
            $custom_view->filterSortModel($grid->model());
            // create grid
            $custom_view->setGrid($grid);
        }

        $result = $grid->model()->buildData(false);
        $this->assertTrue(count($result) > 0, "custom view $view_name count expects over 0, but this count is 0.");

        return $result;
    }
    protected function andWhere($data)
    {
        return array_get($data, 'value.odd_even') != 'odd' &&
        array_get($data, 'value.multiples_of_3') == 1 &&
        array_get($data, 'value.user') == 2;
    }
    protected function orWhere($data)
    {
        return array_get($data, 'value.odd_even') != 'odd' ||
        array_get($data, 'value.multiples_of_3') == 1 ||
        array_get($data, 'value.user') == 2;
    }
    protected function sortParent($prev_data, $data)
    {
        $prev_parent = $prev_data->getParentValue();
        $parent = $data->getParentValue();

        if (isset($prev_parent) && isset($parent)) {
            return array_get($prev_parent, 'value.date') < array_get($parent, 'value.date') ||
            array_get($prev_parent, 'value.date') == array_get($parent, 'value.date') &&
            array_get($prev_parent, 'value.odd_even') >= array_get($parent, 'value.odd_even');
        }

        return false;
    }
    protected function sortParentMix($prev_data, $data)
    {
        $prev_parent = $prev_data->getParentValue();
        $parent = $data->getParentValue();

        if (isset($prev_parent) && isset($parent)) {
            return array_get($prev_data, 'value.odd_even') < array_get($data, 'value.odd_even') ||
            (array_get($prev_data, 'value.odd_even') == array_get($data, 'value.odd_even') &&
            array_get($prev_parent, 'value.odd_even') > array_get($parent, 'value.odd_even')) ||
            (array_get($prev_data, 'value.odd_even') == array_get($data, 'value.odd_even') &&
            array_get($prev_parent, 'value.odd_even') == array_get($parent, 'value.odd_even') &&
            array_get($prev_parent, 'created_user_id') <= array_get($parent, 'created_user_id'));
        }

        return false;
    }
    protected function sortSelectTable($prev_data, $data)
    {
        $select_table_prev = $prev_data->getValue('select_table');
        $select_table = $data->getValue('select_table');
        $select_table_2_prev = $prev_data->getValue('select_table_2');
        $select_table_2 = $data->getValue('select_table_2');
        $user_prev = $prev_data->getValue('user');
        $user = $data->getValue('user');
        $organization_prev = $prev_data->getValue('organization');
        $organization = $data->getValue('organization');

        return array_get($select_table_prev, 'date') < array_get($select_table, 'date') ||
            (array_get($select_table_prev, 'date') == array_get($select_table, 'date') &&
            array_get($select_table_2_prev, 'date') < array_get($select_table_2, 'date')) ||
            (array_get($select_table_prev, 'date') == array_get($select_table, 'date') &&
            array_get($select_table_2_prev, 'date') == array_get($select_table_2, 'date') &&
            array_get($user_prev, 'user_name') < array_get($user, 'user_name')) ||
            (array_get($select_table_prev, 'date') == array_get($select_table, 'date') &&
            array_get($select_table_2_prev, 'date') == array_get($select_table_2, 'date') &&
            array_get($user_prev, 'user_name') == array_get($user, 'user_name') &&
            array_get($organization_prev, 'organization_name') <= array_get($organization, 'organization_name'));
    }
}
