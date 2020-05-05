<?php
namespace Exceedone\Exment\Tests\Unit;
use Encore\Admin\Grid;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
class CustomViewTest extends UnitTestBase
{
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
    protected function getData($table_name, $view_name){
        $this->be(LoginUser::find(1));
        $classname = getModelName($table_name);
        $grid = new Grid(new $classname);
    
        $custom_view = CustomView::where('view_view_name', $view_name)->first();

        \Exment::user()->filterModel($grid->model(), $custom_view);
        // create grid
        $custom_view->setGrid($grid);
 
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
