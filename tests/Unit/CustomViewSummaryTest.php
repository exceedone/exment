<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\ValueType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\System;
use Illuminate\Database\Eloquent\Model;

class CustomViewSummaryTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    /**
     * FilterOption = Group(id), Summary(id), Filter(id)
     */
    public function testFuncGroupId()
    {
        $this->init();

        $options = [
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
            $this->assertTrue(collect($defaults)->contains(function ($value) use ($summary) {
                return $value['id'] == $summary['value'];
            }));
        }
    }

    /**
     * FilterOption = Group(suuid), Summary(suuid/count)
     */
    public function testFuncGroupSuuid()
    {
        $this->init();

        $options = [
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

        foreach ($summaries as $summary) {
            $this->assertTrue($summary['value'] == '1');
        }
    }

    /**
     * FilterOption = Group(text), Summary(integer/sum)
     */
    public function testFuncGroupText()
    {
        $this->init();

        $options = [
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

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                return $data['text'] == $summary['key'];
            })->sum(function ($data) {
                return $data['integer'];
            });
            $this->assertTrue($result == $summary['value']);
        }
    }

    /**
     * FilterOption = Group(integer), Summary(date/min)
     */
    public function testFuncGroupInteger()
    {
        $this->init();

        $options = [
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

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                return $data['integer'] == $summary['key'];
            })->map(function ($data) {
                return $data['date'];
            })->min();
            $this->assertTrue($result == $summary['value']);
        }
    }

    /**
     * FilterOption = Group(date), Summary(currency/min)
     */
    public function testFuncGroupDate()
    {
        $this->init();

        $options = [
            'column_settings' => [[
                'column_name' => 'date',
            ]],
            'summary_settings' => [[
                'column_name' => 'currency',
                'summary_condition' => SummaryCondition::MIN
            ]],
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                if (!empty($summary['key']) && isset($data['date'])) {
                    return \Carbon\Carbon::parse($data['date'])->format('Y-m-d') == $summary['key'];
                } else {
                    return empty($summary['key']) && empty($data['date']);
                }
            })->map(function ($data) {
                return $data['currency'];
            })->min();
            $this->assertTrue(isMatchDecimal($summary['value'], $result));
        }
    }

    /**
     * FilterOption = Group(date/Y), Summary(decimal/sum)
     */
    public function testFuncGroupDateY()
    {
        $this->init();

        $options = [
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

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                if (isset($summary['key']) && isset($data['date'])) {
                    return \Carbon\Carbon::parse($data['date'])->year == $summary['key'];
                } else {
                    return empty($summary['key']) && empty($data['date']);
                }
            })->sum(function ($data) {
                return $data['decimal'];
            });
            $this->assertTrue(isMatchDecimal($summary['value'], $result));
        }
    }

    /**
     * FilterOption = Group(date/YM), Summary(currency/sum)
     */
    public function testFuncGroupDateYM()
    {
        $this->init();

        $options = [
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

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                if (isset($summary['key']) && isset($data['date'])) {
                    return \Carbon\Carbon::parse($data['date'])->format('Y-m') == $summary['key'];
                } else {
                    return empty($summary['key']) && empty($data['date']);
                }
            })->sum(function ($data) {
                return $data['currency'];
            });
            $this->assertTrue(isMatchDecimal($summary['value'], $result));
        }
    }

    /**
     * FilterOption = Group(date/YMD), Summary(time/min)
     */
    public function testFuncGroupDateYMD()
    {
        $this->init();

        $options = [
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

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                if (isset($summary['key']) && isset($data['date'])) {
                    return \Carbon\Carbon::parse($data['date'])->format('Y-m-d') == $summary['key'];
                } else {
                    return empty($summary['key']) && empty($data['date']);
                }
            })->map(function ($data) {
                return $data['time'];
            })->min();
            $this->assertTrue(isMatchString($summary['value'], $result));
        }
    }

    /**
     * FilterOption = Group(date/M), Summary(datetime/max)
     */
    public function testFuncGroupDateM()
    {
        $this->init();

        $options = [
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

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                if (!empty($summary['key']) && isset($data['date'])) {
                    return \Carbon\Carbon::parse($data['date'])->month == $summary['key'];
                } else {
                    return empty($summary['key']) && empty($data['date']);
                }
            })->map(function ($data) {
                return $data['datetime'];
            })->max();
            $this->assertTrue(isMatchString($summary['value'], $result));
        }
    }

    /**
     * FilterOption = Group(date/D), Summary(created_at/max)
     */
    public function testFuncGroupDateD()
    {
        $this->init();

        $options = [
            'column_settings' => [[
                'column_name' => 'date',
                'options' => [
                    'view_group_condition' => 'd'
                ]
            ]],
            'summary_settings' => [[
                'condition_type' => ConditionType::SYSTEM,
                'column_name' => 'created_at',
            ]],
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                if (!empty($summary['key']) && isset($data['date'])) {
                    return \Carbon\Carbon::parse($data['date'])->day == $summary['key'];
                } else {
                    return empty($summary['key']) && empty($data['date']);
                }
            })->map(function ($data) {
                return \Carbon\Carbon::parse($data['created_at']);
            })->max();

            $value = \Carbon\Carbon::parse($summary['value']);
            $this->assertTrue(isMatchString($value->format('YmdHis'), $result->format('YmdHis')));
        }
    }

    /**
     * FilterOption = Group(date/W), Summary(id/count)
     */
    public function testFuncGroupDateW()
    {
        $this->init();

        $options = [
            'column_settings' => [[
                'column_name' => 'date',
                'options' => [
                    'view_group_condition' => 'w'
                ]
            ]],
            'summary_settings' => [[
                'condition_type' => ConditionType::SYSTEM,
                'column_name' => 'id',
                'summary_condition' => SummaryCondition::COUNT
            ]],
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        $weekday = [0,1,2,3,4,5,6];

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary, $weekday) {
                if (!is_nullorempty($summary['key']) && !is_nullorempty($data['date'])) {
                    $week = $weekday[\Carbon\Carbon::parse($data['date'])->dayOfWeek];
                    return $week == $summary['key'];
                } else {
                    return is_nullorempty($summary['key']) && is_nullorempty($data['date']);
                }
            })->count();
            $this->assertMatch($summary['value'], $result);
        }
    }

    /**
     * FilterOption = Group(date/Time), Summary(integer/max)
     */
    public function testFuncGroupTime()
    {
        $this->init();

        $options = [
            'column_settings' => [[
                'column_name' => 'time',
            ]],
            'summary_settings' => [[
                'column_name' => 'integer',
            ]],
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                if (!empty($summary['key']) && isset($data['time'])) {
                    return $data['time'] == $summary['key'];
                } else {
                    return empty($summary['key']) && empty($data['time']);
                }
            })->map(function ($data) {
                return $data['integer'];
            })->max();
            $this->assertTrue(isMatchString($summary['value'], $result));
        }
    }

    /**
     * FilterOption = Group(datetime), Summary(suuid/max)
     */
    public function testFuncGroupDateTime()
    {
        $this->init();

        $options = [
            'column_settings' => [[
                'column_name' => 'datetime',
            ]],
            'summary_settings' => [[
                'condition_type' => ConditionType::SYSTEM,
                'column_name' => 'suuid',
            ]],
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                if (!empty($summary['key']) && isset($data['datetime'])) {
                    return \Carbon\Carbon::parse($data['datetime'])->format('Y-m-d H:i:s') == $summary['key'];
                } else {
                    return empty($summary['key']) && empty($data['datetime']);
                }
            })->map(function ($data) {
                return $data['suuid'];
            })->max();
            $this->assertTrue(isMatchDecimal($summary['value'], $result));
        }
    }

    /**
     * FilterOption = Group(datetime/YMD), Summary(decimal/max)
     */
    public function testFuncGroupDateTimeYMD()
    {
        $this->init();

        $options = [
            'column_settings' => [[
                'column_name' => 'datetime',
                'options' => [
                    'view_group_condition' => 'ymd'
                ]
            ]],
            'summary_settings' => [[
                'column_name' => 'decimal',
            ]],
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                if (!empty($summary['key']) && isset($data['datetime'])) {
                    return \Carbon\Carbon::parse($data['datetime'])->format('Y-m-d') == $summary['key'];
                } else {
                    return empty($summary['key']) && empty($data['datetime']);
                }
            })->map(function ($data) {
                return $data['decimal'];
            })->max();
            $this->assertTrue(isMatchDecimal($summary['value'], $result));
        }
    }

    /**
     * FilterOption = Group(select), Summary(id/count)
     */
    public function testFuncGroupSelect()
    {
        $this->commonTestCount('select');
    }

    /**
     * FilterOption = Group(select_multiple), Summary(id/count)
     */
    public function testFuncGroupSelectMultiple()
    {
        $this->commonTestCount('select_multiple');
    }

    /**
     * FilterOption = Group(select_valtext), Summary(id/count)
     */
    public function testFuncGroupSelectValue()
    {
        $this->commonTestCount('select_valtext');
    }

    /**
     * FilterOption = Group(select_valtext_multiple), Summary(id/count)
     */
    public function testFuncGroupSelectValueMultiple()
    {
        $this->commonTestCount('select_valtext_multiple');
    }

    /**
     * FilterOption = Group(select_table), Summary(id/count)
     */
    public function testFuncGroupSelectTable()
    {
        $this->commonTestCount('select_table');
    }

    /**
     * FilterOption = Group(select_table_multiple), Summary(id/count)
     */
    public function testFuncGroupSelectTableMultiple()
    {
        $this->commonTestCount('select_table_multiple');
    }

    /**
     * FilterOption = Group(yesno), Summary(id/count)
     */
    public function testFuncGroupYesNo()
    {
        $this->commonTestCount('yesno');
    }

    /**
     * FilterOption = Group(boolean), Summary(id/count)
     */
    public function testFuncGroupBoolean()
    {
        $this->commonTestCount('boolean');
    }

    /**
     * FilterOption = Group(auto_number), Summary(id/count)
     */
    public function testFuncGroupAutoNumber()
    {
        $this->commonTestCount('auto_number');
    }

    /**
     * FilterOption = Group(user), Summary(id/count)
     */
    public function testFuncGroupUser()
    {
        $this->commonTestCount('user');
    }

    /**
     * FilterOption = Group(user_multiple), Summary(id/count)
     */
    public function testFuncGroupUserMultiple()
    {
        $this->commonTestCount('user_multiple');
    }

    /**
     * FilterOption = Group(organization), Summary(id/count)
     */
    public function testFuncGroupOrganization()
    {
        $this->commonTestCount('organization');
    }

    /**
     * FilterOption = Group(organization_multiple), Summary(id/count)
     */
    public function testFuncGroupOrganizationMultiple()
    {
        $this->commonTestCount('organization_multiple');
    }

    /**
     * FilterOption = Group(created_at), Summary(id/count)
     */
    public function testFuncGroupCreatedAt()
    {
        $this->commonTestCount('created_at', ConditionType::SYSTEM);
    }

    /**
     * FilterOption = Group(created_at/YMD), Summary(id/count)
     */
    public function testFuncGroupCreatedAtYmd()
    {
        $this->commonTestCount('created_at', ConditionType::SYSTEM, [
            'view_group_condition' => 'ymd'
        ]);
    }

    /**
     * FilterOption = Group(updated_at), Summary(id/count)
     */
    public function testFuncGroupUpdatedAt()
    {
        $this->commonTestCount('updated_at', ConditionType::SYSTEM);
    }

    /**
     * FilterOption = Group(updated_at/YM), Summary(id/count)
     */
    public function testFuncGroupUpdatedAtYm()
    {
        $this->commonTestCount('updated_at', ConditionType::SYSTEM, [
            'view_group_condition' => 'ym'
        ]);
    }

    /**
     * FilterOption = Group(created_user), Summary(id/count)
     */
    public function testFuncGroupCreatedUser()
    {
        $this->commonTestCount('created_user', ConditionType::SYSTEM);
    }

    /**
     * FilterOption = Group(updated_user), Summary(id/count)
     */
    public function testFuncGroupUpdatedUser()
    {
        $this->commonTestCount('updated_user', ConditionType::SYSTEM);
    }

    /**
     * FilterOption = Group(select_table.id), Summary(id/count)
     */
    public function testFuncGroupSelectTableId()
    {
        $this->commonTestCountBase([
            'reference_table' => 'custom_value_view_all',
            'reference_column' => 'select_table',
            'column_name' => 'id',
            'condition_type' => ConditionType::SYSTEM,
        ]);
    }

    /**
     * FilterOption = Group(select_table.yesno), Summary(id/count)
     */
    public function testFuncGroupSelectTableYesNo()
    {
        $this->commonTestCountBase([
            'reference_table' => 'custom_value_view_all',
            'reference_column' => 'select_table',
            'column_name' => 'multiples_of_3',
        ]);
    }

    /**
     * FilterOption = Group(user.user_name), Summary(id/count)
     */
    public function testFuncGroupUserTableUserName()
    {
        $this->commonTestCountBase([
            'reference_table' => 'user',
            'reference_column' => 'user',
            'column_name' => 'user_name',
        ]);
    }

    /**
     * FilterOption = Group(organization.parent_organization), Summary(id/count)
     */
    public function testFuncGroupOrgTableParentOrg()
    {
        $this->commonTestCountBase([
            'reference_table' => 'organization',
            'reference_column' => 'organization',
            'column_name' => 'parent_organization',
        ]);
    }

    /**
     * FilterOption = Group(parent:multiples_of_3), Summary(integer/sum/child_table)
     */
    public function testFuncSummaryChild()
    {
        $this->init();

        $options = [
            'target_table_name' => 'parent_table',
            'column_settings' => [[
                'column_name' => 'multiples_of_3',
            ]],
            'summary_settings' => [[
                'reference_table' => 'child_table',
                'is_child' => true,
                'column_name' => 'integer',
                'summary_condition' => SummaryCondition::SUM
            ]],
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                return $data['multiples_of_3'] == $summary['key'];
            })->sum(function ($data) {
                return collect($data['child_table.integer'])->sum();
            });
            $this->assertTrue($result == $summary['value']);
        }
    }

    /**
     * FilterOption = Group(parent:date:YM), Min(date/child_table)
     */
    public function testFuncSummaryChild2()
    {
        $this->init();

        $options = [
            'target_table_name' => 'parent_table',
            'column_settings' => [[
                'column_name' => 'date',
                'options' => [
                    'view_group_condition' => 'ym'
                ]
            ]],
            'summary_settings' => [[
                'reference_table' => 'child_table',
                'is_child' => true,
                'column_name' => 'date',
                'summary_condition' => SummaryCondition::MIN
            ]],
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                if (isset($summary['key']) && isset($data['date'])) {
                    return \Carbon\Carbon::parse($data['date'])->format('Y-m') == $summary['key'];
                } else {
                    return empty($summary['key']) && empty($data['date']);
                }
            })->min(function ($data) {
                return collect($data['child_table.date'])->min();
            });
            $this->assertTrue($result == $summary['value']);
        }
    }

    /**
     * FilterOption = Group(parent:multiples_of_3), Summary(integer/sum/child_table)
     */
    public function testFuncSummaryChildDelete()
    {
        $this->init();

        $options = [
            'target_table_name' => 'parent_table',
            'column_settings' => [[
                'column_name' => 'multiples_of_3',
            ]],
            'summary_settings' => [[
                'reference_table' => 'child_table',
                'is_child' => true,
                'column_name' => 'integer',
                'summary_condition' => SummaryCondition::SUM
            ]],
        ];

        $target = CustomTable::getEloquent('child_table')->getValueModel()->offset(10)->first();
        $target->delete();

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                return $data['multiples_of_3'] == $summary['key'];
            })->sum(function ($data) {
                return collect($data['child_table.integer'])->sum();
            });
            $this->assertTrue($result == $summary['value']);
        }
    }

    /**
     * FilterOption = Group(select_table:date:YM), Sum(integer)
     */
    public function testFuncSummarySelect()
    {
        $this->init();

        $options = [
            'target_table_name' => 'parent_table_select',
            'column_settings' => [[
                'column_name' => 'date',
                'reference_table' => 'child_table_select',
                'reference_column' => 'parent_select_table',
                'is_refer' => true,
                'options' => [
                    'view_group_condition' => 'ym'
                ]
            ]],
            'summary_settings' => [[
                'column_name' => 'integer',
                'summary_condition' => SummaryCondition::SUM
            ]],
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = CustomTable::getEloquent('child_table_select')->getValueModel()->all();

        foreach ($summaries as $summary) {
            $ids = collect($defaults)->filter(function ($data) use ($summary) {
                $value = $data->getValue('date');
                if (isset($summary['key']) && isset($value)) {
                    return \Carbon\Carbon::parse($value)->format('Y-m') == $summary['key'];
                } else {
                    return empty($summary['key']) && empty($value);
                }
            })->map(function ($data) {
                return $data->getValue('parent_select_table', ValueType::PURE_VALUE);
            })->filter();

            $result = CustomTable::getEloquent('parent_table_select')->getValueModel()->find($ids)->sum(function($val) {
                return $val->getValue('integer');
            });
            $this->assertTrue($result == $summary['value']);
        }
    }

    /**
     * FilterOption = Group(select_table:date:w), Sum(integer)
     */
    public function testFuncSummarySelectDelete()
    {
        $this->init();

        $options = [
            'target_table_name' => 'parent_table_select',
            'column_settings' => [[
                'column_name' => 'date',
                'reference_table' => 'child_table_select',
                'reference_column' => 'parent_select_table',
                'is_refer' => true,
                'options' => [
                    'view_group_condition' => 'w'
                ]
            ]],
            'summary_settings' => [[
                'column_name' => 'integer',
                'summary_condition' => SummaryCondition::SUM
            ]],
        ];

        $target = CustomTable::getEloquent('parent_table_select')->getValueModel()->offset(5)->first();
        $target->delete();

        $summaries = $this->getCustomViewSummary($options);

        $defaults = CustomTable::getEloquent('child_table_select')->getValueModel()->all();

        foreach ($summaries as $summary) {
            $ids = collect($defaults)->filter(function ($data) use ($summary) {
                $value = $data->getValue('date');
                if (isset($summary['key']) && isset($value)) {
                    $week = \Carbon\Carbon::parse($value)->format('w');
                    return $week == $summary['key'];
                } else {
                    return empty($summary['key']) && empty($value);
                }
            })->map(function ($data) {
                return $data->getValue('parent_select_table', ValueType::PURE_VALUE);
            })->filter();

            $result = CustomTable::getEloquent('parent_table_select')->getValueModel()->find($ids)->sum(function($val) {
                return $val->getValue('integer');
            });
            $this->assertTrue($result == $summary['value']);
        }
    }

    /**
     * FilterOption = Group(parent_table.user), Summary(integer/max)
     */
    public function testFuncGroupParent()
    {
        $this->init();

        $options = [
            'target_table_name' => 'child_table',
            'column_settings' => [[
                'reference_table' => 'parent_table',
                'column_name' => 'user',
                'is_parent' => true
            ]],
            'summary_settings' => [[
                'column_name' => 'integer',
            ]],
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                return $data['parent_table.user'] == $summary['key'];
            })->max(function ($data) {
                return collect($data['integer'])->max();
            });
            $this->assertTrue($result == $summary['value']);
        }
    }

    /**
     * FilterOption = Group(parent_table.user, odd_even), Summary(integer/sum)
     */
    public function testFuncGroupParentChild()
    {
        $this->init();

        $options = [
            'target_table_name' => 'child_table',
            'column_settings' => [[
                'reference_table' => 'parent_table',
                'column_name' => 'user',
                'is_parent' => true
            ], [
                'column_name' => 'odd_even',
            ]],
            'summary_settings' => [[
                'column_name' => 'integer',
                'summary_condition' => SummaryCondition::SUM
            ]],
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                $key = $summary['key'];
                return $data['parent_table.user'] == $key[0] && $data['odd_even'] == $key[1];
            })->sum(function ($data) {
                return $data['integer'];
            });
            $this->assertTrue($result == $summary['value']);
        }
    }

    /**
     * FilterOption = Group(updated_user), Summary(id/count/child_table_n_n)
     */
    public function testFuncSummaryChildNN()
    {
        $this->init();

        $options = [
            'target_table_name' => 'parent_table_n_n',
            'column_settings' => [[
                'condition_type' => ConditionType::SYSTEM,
                'column_name' => 'updated_user',
            ]],
            'summary_settings' => [[
                'reference_table' => 'child_table_n_n',
                'is_child' => true,
                'condition_type' => ConditionType::SYSTEM,
                'column_name' => 'id',
                'summary_condition' => SummaryCondition::COUNT
            ]],
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                return $data['updated_user'] == $summary['key'];
            })->map(function ($data) {
                return count($data['child_table_n_n.id']);
            })->sum();
            $this->assertTrue($result == $summary['value']);
        }
    }

    /**
     * FilterOption = Group(odd_even), Summary(id/count/child_table_n_n)
     */
    public function testFuncSummaryChildNNDelete()
    {
        $this->init();

        $options = [
            'target_table_name' => 'parent_table_n_n',
            'column_settings' => [[
                'column_name' => 'odd_even',
            ]],
            'summary_settings' => [[
                'reference_table' => 'child_table_n_n',
                'is_child' => true,
                'condition_type' => ConditionType::SYSTEM,
                'column_name' => 'id',
                'summary_condition' => SummaryCondition::COUNT
            ]],
        ];

        $target = CustomTable::getEloquent('child_table_n_n')->getValueModel()->offset(10)->first();
        $target->delete();

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                return $data['odd_even'] == $summary['key'];
            })->map(function ($data) {
                return count($data['child_table_n_n.id']);
            })->sum();
            $this->assertTrue($result == $summary['value']);
        }
    }

    /**
     * FilterOption = Group(odd_even), Summary(id/count/parent_table_n_n)
     */
    public function testFuncSummaryParentNNDelete()
    {
        $this->init();

        $options = [
            'target_table_name' => 'child_table_n_n',
            'column_settings' => [[
                'column_name' => 'odd_even',
            ]],
            'summary_settings' => [[
                'reference_table' => 'parent_table_n_n',
                'is_parent' => true,
                'condition_type' => ConditionType::SYSTEM,
                'column_name' => 'id',
                'summary_condition' => SummaryCondition::COUNT
            ]],
        ];

        $target = CustomTable::getEloquent('parent_table_n_n')->getValueModel()->offset(10)->first();
        $target->delete();

        $summaries = $this->getCustomViewSummary($options);

        $defaults = CustomTable::getEloquent('child_table_n_n')->getValueModel()->get();

        $relation = CustomRelation::getRelationByParentChild('parent_table_n_n', 'child_table_n_n', RelationType::MANY_TO_MANY);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                return $data->getValue('odd_even') == $summary['key'];
            })->map(function ($data) use($relation) {
                return $data->getParentValue($relation)->count();
            })->sum();
            $this->assertTrue($result == $summary['value']);
        }
    }

    /**
     * FilterOption = Group(workflow_status), Summary(currency/sum)
     */
    public function testFuncGroupWorkflow()
    {
        $this->init();

        $options = [
            'target_table_name' => 'custom_value_edit',
            'column_settings' => [[
                'condition_type' => ConditionType::WORKFLOW,
                'column_name' => 'workflow_status',
            ]],
            'summary_settings' => [[
                'column_name' => 'currency',
                'summary_condition' => SummaryCondition::SUM
            ]],
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary) {
                return $data['workflow_status'] == $summary['key'];
            })->sum(function ($data) {
                return $data['currency'];
            });
            $this->assertTrue($result == $summary['value']);
        }
    }

    protected function commonTestCount($column_name, $condition_type = ConditionType::COLUMN, $column_options = [])
    {
        $this->commonTestCountBase([
            'column_name' => $column_name,
            'condition_type' => $condition_type,
            'options' => $column_options
        ]);
    }

    protected function commonTestCountBase($column_settings)
    {
        $this->init();

        $options = [
            'column_settings' => [$column_settings],
            'summary_settings' => [[
                'condition_type' => ConditionType::SYSTEM,
                'column_name' => 'id',
                'summary_condition' => SummaryCondition::COUNT
            ]],
            'offset' => 0,
            'limit' => 80,
        ];

        $summaries = $this->getCustomViewSummary($options);

        $defaults = $this->getCustomViewDataAll($options);

        foreach ($summaries as $summary) {
            $result = collect($defaults)->filter(function ($data) use ($summary, $column_settings) {
                $reference_table = array_get($column_settings, 'reference_table');
                $column_name = array_get($column_settings, 'column_name');
                $column_options = array_get($column_settings, 'options')?? [];
                $group_condition = array_get($column_options, 'view_group_condition');

                if (isset($reference_table)) {
                    $column_data = $data["$reference_table.$column_name"];
                } else {
                    $column_data = $data[$column_name];
                }

                if (!is_null($summary['key']) && isset($column_data)) {
                    if (is_array(json_decode_ex($summary['key']))) {
                        return isMatchArray($column_data, json_decode_ex($summary['key']));
                    } elseif (is_array($column_data)) {
                        return false;
                    } elseif ($column_data instanceof \Carbon\Carbon) {
                        $column_data = $this->convertDateToString($column_data, $group_condition);
                    }
                    return isMatchString($column_data, $summary['key']);
                } else {
                    return is_null($summary['key']) && (is_null($column_data));
                }
            })->count();
            $this->assertTrue(isMatchString($summary['value'], $result));
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
    protected function getCustomViewSummary($options)
    {
        return $this->getCustomViewData($options, ViewKindType::AGGREGATE)->map(function ($data) {
            $values = array_values($data->getAttributes());
            $key = count($values) > 2 ? array_slice($values, 0, count($values) - 1) : $values[0];
            return [
                'key' => $key,
                'value' => end($values)
            ];
        });
    }

    protected function getCustomViewDataCount($options)
    {
        System::clearCache();
        unset($options['column_settings']);
        unset($options['summary_settings']);
        $options['get_count'] = true;
        return $this->getCustomViewData($options);
    }

    protected function getCustomViewDataAll($options)
    {
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

        return $this->getCustomViewData($options)->map(function ($data) use ($column_settings) {
            return collect($column_settings)->mapWithKeys(function ($column_setting) use ($data) {
                $column_setting = array_merge(
                    [
                        'column_name' => null,
                        'condition_type' => ConditionType::COLUMN,
                        'reference_table' => null,
                        'reference_column' => null,
                        'is_parent' => false,
                        'is_child' => false,
                    ],
                    $column_setting
                );
                $column_name = array_get($column_setting, 'column_name');
                $condition_type = array_get($column_setting, 'condition_type');
                $reference_table = array_get($column_setting, 'reference_table');
                $reference_column = array_get($column_setting, 'reference_column');
                $is_parent = array_get($column_setting, 'is_parent');
                $is_child = array_get($column_setting, 'is_child');

                if (isset($reference_table)) {
                    $reference_key = "$reference_table.$column_name";
                    if ($is_parent) {
                        $data = $data->getParentValue();
                    } elseif ($is_child) {
                        $data_list = $data->getChildrenValues($reference_table)->map(function ($row) use ($column_name, $condition_type) {
                            return $this->getColumnValue($row, $column_name, $condition_type);
                        })->toArray();
                        return [$reference_key => $data_list];
                    } elseif (isset($reference_column)) {
                        $data = $data->getValue($reference_column);
                    }
                }
                $column_data = $this->getColumnValue($data, $column_name, $condition_type);

                if ($column_data instanceof \Illuminate\Support\Collection) {
                    $column_data = $column_data->map(function ($item) {
                        if ($item instanceof Model) {
                            /** @var mixed $item */
                            return $item->id;
                        }
                        return $item;
                    });
                } elseif ($column_data instanceof Model) {
                    $column_data = $column_data->id;
                }
                return [($reference_key ?? $column_name) => $column_data];
            });
        })->toArray();
    }

    protected function getColumnValue($data, $column_name, $condition_type)
    {
        $column_data = null;
        if (isset($condition_type) && $condition_type == ConditionType::SYSTEM) {
            $column_id = SystemColumn::getOption(['name' => $column_name])['sqlname'];
            $column_data = array_get($data, $column_id);
        } elseif (isset($condition_type) && $condition_type == ConditionType::WORKFLOW) {
            $column_data = array_get($data, $column_name);
        } elseif ($data instanceof CustomValue) {
            $column_data = $data->getValue($column_name);
        }
        return $column_data;
    }

    protected function init()
    {
        $this->initAllTest();
    }
}
