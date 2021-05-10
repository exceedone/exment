<?php
namespace Exceedone\Exment\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Encore\Admin\Grid;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;

class CustomViewTest extends UnitTestBase
{
    use CustomViewTrait, DatabaseTransactions;

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

    public function testFuncGetSortedCustomView1()
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

    public function testFuncGetSortedCustomView2()
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
        $grid = new Grid(new $classname);
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

        if (isset($prev_parent) && isset($parent)){
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

        if (isset($prev_parent) && isset($parent)){
            return array_get($prev_data, 'value.odd_even') < array_get($data, 'value.odd_even') ||
            (array_get($prev_data, 'value.odd_even') == array_get($data, 'value.odd_even') &&
            array_get($prev_parent, 'value.odd_even') > array_get($parent, 'value.odd_even')) ||
            (array_get($prev_data, 'value.odd_even') == array_get($data, 'value.odd_even') &&
            array_get($prev_parent, 'value.odd_even') == array_get($parent, 'value.odd_even') &&
            array_get($prev_parent, 'created_user_id') == array_get($parent, 'created_user_id'));
        }

        return false;
    }
}
