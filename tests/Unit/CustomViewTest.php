<?php
namespace Exceedone\Exment\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Encore\Admin\Grid;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\TestDefine;

class CustomViewTest extends UnitTestBase
{
    use CustomViewTrait;

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
        $andCount = $array->filter(function($a){
            return $this->andWhere($a);
        })->count();
        $this->assertTrue($andCount != $array->count());
    }

    /**
     * show select table id in custom view
     * -- bug fixed confirm test
     */
    public function testFuncSelectTableId()
    {
        $this->initAllTest();

        DB::beginTransaction();
        try {
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
                            $this->assertEquals($text, $index + 1);
                            break;
                        case 1:
                            $this->assertEquals($text, ($index % 10) + 1);
                            break;
                        case 2:
                            $this->assertEquals($text, ($index % 10) + 11);
                            break;
                        }
                }
            }
        } finally {
            DB::rollback();
        }
    }

    protected function getData($table_name, $view_name){
        $this->be(LoginUser::find(1));
        $classname = getModelName($table_name);
        $grid = new Grid(new $classname);
    
        $custom_view = CustomView::where('view_view_name', $view_name)->first();

        if(isset($custom_view)){
            $custom_view->filterModel($grid->model());
            // create grid
            $custom_view->setGrid($grid);
        }
 
        return $grid->model()->buildData(false);
    }
    protected function andWhere($data){
        return array_get($data, 'value.odd_even') != 'odd' &&
        array_get($data, 'value.multiples_of_3') == 1 &&
        array_get($data, 'value.user') == 2;
    }
    protected function orWhere($data){
        return array_get($data, 'value.odd_even') != 'odd' ||
        array_get($data, 'value.multiples_of_3') == 1 ||
        array_get($data, 'value.user') == 2;
    }
}
