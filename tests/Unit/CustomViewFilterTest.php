<?php
namespace Exceedone\Exment\Tests\Unit;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;

class CustomViewFilterTest extends UnitTestBase
{
    // /**
    //  * FilterOption = EQ
    //  */
    // public function testFuncFilterEq()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $target_value = 'index_2_2';
    //         $array = $this->getColumnFilterData('index_text', FilterOption::EQ, $target_value);

    //         foreach ($array as $data) {
    //             $this->assertTrue(array_get($data, 'value.index_text') == $target_value);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = NE
    //  */
    // public function testFuncFilterNe()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $target_value = 'odd';
    //         $array = $this->getColumnFilterData('odd_even', FilterOption::NE, $target_value);

    //         foreach ($array as $data) {
    //             $this->assertTrue(array_get($data, 'value.odd_even') != $target_value);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = NE
    //  */
    // public function testFuncFilterNotNull()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $array = $this->getColumnFilterData('null_text', FilterOption::NOT_NULL);

    //         foreach ($array as $data) {
    //             $this->assertTrue(array_get($data, 'value.null_text') !== null);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = NULL
    //  */
    // public function testFuncFilterNull()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $array = $this->getColumnFilterData('null_text', FilterOption::NULL);

    //         foreach ($array as $data) {
    //             $this->assertTrue(array_get($data, 'value.null_text') === null);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = LIKE
    //  */
    // public function testFuncFilterLike()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $target_value = 'index_5';
    //         $array = $this->getColumnFilterData('index_text', FilterOption::LIKE, $target_value);

    //         foreach ($array as $data) {
    //             $this->assertTrue(strpos(array_get($data, 'value.index_text'), $target_value) === 0);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = NOT LIKE
    //  */
    // public function testFuncFilterNotLike()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $target_value = 'index_5';
    //         $array = $this->getColumnFilterData('index_text', FilterOption::NOT_LIKE, $target_value);

    //         foreach ($array as $data) {
    //             $this->assertTrue(strpos(array_get($data, 'value.index_text'), $target_value) !== 0);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = DAY
    //  */
    // public function testFuncFilterDayOn()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $target_value = '2019-01-01';
    //         $array = $this->getColumnFilterData('date', FilterOption::DAY_ON, $target_value);

    //         foreach ($array as $data) {
    //             $this->assertTrue(array_get($data, 'value.date') == $target_value);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption >= DAY
    //  */
    // public function testFuncFilterDayOnOrAfter()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $target_value = '2019-01-07';
    //         $array = $this->getColumnFilterData('date', FilterOption::DAY_ON_OR_AFTER, $target_value);

    //         $base_date = \Carbon\Carbon::parse($target_value);
    //         foreach ($array as $data) {
    //             $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
    //             $this->assertTrue($date >= $base_date);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption <= DAY
    //  */
    // public function testFuncFilterDayOnOrBefore()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $target_value = '2019-01-01';
    //         $array = $this->getColumnFilterData('date', FilterOption::DAY_ON_OR_BEFORE, $target_value);

    //         $base_date = \Carbon\Carbon::parse($target_value);
    //         foreach ($array as $data) {
    //             $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
    //             $this->assertTrue($date <= $base_date);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = DAY NOT NULL
    //  */
    // public function testFuncFilterDayNotNull()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $array = $this->getColumnFilterData('date', FilterOption::DAY_NOT_NULL);

    //         foreach ($array as $data) {
    //             $this->assertTrue(array_get($data, 'value.date') !== null);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = DAY NULL
    //  */
    // public function testFuncFilterDayNull()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $array = $this->getColumnFilterData('date', FilterOption::DAY_NULL);

    //         foreach ($array as $data) {
    //             $this->assertTrue(array_get($data, 'value.date') === null);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = DAY TODAY
    //  */
    // public function testFuncFilterDayToday()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $array = $this->getColumnFilterData('date', FilterOption::DAY_TODAY);
    //         $target_value = \Carbon\Carbon::now()->format('Y-m-d');

    //         foreach ($array as $data) {
    //             $this->assertTrue(array_get($data, 'value.date') === $target_value);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = DAY TODAY OR AFTER
    //  */
    // public function testFuncFilterDayTodayOrAfter()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $array = $this->getColumnFilterData('date', FilterOption::DAY_TODAY_OR_AFTER);
    //         $target_value = \Carbon\Carbon::now()->format('Y-m-d');

    //         foreach ($array as $data) {
    //             $this->assertTrue(array_get($data, 'value.date') >= $target_value);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = DAY TODAY OR BEFORE
    //  */
    // public function testFuncFilterDayTodayOrBefore()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $array = $this->getColumnFilterData('date', FilterOption::DAY_TODAY_OR_BEFORE);
    //         $target_value = \Carbon\Carbon::now()->format('Y-m-d');

    //         foreach ($array as $data) {
    //             $this->assertTrue(array_get($data, 'value.date') <= $target_value);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = DAY YESTERDAY
    //  */
    // public function testFuncFilterDayYesterday()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $array = $this->getColumnFilterData('date', FilterOption::DAY_YESTERDAY);
    //         $target_value = \Carbon\Carbon::yesterday()->format('Y-m-d');

    //         foreach ($array as $data) {
    //             $this->assertTrue(array_get($data, 'value.date') === $target_value);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = DAY TOMORROW
    //  */
    // public function testFuncFilterDayTomorrow()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $array = $this->getColumnFilterData('date', FilterOption::DAY_TOMORROW);
    //         $target_value = \Carbon\Carbon::tomorrow()->format('Y-m-d');

    //         foreach ($array as $data) {
    //             $this->assertTrue(array_get($data, 'value.date') === $target_value);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    /**
     * FilterOption = DAY THIS MONTH
     */
    public function testFuncFilterDayThisMonth()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $array = $this->getColumnFilterData('date', FilterOption::DAY_THIS_MONTH);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
                $this->assertTrue($date->isCurrentMonth());
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY LAST MONTH
     */
    public function testFuncFilterDayLastMonth()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $array = $this->getColumnFilterData('date', FilterOption::DAY_LAST_MONTH);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
                $this->assertTrue($date->isLastMonth());
            }
        } finally {
            DB::rollback();
        }
    }

    // /**
    //  * FilterOption = USER_EQ_USER
    //  */
    // public function testFuncFilterLoginUser()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $array = $this->getColumnFilterData('user', FilterOption::USER_EQ_USER);

    //         $user_id = \Exment::getUserId();
    //         foreach ($array as $data) {
    //             $this->assertTrue(array_get($data, 'value.user') == $user_id);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    protected function init(){
        System::clearCache();
    }

    protected function getColumnFilterData($column_name, $filter_condition, $filter_value_text = null, array $options = [])
    {
        $options = array_merge(
            [
                'login_user_admin' => false, // if true, login as admin. else normal user. if normal user, options has only items user has permission.
                'target_table_name' => null,
                'condition_join' => 'and',
            ], 
            $options
        );

        // Login user.
        $this->be(LoginUser::find($options['login_user_admin'] ? TestDefine::TESTDATA_USER_LOGINID_ADMIN : TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC));

        $custom_table = CustomTable::getEloquent($options['target_table_name']?? TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $custom_column = CustomColumn::getEloquent($column_name, $custom_table);
        $model = $custom_table->getValueModel()->query();

        $custom_view = $this->createCustomView(
            $custom_table, 
            ViewType::SYSTEM, 
            ViewKindType::DEFAULT, 
            $custom_table->table_name . '-view-' . $column_name, 
            ['condition_join' => $options['condition_join']?? 'and']
        );

        $custom_view_filter = $this->createCustomViewFilter(
            $custom_view->id,
            ConditionType::COLUMN,
            $custom_table->id,
            $custom_column->id,
            $filter_condition,
            $filter_value_text
        );

        $custom_view->filterModel($model);
        return $model->get();
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
}
