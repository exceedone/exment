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
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;

class CustomViewSummaryTest extends UnitTestBase
{
    /**
     * FilterOption = Group(id), Summary(id), Filter(id)
     */
    public function testFuncGroupId()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $options = [
                'view_kind_type' => ViewKindType::AGGREGATE,
                'column_settings' => [[
                    'column_name' => 'id',
                    'condition_type' => ConditionType::SYSTEM,
                ]],
                'summary_settings' => [[
                    'column_name' => 'id',
                    'condition_type' => ConditionType::SYSTEM,
                ]],
                'filter_settings' => [[
                    'column_name' => 'id',
                    'condition_type' => ConditionType::SYSTEM,
                    'filter_condition' => FilterOption::NOT_LIKE,
                    'filter_value_text' => '1'
                ]],
            ];

            $summaries = $this->getCustomViewSummary($options);

            $defaults = $this->getCustomViewDataAll($options);

            $this->assertTrue(count($summaries) == count($defaults));

            foreach ($summaries as $summary) {
                $this->assertTrue($defaults->contains(function ($value) use($summary) {
                    return $value['id'] == $summary;
                }));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = Group(suuid), Summary(suuid/count)
     */
    public function testFuncGroupSuuid()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $options = [
                'view_kind_type' => ViewKindType::AGGREGATE,
                'column_settings' => [[
                    'column_name' => 'suuid',
                    'condition_type' => ConditionType::SYSTEM,
                ]],
                'summary_settings' => [[
                    'column_name' => 'suuid',
                    'condition_type' => ConditionType::SYSTEM,
                    'summary_condition' => SummaryCondition::COUNT
                ]],
            ];

            $summaries = $this->getCustomViewSummary($options);

            $defaults = $this->getCustomViewDataCount($options);

            $this->assertTrue(count($summaries) == $defaults);

            foreach ($summaries as $key => $value) {
                $this->assertTrue($value == '1');
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = Group(text), Summary(integer/sum)
     */
    public function testFuncGroupText()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $options = [
                'view_kind_type' => ViewKindType::AGGREGATE,
                'column_settings' => [[
                    'column_name' => 'text',
                ]],
                'summary_settings' => [[
                    'column_name' => 'integer',
                    'summary_condition' => SummaryCondition::SUM
                ]],
            ];

            $summaries = $this->getCustomViewSummary($options);

            $defaults = $this->getCustomViewDataAll($options);

            foreach ($summaries as $key => $value) {
                $result = $defaults->filter(function($data) use($key){
                    return $data['text'] == $key;
                })->sum(function($data) {
                    return $data['integer'];
                });
                $this->assertTrue($result == $value);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = Group(integer), Summary(date/min)
     */
    public function testFuncGroupInteger()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $options = [
                'view_kind_type' => ViewKindType::AGGREGATE,
                'column_settings' => [[
                    'column_name' => 'integer',
                ]],
                'summary_settings' => [[
                    'column_name' => 'date',
                    'summary_condition' => SummaryCondition::MIN
                ]],
            ];

            $summaries = $this->getCustomViewSummary($options);

            $defaults = $this->getCustomViewDataAll($options);

            foreach ($summaries as $key => $value) {
                $result = $defaults->filter(function($data) use($key){
                    return $data['integer'] == $key;
                })->map(function($data) {
                    return $data['date'];
                })->min();
                $this->assertTrue($result == $value);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = Group(date/Y), Summary(decimal/sum)
     */
    public function testFuncGroupDateY()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $options = [
                'view_kind_type' => ViewKindType::AGGREGATE,
                'column_settings' => [[
                    'column_name' => 'date',
                    'options' => [
                        'view_group_condition' => 'y'
                    ]
                ]],
                'summary_settings' => [[
                    'column_name' => 'decimal',
                    'summary_condition' => SummaryCondition::SUM
                ]],
            ];

            $summaries = $this->getCustomViewSummary($options);
            $defaults = $this->getCustomViewDataAll($options);

            foreach ($summaries as $key => $value) {
                $result = $defaults->filter(function($data) use($key){
                    if (isset($key) && isset($data['date'])) {
                        return \Carbon\Carbon::parse($data['date'])->year == $key;
                    } else {
                        return empty($key) && empty($data['date']);
                    }
                })->sum(function($data) {
                    return $data['decimal'];
                });
                $this->assertTrue(isMatchDecimal($value, $result));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = Group(date/YM), Summary(currency/sum)
     */
    public function testFuncGroupDateYM()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $options = [
                'view_kind_type' => ViewKindType::AGGREGATE,
                'column_settings' => [[
                    'column_name' => 'date',
                    'options' => [
                        'view_group_condition' => 'ym'
                    ]
                ]],
                'summary_settings' => [[
                    'column_name' => 'currency',
                    'summary_condition' => SummaryCondition::SUM
                ]],
            ];

            $summaries = $this->getCustomViewSummary($options);

            $defaults = $this->getCustomViewDataAll($options);

            foreach ($summaries as $key => $value) {
                $result = $defaults->filter(function($data) use($key){
                    if (!empty($key) && isset($data['date'])) {
                        return \Carbon\Carbon::parse($data['date'])->format('Y-m') == $key;
                    } else {
                        return empty($key) && empty($data['date']);
                    }
                })->sum(function($data) {
                    return $data['currency'];
                });
                $this->assertTrue(isMatchDecimal($value, $result));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = Group(date/YMD), Summary(time/min)
     */
    public function testFuncGroupDateYMD()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $options = [
                'view_kind_type' => ViewKindType::AGGREGATE,
                'column_settings' => [[
                    'column_name' => 'date',
                    'options' => [
                        'view_group_condition' => 'ymd'
                    ]
                ]],
                'summary_settings' => [[
                    'column_name' => 'time',
                    'summary_condition' => SummaryCondition::MIN
                ]],
            ];

            $summaries = $this->getCustomViewSummary($options);

            $defaults = $this->getCustomViewDataAll($options);

            foreach ($summaries as $key => $value) {
                $result = $defaults->filter(function($data) use($key){
                    if (!empty($key) && isset($data['date'])) {
                        return \Carbon\Carbon::parse($data['date'])->format('Y-m-d') == $key;
                    } else {
                        return empty($key) && empty($data['date']);
                    }
                })->map(function($data) {
                    return $data['time'];
                })->min();
                $this->assertTrue(isMatchString($value, $result));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = Group(date/M), Summary(datetime/max)
     */
    public function testFuncGroupDateM()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $options = [
                'view_kind_type' => ViewKindType::AGGREGATE,
                'column_settings' => [[
                    'column_name' => 'date',
                    'options' => [
                        'view_group_condition' => 'm'
                    ]
                ]],
                'summary_settings' => [[
                    'column_name' => 'datetime',
                ]],
            ];

            $summaries = $this->getCustomViewSummary($options);

            $defaults = $this->getCustomViewDataAll($options);

            foreach ($summaries as $key => $value) {
                $result = $defaults->filter(function($data) use($key){
                    if (!empty($key) && isset($data['date'])) {
                        return \Carbon\Carbon::parse($data['date'])->month == $key;
                    } else {
                        return empty($key) && empty($data['date']);
                    }
                })->map(function($data) {
                    return $data['datetime'];
                })->max();
                $this->assertTrue(isMatchString($value, $result));
            }
        } finally {
            DB::rollback();
        }
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
                    return [$column_name => array_get($data, $column_name)];
                } 
                return [$column_name => $data->getValue($column_name)];
            })->toArray();
        });
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
            $custom_view_column = CustomViewColumn::create([
                'custom_view_id' => $custom_view->id,
                'view_column_type' => $column_setting['condition_type'] ?? ConditionType::COLUMN,
                'view_column_table_id' => $custom_table->id,
                'view_column_target_id' => $this->getTargetColumnId($column_setting, $custom_table),
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