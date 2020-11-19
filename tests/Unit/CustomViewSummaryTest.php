<?php
namespace Exceedone\Exment\Tests\Unit;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;

class CustomViewSummaryTest extends UnitTestBase
{
    // /**
    //  * FilterOption = Group(id), Summary(id), Filter(id)
    //  */
    // public function testFuncGroupId()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'id',
    //                 'condition_type' => ConditionType::SYSTEM,
    //             ]],
    //             'summary_settings' => [[
    //                 'column_name' => 'id',
    //                 'condition_type' => ConditionType::SYSTEM,
    //             ]],
    //             'filter_settings' => [[
    //                 'column_name' => 'id',
    //                 'condition_type' => ConditionType::SYSTEM,
    //                 'filter_condition' => FilterOption::NOT_LIKE,
    //                 'filter_value_text' => '1'
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);

    //         $defaults = $this->getCustomViewDataAll($options);

    //         $this->assertTrue(count($summaries) == count($defaults));

    //         foreach ($summaries as $summary) {
    //             $this->assertTrue($defaults->contains(function ($value) use($summary) {
    //                 return $value['id'] == $summary;
    //             }));
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(suuid), Summary(suuid/count)
    //  */
    // public function testFuncGroupSuuid()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'suuid',
    //                 'condition_type' => ConditionType::SYSTEM,
    //             ]],
    //             'summary_settings' => [[
    //                 'column_name' => 'suuid',
    //                 'condition_type' => ConditionType::SYSTEM,
    //                 'summary_condition' => SummaryCondition::COUNT
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);

    //         $defaults = $this->getCustomViewDataCount($options);

    //         $this->assertTrue(count($summaries) == $defaults);

    //         foreach ($summaries as $key => $value) {
    //             $this->assertTrue($value == '1');
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(text), Summary(integer/sum)
    //  */
    // public function testFuncGroupText()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'text',
    //             ]],
    //             'summary_settings' => [[
    //                 'column_name' => 'integer',
    //                 'summary_condition' => SummaryCondition::SUM
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);

    //         $defaults = $this->getCustomViewDataAll($options);

    //         foreach ($summaries as $key => $value) {
    //             $result = $defaults->filter(function($data) use($key){
    //                 return $data['text'] == $key;
    //             })->sum(function($data) {
    //                 return $data['integer'];
    //             });
    //             $this->assertTrue($result == $value);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(integer), Summary(date/min)
    //  */
    // public function testFuncGroupInteger()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'integer',
    //             ]],
    //             'summary_settings' => [[
    //                 'column_name' => 'date',
    //                 'summary_condition' => SummaryCondition::MIN
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);

    //         $defaults = $this->getCustomViewDataAll($options);

    //         foreach ($summaries as $key => $value) {
    //             $result = $defaults->filter(function($data) use($key){
    //                 return $data['integer'] == $key;
    //             })->map(function($data) {
    //                 return $data['date'];
    //             })->min();
    //             $this->assertTrue($result == $value);
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(date), Summary(currency/min)
    //  */
    // public function testFuncGroupDate()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'date',
    //             ]],
    //             'summary_settings' => [[
    //                 'column_name' => 'currency',
    //                 'summary_condition' => SummaryCondition::MIN
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);

    //         $defaults = $this->getCustomViewDataAll($options);

    //         foreach ($summaries as $key => $value) {
    //             $result = $defaults->filter(function($data) use($key){
    //                 if (!empty($key) && isset($data['date'])) {
    //                     return \Carbon\Carbon::parse($data['date'])->format('Y-m-d') == $key;
    //                 } else {
    //                     return empty($key) && empty($data['date']);
    //                 }
    //             })->map(function($data) {
    //                 return $data['currency'];
    //             })->min();
    //             $this->assertTrue(isMatchDecimal($value, $result));
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(date/Y), Summary(decimal/sum)
    //  */
    // public function testFuncGroupDateY()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'date',
    //                 'options' => [
    //                     'view_group_condition' => 'y'
    //                 ]
    //             ]],
    //             'summary_settings' => [[
    //                 'column_name' => 'decimal',
    //                 'summary_condition' => SummaryCondition::SUM
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);
    //         $defaults = $this->getCustomViewDataAll($options);

    //         foreach ($summaries as $key => $value) {
    //             $result = $defaults->filter(function($data) use($key){
    //                 if (isset($key) && isset($data['date'])) {
    //                     return \Carbon\Carbon::parse($data['date'])->year == $key;
    //                 } else {
    //                     return empty($key) && empty($data['date']);
    //                 }
    //             })->sum(function($data) {
    //                 return $data['decimal'];
    //             });
    //             $this->assertTrue(isMatchDecimal($value, $result));
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(date/YM), Summary(currency/sum)
    //  */
    // public function testFuncGroupDateYM()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'date',
    //                 'options' => [
    //                     'view_group_condition' => 'ym'
    //                 ]
    //             ]],
    //             'summary_settings' => [[
    //                 'column_name' => 'currency',
    //                 'summary_condition' => SummaryCondition::SUM
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);

    //         $defaults = $this->getCustomViewDataAll($options);

    //         foreach ($summaries as $key => $value) {
    //             $result = $defaults->filter(function($data) use($key){
    //                 if (!empty($key) && isset($data['date'])) {
    //                     return \Carbon\Carbon::parse($data['date'])->format('Y-m') == $key;
    //                 } else {
    //                     return empty($key) && empty($data['date']);
    //                 }
    //             })->sum(function($data) {
    //                 return $data['currency'];
    //             });
    //             $this->assertTrue(isMatchDecimal($value, $result));
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(date/YMD), Summary(time/min)
    //  */
    // public function testFuncGroupDateYMD()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'date',
    //                 'options' => [
    //                     'view_group_condition' => 'ymd'
    //                 ]
    //             ]],
    //             'summary_settings' => [[
    //                 'column_name' => 'time',
    //                 'summary_condition' => SummaryCondition::MIN
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);

    //         $defaults = $this->getCustomViewDataAll($options);

    //         foreach ($summaries as $key => $value) {
    //             $result = $defaults->filter(function($data) use($key){
    //                 if (!empty($key) && isset($data['date'])) {
    //                     return \Carbon\Carbon::parse($data['date'])->format('Y-m-d') == $key;
    //                 } else {
    //                     return empty($key) && empty($data['date']);
    //                 }
    //             })->map(function($data) {
    //                 return $data['time'];
    //             })->min();
    //             $this->assertTrue(isMatchString($value, $result));
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(date/M), Summary(datetime/max)
    //  */
    // public function testFuncGroupDateM()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'date',
    //                 'options' => [
    //                     'view_group_condition' => 'm'
    //                 ]
    //             ]],
    //             'summary_settings' => [[
    //                 'column_name' => 'datetime',
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);

    //         $defaults = $this->getCustomViewDataAll($options);

    //         foreach ($summaries as $key => $value) {
    //             $result = $defaults->filter(function($data) use($key){
    //                 if (!empty($key) && isset($data['date'])) {
    //                     return \Carbon\Carbon::parse($data['date'])->month == $key;
    //                 } else {
    //                     return empty($key) && empty($data['date']);
    //                 }
    //             })->map(function($data) {
    //                 return $data['datetime'];
    //             })->max();
    //             $this->assertTrue(isMatchString($value, $result));
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(date/D), Summary(created_at/max)
    //  */
    // public function testFuncGroupDateD()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'date',
    //                 'options' => [
    //                     'view_group_condition' => 'd'
    //                 ]
    //             ]],
    //             'summary_settings' => [[
    //                 'condition_type' => ConditionType::SYSTEM,
    //                 'column_name' => 'created_at',
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);

    //         $defaults = $this->getCustomViewDataAll($options);

    //         foreach ($summaries as $key => $value) {
    //             $result = $defaults->filter(function($data) use($key){
    //                 if (!empty($key) && isset($data['date'])) {
    //                     return \Carbon\Carbon::parse($data['date'])->day == $key;
    //                 } else {
    //                     return empty($key) && empty($data['date']);
    //                 }
    //             })->map(function($data) {
    //                 return $data['created_at'];
    //             })->max();
    //             $this->assertTrue(isMatchString($value, $result));
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(date/W), Summary(id/count)
    //  */
    // public function testFuncGroupDateW()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'date',
    //                 'options' => [
    //                     'view_group_condition' => 'w'
    //                 ]
    //             ]],
    //             'summary_settings' => [[
    //                 'condition_type' => ConditionType::SYSTEM,
    //                 'column_name' => 'id',
    //                 'summary_condition' => SummaryCondition::COUNT
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);

    //         $defaults = $this->getCustomViewDataAll($options);

    //         $weekday = ['日', '月', '火', '水', '木', '金', '土'];

    //         foreach ($summaries as $key => $value) {
    //             $result = $defaults->filter(function($data) use($key, $weekday){
    //                 if (!empty($key) && isset($data['date'])) {
    //                     $week = $weekday[\Carbon\Carbon::parse($data['date'])->dayOfWeek]; 
    //                     return $week == $key;
    //                 } else {
    //                     return empty($key) && empty($data['date']);
    //                 }
    //             })->count();
    //             $this->assertTrue(isMatchString($value, $result));
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(date/Time), Summary(integer/max)
    //  */
    // public function testFuncGroupDateTime()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'time',
    //             ]],
    //             'summary_settings' => [[
    //                 'column_name' => 'integer',
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);

    //         $defaults = $this->getCustomViewDataAll($options);

    //         foreach ($summaries as $key => $value) {
    //             $result = $defaults->filter(function($data) use($key){
    //                 if (!empty($key) && isset($data['time'])) {
    //                     return $data['time'] == $key;
    //                 } else {
    //                     return empty($key) && empty($data['time']);
    //                 }
    //             })->map(function($data) {
    //                 return $data['integer'];
    //             })->max();
    //             $this->assertTrue(isMatchString($value, $result));
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(datetime), Summary(suuid/max)
    //  */
    // public function testFuncGroupDateTime()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'datetime',
    //             ]],
    //             'summary_settings' => [[
    //                 'condition_type' => ConditionType::SYSTEM,
    //                 'column_name' => 'suuid',
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);

    //         $defaults = $this->getCustomViewDataAll($options);

    //         foreach ($summaries as $key => $value) {
    //             $result = $defaults->filter(function($data) use($key){
    //                 if (!empty($key) && isset($data['datetime'])) {
    //                     return \Carbon\Carbon::parse($data['datetime'])->format('Y-m-d H:i:s') == $key;
    //                 } else {
    //                     return empty($key) && empty($data['datetime']);
    //                 }
    //             })->map(function($data) {
    //                 return $data['suuid'];
    //             })->max();
    //             $this->assertTrue(isMatchDecimal($value, $result));
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(datetime/YMD), Summary(decimal/max)
    //  */
    // public function testFuncGroupDateTimeYMD()
    // {
    //     $this->init();

    //     DB::beginTransaction();
    //     try {
    //         $options = [
    //             'view_kind_type' => ViewKindType::AGGREGATE,
    //             'column_settings' => [[
    //                 'column_name' => 'datetime',
    //                 'options' => [
    //                     'view_group_condition' => 'ymd'
    //                 ]
    //             ]],
    //             'summary_settings' => [[
    //                 'column_name' => 'decimal',
    //             ]],
    //         ];

    //         $summaries = $this->getCustomViewSummary($options);

    //         $defaults = $this->getCustomViewDataAll($options);

    //         foreach ($summaries as $key => $value) {
    //             $result = $defaults->filter(function($data) use($key){
    //                 if (!empty($key) && isset($data['datetime'])) {
    //                     return \Carbon\Carbon::parse($data['datetime'])->format('Y-m-d') == $key;
    //                 } else {
    //                     return empty($key) && empty($data['datetime']);
    //                 }
    //             })->map(function($data) {
    //                 return $data['decimal'];
    //             })->max();
    //             $this->assertTrue(isMatchDecimal($value, $result));
    //         }
    //     } finally {
    //         DB::rollback();
    //     }
    // }

    // /**
    //  * FilterOption = Group(select), Summary(id/count)
    //  */
    // public function testFuncGroupSelect()
    // {
    //     $this->commonTest('select');
    // }

    // /**
    //  * FilterOption = Group(select_multiple), Summary(id/count)
    //  */
    // public function testFuncGroupSelectMultiple()
    // {
    //     $this->commonTest('select_multiple');
    // }

    // /**
    //  * FilterOption = Group(select_valtext), Summary(id/count)
    //  */
    // public function testFuncGroupSelectValue()
    // {
    //     $this->commonTest('select_valtext');
    // }

    // /**
    //  * FilterOption = Group(select_valtext_multiple), Summary(id/count)
    //  */
    // public function testFuncGroupSelectValueMultiple()
    // {
    //     $this->commonTest('select_valtext_multiple');
    // }

    // /**
    //  * FilterOption = Group(select_table), Summary(id/count)
    //  */
    // public function testFuncGroupSelectTable()
    // {
    //     $this->commonTest('select_table');
    // }

    // /**
    //  * FilterOption = Group(select_table_multiple), Summary(id/count)
    //  */
    // public function testFuncGroupSelectTableMultiple()
    // {
    //     $this->commonTest('select_table_multiple');
    // }

    // /**
    //  * FilterOption = Group(yesno), Summary(id/count)
    //  */
    // public function testFuncGroupYesNo()
    // {
    //     $this->commonTest('yesno');
    // }

    // /**
    //  * FilterOption = Group(boolean), Summary(id/count)
    //  */
    // public function testFuncGroupBoolean()
    // {
    //     $this->commonTest('boolean');
    // }

    // /**
    //  * FilterOption = Group(auto_number), Summary(id/count)
    //  */
    // public function testFuncGroupAutoNumber()
    // {
    //     $this->commonTest('auto_number');
    // }

    // /**
    //  * FilterOption = Group(user), Summary(id/count)
    //  */
    // public function testFuncGroupUser()
    // {
    //     $this->commonTest('user');
    // }

    // /**
    //  * FilterOption = Group(user_multiple), Summary(id/count)
    //  */
    // public function testFuncGroupUserMultiple()
    // {
    //     $this->commonTest('user_multiple');
    // }

    // /**
    //  * FilterOption = Group(organization), Summary(id/count)
    //  */
    // public function testFuncGroupOrganization()
    // {
    //     $this->commonTest('organization');
    // }

    // /**
    //  * FilterOption = Group(organization_multiple), Summary(id/count)
    //  */
    // public function testFuncGroupOrganizationMultiple()
    // {
    //     $this->commonTest('organization_multiple');
    // }

    // /**
    //  * FilterOption = Group(created_at), Summary(id/count)
    //  */
    // public function testFuncGroupCreatedAt()
    // {
    //     $this->commonTest('created_at', ConditionType::SYSTEM);
    // }

    // /**
    //  * FilterOption = Group(created_at/YMD), Summary(id/count)
    //  */
    // public function testFuncGroupCreatedAtYmd()
    // {
    //     $this->commonTest('created_at', ConditionType::SYSTEM, [
    //         'view_group_condition' => 'ymd'
    //     ]);
    // }

    // /**
    //  * FilterOption = Group(updated_at), Summary(id/count)
    //  */
    // public function testFuncGroupUpdatedAt()
    // {
    //     $this->commonTest('updated_at', ConditionType::SYSTEM);
    // }

    // /**
    //  * FilterOption = Group(updated_at/YM), Summary(id/count)
    //  */
    // public function testFuncGroupUpdatedAtYm()
    // {
    //     $this->commonTest('updated_at', ConditionType::SYSTEM, [
    //         'view_group_condition' => 'ym'
    //     ]);
    // }

    /**
     * FilterOption = Group(created_user), Summary(id/count)
     */
    public function testFuncGroupCreatedUser()
    {
        $this->commonTest('created_user', ConditionType::SYSTEM);
    }

    /**
     * FilterOption = Group(updated_user), Summary(id/count)
     */
    public function testFuncGroupUpdatedUser()
    {
        $this->commonTest('updated_user', ConditionType::SYSTEM);
    }

    /**
     * FilterOption = Group(updated_user), Summary(id/count)
     */
    public function testFuncGroupSelectTableId()
    {
        $this->commonTestBase([
            'reference_table' => 'custom_value_edit_all',
            'reference_column' => 'select_table',
            'column_name' => 'id',
            'condition_type' => ConditionType::SYSTEM,
        ]);
    }

    protected function commonTest($column_name, $condition_type = ConditionType::COLUMN, $column_options = []){
        $this->commonTestBase([
            'column_name' => $column_name,
            'condition_type' => $condition_type,
            'options' => $column_options 
        ]);
    }

    protected function commonTestBase($column_settings){
        $this->init();

        DB::beginTransaction();
        try {
            $options = [
                'view_kind_type' => ViewKindType::AGGREGATE,
                'column_settings' => [$column_settings],
                'summary_settings' => [[
                    'condition_type' => ConditionType::SYSTEM,
                    'column_name' => 'id',
                    'summary_condition' => SummaryCondition::COUNT
                ]],
            ];

            $summaries = $this->getCustomViewSummary($options);

            $defaults = $this->getCustomViewDataAll($options);


            foreach ($summaries as $key => $value) {
                $result = collect($defaults)->filter(function($data) use($key, $column_settings){
                    $column_name = array_get($column_settings, 'column_name');
                    $column_options = array_get($column_settings, 'options')?? [];
                    $group_condition = array_get($column_options, 'view_group_condition');

                    $column_data = $data[$column_name];
                    if (!empty($key) && isset($column_data)) {
                        if (is_array(json_decode($key))) {
                            return isMatchArray($column_data, json_decode($key));
                            // if (is_vector($column_data)) {
                            //     return isMatchArray($column_data, json_decode($key));
                            // } else {
                            //     $column_data = array_get($column_data, 'id');
                            // }
                        } elseif (is_array($column_data)) {
                            return false;
                        } elseif ($column_data instanceof \Carbon\Carbon) {
                            $column_data = $this->convertDateToString($column_data, $group_condition);
                        }
                        return isMatchString($column_data, $key);
                    } else {
                        return empty($key) && empty($column_data);
                    }
                })->count();
                $this->assertTrue(isMatchString($value, $result));
            }
        } finally {
            DB::rollback();
        }
    }

    protected function convertDateToString($datevalue, $format = null)
    {
        if (is_null($format)) {
            return $datevalue;
        }
        $dateStrings = [
            'ymd' => 'Y-m-d',
            'ym' => 'Y-m',
        ];
        return $datevalue->format($dateStrings[strtolower($format)]);
    }
    protected function getCustomViewSummary($options){
        return $this->getCustomViewData($options)->mapWithKeys(function($data) {
            $values = array_values($data->getAttributes());
            return [$values[0] => $values[1]];
        });
    }

    protected function getCustomViewDataCount($options){
        System::clearCache();
        unset($options['column_settings']);
        unset($options['summary_settings']);
        $options['view_kind_type'] = ViewKindType::DEFAULT;
        $options['get_count'] = true;
        return $this->getCustomViewData($options);
    }
    protected function getCustomViewDataAll($options){
        System::clearCache();

        $column_settings = [];
        if (isset($options['column_settings'])) {
            foreach ($options['column_settings'] as $setting) {
                if (array_key_exists('column_name', $setting)) {
                    $column_settings[] = $setting;
                }
            }
            unset($options['column_settings']);
        }
        if (isset($options['summary_settings'])) {
            foreach ($options['summary_settings'] as $setting) {
                if (array_key_exists('column_name', $setting)) {
                    $column_settings[] = $setting;
                }
            }
            unset($options['summary_settings']);
        }
        $options['view_kind_type'] = ViewKindType::DEFAULT;

        return $this->getCustomViewData($options)->map(function($data) use($column_settings) {
            return collect($column_settings)->mapWithKeys(function($column_setting) use($data){
                $column_name = $column_setting['column_name'];
                if (isset($column_setting['condition_type']) && $column_setting['condition_type'] == ConditionType::SYSTEM) {
                    $column_id = SystemColumn::getOption(['name' => $column_name])['sqlname'];
                    $column_data = array_get($data, $column_id);
                } else {
                    $column_data = $data->getValue($column_name);
                }
                if ($column_data instanceof \Illuminate\Support\Collection) {
                    $column_data = $column_data->map(function($item) {
                        if ($item instanceof CustomValue) {
                            return $item->id;
                        }
                        return $item;
                    });
                } elseif ($column_data instanceof CustomValue) {
                    $column_data = $column_data->id;
                }
                return [$column_name => $column_data];
            });
        })->toArray();
    }

    protected function init(){
        System::clearCache();
    }

    protected function getCustomViewData(array $options = [])
    {
        $options = array_merge(
            [
                'login_user_id' => TestDefine::TESTDATA_USER_LOGINID_ADMIN,
                'target_table_name' => TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS,
                'view_kind_type' => ViewKindType::DEFAULT,
                'condition_join' => 'and',
                'get_count' => false,
                'filter_settings' => [],
                'column_settings' => [],
                'summary_settings' => [],
            ], 
            $options
        );
        extract($options);

        // Login user.
        $this->be(LoginUser::find($login_user_id));

        $custom_table = CustomTable::getEloquent($target_table_name);

        $custom_view = CustomView::create([
            'custom_table_id' => $custom_table->id,
            'view_view_name' => $custom_table->table_name . '-view-unittest',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => $view_kind_type,
            'options' => ['condition_join' => $condition_join?? 'and'],
        ]);

        foreach ($column_settings as $index => $column_setting)
        {
            if (isset($column_setting['reference_table'])) {
                $refer_table = CustomTable::getEloquent($column_setting['reference_table']);
                $view_column_table_id = $refer_table->id;
                $view_column_target_id = $this->getTargetColumnId($column_setting, $refer_table);
                $column_setting['options']['view_pivot_table_id'] = $custom_table->id;
                $column_setting['options']['view_pivot_column_id'] = $this->getTargetColumnId([
                    'column_name' => $column_setting['reference_column']
                ], $custom_table);
            } else {
                $view_column_table_id = $custom_table->id;
                $view_column_target_id = $this->getTargetColumnId($column_setting, $custom_table);
            }
            $custom_view_column = CustomViewColumn::create([
                'custom_view_id' => $custom_view->id,
                'view_column_type' => $column_setting['condition_type'] ?? ConditionType::COLUMN,
                'view_column_table_id' => $view_column_table_id,
                'view_column_target_id' => $view_column_target_id,
                'view_column_name' => $column_setting['view_column_name']?? null,
                'order' => $column_setting['order']?? $index + 1,
                'options' => $column_setting['options']?? null,
            ]);
        }

        foreach ($summary_settings as $summary_setting)
        {
            $custom_view_summary = CustomViewSummary::create([
                'custom_view_id' => $custom_view->id,
                'view_column_type' => $summary_setting['condition_type'] ?? ConditionType::COLUMN,
                'view_column_table_id' => $custom_table->id,
                'view_column_target_id' => $this->getTargetColumnId($summary_setting, $custom_table),
                'view_column_name' => $summary_setting['view_column_name']?? null,
                'view_summary_condition' => $summary_setting['summary_condition'] ?? SummaryCondition::MAX,
                'options' => $summary_setting['options']?? null,
            ]);
        }

        foreach ($filter_settings as $filter_setting)
        {
            $custom_view_filter = CustomViewFilter::create([
                'custom_view_id' => $custom_view->id,
                'view_column_type' => $filter_setting['condition_type'] ?? ConditionType::COLUMN,
                'view_column_table_id' => $custom_table->id,
                'view_column_target_id' => $this->getTargetColumnId($filter_setting, $custom_table),
                'view_filter_condition' => $filter_setting['filter_condition']?? null,
                'view_filter_condition_value_text' => $filter_setting['filter_value_text']?? null,
                'options' => $filter_setting['options']?? null,
            ]);
        }

        $query = $custom_table->getValueModel()->query();
        if ($view_kind_type == ViewKindType::DEFAULT) {
            $custom_view->filterModel($query);
            if ($get_count) {
                return $query->count();
            } else {
                return $query->get();
            }
        } else {
            return $custom_view->getQuery($query)->get();
        }
    }

    protected function getTargetColumnId($setting, $custom_table)
    {
        if (!isset($setting['condition_type']) || $setting['condition_type'] == ConditionType::COLUMN) {
            $custom_column = CustomColumn::getEloquent($setting['column_name'], $custom_table);
            $column_id = $custom_column->id;
        } else {
            $column_id = SystemColumn::getOption(['name' => $setting['column_name']])['id'];
        }
        return $column_id;
    }

}