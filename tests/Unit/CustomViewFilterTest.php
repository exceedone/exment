<?php
namespace Exceedone\Exment\Tests\Unit;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemColumn;
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
    /**
     * FilterOption = EQ
     */
    public function testFuncFilterEq()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'text',
                'filter_condition' => FilterOption::EQ,
                'filter_value_text' => 'text_2'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.text') == $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NE
     */
    public function testFuncFilterNe()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'yesno',
                'filter_condition' => FilterOption::NE,
                'filter_value_text' => 1
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.yesno') != $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NE
     */
    public function testFuncFilterNotNull()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'text',
                'filter_condition' => FilterOption::NOT_NULL,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.text') !== null);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NULL
     */
    public function testFuncFilterNull()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'text',
                'filter_condition' => FilterOption::NULL,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.text') === null);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = LIKE
     */
    public function testFuncFilterLike()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'text',
                'filter_condition' => FilterOption::LIKE,
                'filter_value_text' => 'text_1'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(strpos(array_get($data, 'value.text'), $filter_settings[0]['filter_value_text']) === 0);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NOT LIKE
     */
    public function testFuncFilterNotLike()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'text',
                'filter_condition' => FilterOption::NOT_LIKE,
                'filter_value_text' => 'text_1'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(strpos(array_get($data, 'value.text'), $filter_settings[0]['filter_value_text']) !== 0);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY
     */
    public function testFuncFilterDayOn()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_ON,
                'filter_value_text' => '2020-01-01'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.date') == $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption >= DAY
     */
    public function testFuncFilterDayOnOrAfter()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_ON_OR_AFTER,
                'filter_value_text' => '2020-01-01'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            $base_date = \Carbon\Carbon::parse($filter_settings[0]['filter_value_text']);
            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
                $this->assertTrue($date >= $base_date);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption <= DAY
     */
    public function testFuncFilterDayOnOrBefore()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_ON_OR_BEFORE,
                'filter_value_text' => '2020-01-01'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            $base_date = \Carbon\Carbon::parse($filter_settings[0]['filter_value_text']);
            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
                $this->assertTrue($date <= $base_date);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY NOT NULL
     */
    public function testFuncFilterDayNotNull()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_NOT_NULL,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.date') !== null);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY NULL
     */
    public function testFuncFilterDayNull()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_NULL,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.date') === null);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY TODAY
     */
    public function testFuncFilterDayToday()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_TODAY,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            $target_value = \Carbon\Carbon::now()->format('Y-m-d');
            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.date') === $target_value);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY TODAY OR AFTER
     */
    public function testFuncFilterDayTodayOrAfter()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_TODAY_OR_AFTER,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            $target_value = \Carbon\Carbon::now()->format('Y-m-d');
            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.date') >= $target_value);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY TODAY OR BEFORE
     */
    public function testFuncFilterDayTodayOrBefore()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_TODAY_OR_BEFORE,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            $target_value = \Carbon\Carbon::now()->format('Y-m-d');
            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.date') <= $target_value);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY YESTERDAY
     */
    public function testFuncFilterDayYesterday()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_YESTERDAY,
            ]];
            $array = $this->getColumnFilterData($filter_settings);
            $target_value = \Carbon\Carbon::yesterday()->format('Y-m-d');

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.date') === $target_value);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY TOMORROW
     */
    public function testFuncFilterDayTomorrow()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_TOMORROW,
            ]];
            $array = $this->getColumnFilterData($filter_settings);
            $target_value = \Carbon\Carbon::tomorrow()->format('Y-m-d');

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.date') === $target_value);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY THIS MONTH
     */
    public function testFuncFilterDayThisMonth()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_THIS_MONTH,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

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
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_LAST_MONTH,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
                $this->assertTrue($date->isLastMonth());
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY NEXT MONTH
     */
    public function testFuncFilterDayNextMonth()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_NEXT_MONTH,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
                $this->assertTrue($date->isNextMonth());
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY THIS YEAR
     */
    public function testFuncFilterDayThisYear()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_THIS_YEAR,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
                $this->assertTrue($date->isCurrentYear());
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY LAST YEAR
     */
    public function testFuncFilterDayLastYear()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_LAST_YEAR,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
                $this->assertTrue($date->isLastYear());
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY NEXT YEAR
     */
    public function testFuncFilterDayNextYear()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_NEXT_YEAR,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
                $this->assertTrue($date->isNextYear());
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY LAST X DAY OR AFTER
     */
    public function testFuncFilterDayLastXDayOrAfter()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_LAST_X_DAY_OR_AFTER,
                'filter_value_text' => 3
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
                $diff = \Carbon\Carbon::today()->diffInDays($date, false);
                $this->assertTrue($diff >= -3);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY LAST X DAY OR BEFORE
     */
    public function testFuncFilterDayLastXDayOrBefore()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_LAST_X_DAY_OR_BEFORE,
                'filter_value_text' => 3
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
                $diff = \Carbon\Carbon::today()->diffInDays($date, false);
                $this->assertTrue($diff <= -3);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY NEXT X DAY OR AFTER
     */
    public function testFuncFilterDayNextXDayOrAfter()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_NEXT_X_DAY_OR_AFTER,
                'filter_value_text' => 3
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
                $diff = \Carbon\Carbon::today()->diffInDays($date, false);
                $this->assertTrue($diff >= 3);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DAY NEXT X DAY OR BEFORE
     */
    public function testFuncFilterDayNextXDayOrBefore()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_NEXT_X_DAY_OR_BEFORE,
                'filter_value_text' => 3
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.date'));
                $diff = \Carbon\Carbon::today()->diffInDays($date, false);
                $this->assertTrue($diff <= 3);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = Time
     */
    public function testFuncFilterTimeEq()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'time',
                'filter_condition' => FilterOption::EQ,
                'filter_value_text' => '02:02:02'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.time') == $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption <> Time
     */
    public function testFuncFilterTimeNe()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'time',
                'filter_condition' => FilterOption::NE,
                'filter_value_text' => '02:02:02'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.time') !== $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = DateTime
     */
    public function testFuncFilterDateTimeOn()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'datetime',
                'filter_condition' => FilterOption::DAY_ON,
                'filter_value_text' => \Carbon\Carbon::today()->format('Y-m-d')
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.datetime'));
                $this->assertTrue($date->format('Y-m-d') == $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption >= DateTime
     */
    public function testFuncFilterDateTimeOnOrAfter()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'datetime',
                'filter_condition' => FilterOption::DAY_ON_OR_AFTER,
                'filter_value_text' => \Carbon\Carbon::today()->format('Y-m-d')
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.datetime'));
                $diff = \Carbon\Carbon::today()->diffInDays($date, false);
                $this->assertTrue($diff >= 0);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption <= DateTime
     */
    public function testFuncFilterDateTimeOnOrBefore()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'datetime',
                'filter_condition' => FilterOption::DAY_ON_OR_BEFORE,
                'filter_value_text' => \Carbon\Carbon::today()->format('Y-m-d')
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'value.datetime'));
                $diff = \Carbon\Carbon::today()->diffInDays($date, false);
                $this->assertTrue($diff <= 0);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = USER_EQ
     */
    public function testFuncFilterUserEq()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $user_id = TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC;
            $filter_settings = [[
                'column_name' => 'user',
                'filter_condition' => FilterOption::USER_EQ,
                'filter_value_text' => $user_id
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.user') == $user_id);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = USER_NE
     */
    public function testFuncFilterUserNe()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $user_id = TestDefine::TESTDATA_USER_LOGINID_ADMIN;
            $filter_settings = [[
                'column_name' => 'user',
                'filter_condition' => FilterOption::USER_NE,
                'filter_value_text' => $user_id
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.user') !== $user_id);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = USER_NOT_NULL
     */
    public function testFuncFilterUserNotNull()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'user',
                'filter_condition' => FilterOption::USER_NOT_NULL,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.user') !== null);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = USER_NULL
     */
    public function testFuncFilterUserNull()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'user',
                'filter_condition' => FilterOption::USER_NULL,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.user') === null);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = USER_EQ_USER
     */
    public function testFuncFilterLoginUser()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'user',
                'filter_condition' => FilterOption::USER_EQ_USER,
            ]];
            $array = $this->getColumnFilterData($filter_settings, ['login_user_id' => TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC]);

            $user_id = \Exment::getUserId();
            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.user') == $user_id);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = USER_NE_USER
     */
    public function testFuncFilterNotLoginUser()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'user',
                'filter_condition' => FilterOption::USER_NE_USER,
            ]];
            $array = $this->getColumnFilterData($filter_settings, ['login_user_id' => TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC]);

            $user_id = \Exment::getUserId();
            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.user') !== $user_id);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_EXISTS(user/multiple)
     */
    public function testFuncFilterUserEqMulti()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $target_value = TestDefine::TESTDATA_USER_LOGINID_USER2;
            $filter_settings = [[
                'column_name' => 'user_multiple',
                'filter_condition' => FilterOption::SELECT_EXISTS,
                'filter_value_text' => $target_value
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(in_array($filter_settings[0]['filter_value_text'], array_get($data, 'value.user_multiple')));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NOT_NULL(user/multiple)
     */
    public function testFuncFilterUserNotNullMulti()
    {
        $this->skipTempTestIfTrue(true, 'Now multi and not null filter is bug.');

        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'user_multiple',
                'filter_condition' => FilterOption::NOT_NULL,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(!empty(array_get($data, 'value.user_multiple')));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_EXISTS(organization)
     */
    public function testFuncFilterOrganizationExists()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $target_value = TestDefine::TESTDATA_ORGANIZATION_DEV;
            $filter_settings = [[
                'column_name' => 'organization',
                'filter_condition' => FilterOption::SELECT_EXISTS,
                'filter_value_text' => $target_value
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.organization') == $target_value);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_NOT_EXISTS(organization/multiple)
     */
    public function testFuncFilterOrganizationNotExists()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $target_value = TestDefine::TESTDATA_ORGANIZATION_DEV;
            $filter_settings = [[
                'column_name' => 'organization_multiple',
                'filter_condition' => FilterOption::SELECT_NOT_EXISTS,
                'filter_value_text' => $target_value
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertFalse(in_array($filter_settings[0]['filter_value_text'], array_get($data, 'value.organization_multiple')));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NULL(organization/multiple)
     */
    public function testFuncFilterOrganizationNullMulti()
    {
        $this->skipTempTestIfTrue(true, 'Now multi and not null filter is bug.');
        
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'organization_multiple',
                'filter_condition' => FilterOption::NULL,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(empty(array_get($data, 'value.organization_multiple')));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NUMBER_GT
     */
    public function testFuncFilterNumberGt()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'integer',
                'filter_condition' => FilterOption::NUMBER_GT,
                'filter_value_text' => 1000
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.integer') > $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NUMBER_LT
     */
    public function testFuncFilterNumberLt()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'integer',
                'filter_condition' => FilterOption::NUMBER_LT,
                'filter_value_text' => 1000
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.integer') < $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }


    /**
     * FilterOption = NUMBER_GTE
     */
    public function testFuncFilterNumberGte()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'integer',
                'filter_condition' => FilterOption::NUMBER_GTE,
                'filter_value_text' => 1000
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.integer') >= $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NUMBER_LTE
     */
    public function testFuncFilterNumberLte()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'integer',
                'filter_condition' => FilterOption::NUMBER_LTE,
                'filter_value_text' => 1000
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.integer') <= $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NUMBER_GT
     */
    public function testFuncFilterNumberGtDec()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $target_value = 0.36;
            $filter_settings = [[
                'column_name' => 'decimal',
                'filter_condition' => FilterOption::NUMBER_GT,
                'filter_value_text' => "$target_value"
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.decimal') > $target_value);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NUMBER_LT
     */
    public function testFuncFilterNumberLtDec()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $target_value = 0.36;
            $filter_settings = [[
                'column_name' => 'decimal',
                'filter_condition' => FilterOption::NUMBER_LT,
                'filter_value_text' => "$target_value"
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.decimal') < $target_value);
            }
        } finally {
            DB::rollback();
        }
    }


    /**
     * FilterOption = NUMBER_GTE
     */
    public function testFuncFilterNumberGteDec()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $target_value = 0.36;
            $filter_settings = [[
                'column_name' => 'decimal',
                'filter_condition' => FilterOption::NUMBER_GTE,
                'filter_value_text' => "$target_value"
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.decimal') >= $target_value);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NUMBER_LTE
     */
    public function testFuncFilterNumberLteDec()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $target_value = 0.36;
            $filter_settings = [[
                'column_name' => 'decimal',
                'filter_condition' => FilterOption::NUMBER_LTE,
                'filter_value_text' => "$target_value"
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.decimal') <= $target_value);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_EXISTS
     */
    public function testFuncFilterSelectExists()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'select',
                'filter_condition' => FilterOption::SELECT_EXISTS,
                'filter_value_text' => 'bar'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.select') === $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_NOT_EXISTS
     */
    public function testFuncFilterSelectNotExists()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'select',
                'filter_condition' => FilterOption::SELECT_NOT_EXISTS,
                'filter_value_text' => 'bar'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.select') !== $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_EXISTS(select_valtext)
     */
    public function testFuncFilterSelectExistsVal()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'select_valtext',
                'filter_condition' => FilterOption::SELECT_EXISTS,
                'filter_value_text' => 'bar'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.select_valtext') === $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_NOT_EXISTS(select_valtext)
     */
    public function testFuncFilterSelectNotExistsVal()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'select_valtext',
                'filter_condition' => FilterOption::SELECT_NOT_EXISTS,
                'filter_value_text' => 'bar'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.select_valtext') !== $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_EXISTS(select_table)
     */
    public function testFuncFilterSelectExistsTable()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'select_table',
                'filter_condition' => FilterOption::SELECT_EXISTS,
                'filter_value_text' => 2
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.select_table') === $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_NOT_EXISTS(select_table)
     */
    public function testFuncFilterSelectNotExistsTable()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'select_table',
                'filter_condition' => FilterOption::SELECT_NOT_EXISTS,
                'filter_value_text' => 2
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.select_table') !== $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_EXISTS(multiple select)
     */
    public function testFuncFilterSelectExistsMulti()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'select_multiple',
                'filter_condition' => FilterOption::SELECT_EXISTS,
                'filter_value_text' => 'bar'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(in_array($filter_settings[0]['filter_value_text'], array_get($data, 'value.select_multiple')));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_NOT_EXISTS(multiple select)
     */
    public function testFuncFilterSelectNotExistsMulti()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'select_multiple',
                'filter_condition' => FilterOption::SELECT_NOT_EXISTS,
                'filter_value_text' => 'foo'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertFalse(in_array($filter_settings[0]['filter_value_text'], array_get($data, 'value.select_multiple')));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_EXISTS(select_valtext/multiple select)
     */
    public function testFuncFilterSelectExistsValMulti()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'select_valtext_multiple',
                'filter_condition' => FilterOption::SELECT_EXISTS,
                'filter_value_text' => 'bar'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(in_array($filter_settings[0]['filter_value_text'], array_get($data, 'value.select_valtext_multiple')));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_NOT_EXISTS(select_valtext/multiple select)
     */
    public function testFuncFilterSelectNotExistsValMulti()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'select_valtext_multiple',
                'filter_condition' => FilterOption::SELECT_NOT_EXISTS,
                'filter_value_text' => 'baz'
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertFalse(in_array($filter_settings[0]['filter_value_text'], array_get($data, 'value.select_valtext_multiple')));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_EXISTS(select_table/multiple select)
     */
    public function testFuncFilterSelectExistsTableMulti()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'select_table_multiple',
                'filter_condition' => FilterOption::SELECT_EXISTS,
                'filter_value_text' => 2
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(in_array($filter_settings[0]['filter_value_text'], array_get($data, 'value.select_table_multiple')));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = SELECT_NOT_EXISTS(select_table/multiple select)
     */
    public function testFuncFilterSelectNotExistsTableMulti()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'select_table_multiple',
                'filter_condition' => FilterOption::SELECT_NOT_EXISTS,
                'filter_value_text' => 4
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertFalse(in_array($filter_settings[0]['filter_value_text'], array_get($data, 'value.select_table_multiple')));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = like id
     */
    public function testFuncFilterIdLike()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'id',
                'condition_type' => ConditionType::SYSTEM,
                'filter_condition' => FilterOption::LIKE,
                'filter_value_text' => 8
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(strpos(strval(array_get($data, 'id')), strval($filter_settings[0]['filter_value_text'])) === 0);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = created_at
     */
    public function testFuncFilterCreatedAtDayOn()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'created_at',
                'condition_type' => ConditionType::SYSTEM,
                'filter_condition' => FilterOption::DAY_ON,
                'filter_value_text' => \Carbon\Carbon::now()->format('Y-m-d')
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'created_at'));
                $this->assertTrue($date->isSameDay(\Carbon\Carbon::today()));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = updated_at is today
     */
    public function testFuncFilterUpdatedAtToday()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'updated_at',
                'condition_type' => ConditionType::SYSTEM,
                'filter_condition' => FilterOption::DAY_TODAY,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $date = \Carbon\Carbon::parse(array_get($data, 'updated_at'));
                $this->assertTrue($date->isSameDay(\Carbon\Carbon::today()));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = created_user is login user
     */
    public function testFuncFilterCreatedUserEqUser()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'created_user',
                'condition_type' => ConditionType::SYSTEM,
                'filter_condition' => FilterOption::USER_EQ_USER,
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            $user_id = \Exment::getUserId();
            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'created_user_id') == $user_id);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = updated_user is not target user
     */
    public function testFuncFilterUpdatedUserNe()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'updated_user',
                'condition_type' => ConditionType::SYSTEM,
                'filter_condition' => FilterOption::NE,
                'filter_value_text' => TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC
            ]];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'updated_user_id') !== TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = EQ(parent_id/1:N relation)
     */
    public function testFuncParentIdEq()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'parent_id',
                'condition_type' => ConditionType::PARENT_ID,
                'filter_condition' => FilterOption::EQ,
                'filter_value_text' => '2'
            ]];
            $array = $this->getColumnFilterData($filter_settings, ['target_table_name' => 'child_table']);

            foreach ($array as $data) {
                $parent_value = $data->getParentValue();
                $this->assertTrue(isset($parent_value));
                $this->assertTrue($parent_value->id == $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NE(parent_id/1:N relation)
     */
    public function testFuncParentIdNe()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'parent_id',
                'condition_type' => ConditionType::PARENT_ID,
                'filter_condition' => FilterOption::NE,
                'filter_value_text' => '2'
            ]];
            $array = $this->getColumnFilterData($filter_settings, ['target_table_name' => 'child_table']);

            foreach ($array as $data) {
                $parent_value = $data->getParentValue();
                $this->assertTrue(isset($parent_value));
                $this->assertFalse($parent_value->id == $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = EQ(workflow status)
     */
    public function testFuncWorkflowStatusEq()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'workflow_status',
                'condition_type' => ConditionType::WORKFLOW,
                'filter_condition' => FilterOption::EQ,
                'filter_value_text' => '7'
            ]];
            $array = $this->getColumnFilterData($filter_settings, ['target_table_name' => 'custom_value_edit']);

            foreach ($array as $data) {
                $workflow_status = array_get($data, 'workflow_status');
                $this->assertTrue($workflow_status->id == $filter_settings[0]['filter_value_text']);
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = NE(workflow status)
     */
    public function testFuncWorkflowStatusNe()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'workflow_status',
                'condition_type' => ConditionType::WORKFLOW,
                'filter_condition' => FilterOption::NE,
                'filter_value_text' => 'start'
            ]];
            $array = $this->getColumnFilterData($filter_settings, ['target_table_name' => 'custom_value_edit']);

            foreach ($array as $data) {
                $workflow_status = array_get($data, 'workflow_status');
                $this->assertFalse(is_null($workflow_status));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = EQ(workflow user)
     */
    public function testFuncWorkflowUser()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'workflow_work_users',
                'condition_type' => ConditionType::WORKFLOW,
                'filter_condition' => FilterOption::USER_EQ_USER,
            ]];
            $array = $this->getColumnFilterData($filter_settings, [
                'login_user_id' => TestDefine::TESTDATA_USER_LOGINID_DEV_USERB,
                'target_table_name' => 'custom_value_edit_all'
            ]);

            foreach ($array as $data) {
                $workflow_work_users = array_get($data, 'workflow_work_users');
                foreach ($workflow_work_users as $workflow_work_user) {
                    $users = array_get($workflow_work_user, 'users');
                    if (isset($users)) {
                        foreach ($users as $user) {
                            $this->assertTrue($user->id == TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
                        }
                    } else {
                        $this->assertTrue($workflow_work_user->id == TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
                    }
                }
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = EQ(workflow user/target organization)
     */
    public function testFuncWorkflowUserOrg()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [[
                'column_name' => 'workflow_work_users',
                'condition_type' => ConditionType::WORKFLOW,
                'filter_condition' => FilterOption::USER_EQ_USER,
            ]];
            $array = $this->getColumnFilterData($filter_settings, [
                'login_user_id' => TestDefine::TESTDATA_USER_LOGINID_DEV_USERB,
                'target_table_name' => 'custom_value_edit'
            ]);

            foreach ($array as $data) {
                $workflow_work_users = array_get($data, 'workflow_work_users');
                foreach ($workflow_work_users as $workflow_work_user) {
                    $users = array_get($workflow_work_user, 'users');
                    if (isset($users)) {
                        foreach ($users as $user) {
                            $this->assertTrue($user->id == TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
                        }
                    } else {
                        $this->assertTrue($workflow_work_user->id == TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
                    }
                }
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = multiple filter condition (join AND)
     */
    public function testFuncFilterMultipleAnd()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [];
            $filter_settings[] = [
                'column_name' => 'date',
                'filter_condition' => FilterOption::DAY_TODAY_OR_AFTER,
            ];
            $filter_settings[] = [
                'column_name' => 'integer',
                'filter_condition' => FilterOption::NUMBER_GT,
                'filter_value_text' => 100
            ];
            $filter_settings[] = [
                'column_name' => 'select_valtext_multiple',
                'filter_condition' => FilterOption::SELECT_EXISTS,
                'filter_value_text' => 'foo'
            ];
            $array = $this->getColumnFilterData($filter_settings);

            foreach ($array as $data) {
                $this->assertTrue(array_get($data, 'value.date') >= \Carbon\Carbon::now()->format('Y-m-d'));
                $this->assertTrue(array_get($data, 'value.integer') > 100);
                $this->assertTrue(in_array('foo', array_get($data, 'value.select_valtext_multiple')));
            }
        } finally {
            DB::rollback();
        }
    }

    /**
     * FilterOption = multiple filter condition (join OR)
     */
    public function testFuncFilterMultipleOr()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $filter_settings = [];
            $filter_settings[] = [
                'column_name' => 'datetime',
                'filter_condition' => FilterOption::DAY_TODAY,
            ];
            $filter_settings[] = [
                'column_name' => 'boolean',
                'filter_condition' => FilterOption::EQ,
                'filter_value_text' => 'ng'
            ];
            $filter_settings[] = [
                'column_name' => 'currency',
                'filter_condition' => FilterOption::NUMBER_GT,
                'filter_value_text' => 70000
            ];
            $array = $this->getColumnFilterData($filter_settings, ['condition_join' => 'or']);

            $not_all = false;
            foreach ($array as $data) {
                $cnt = 0;
                if (array_get($data, 'value.datetime') >= \Carbon\Carbon::now()->format('Y-m-d')) {
                    $cnt++;
                }
                if (array_get($data, 'value.currency') > 70000) {
                    $cnt++;
                }
                if (array_get($data, 'value.boolean') == 'ng') {
                    $cnt++;
                }
                $this->assertTrue($cnt > 0);
                if ($cnt < 3) $not_all = true;
            }
            $this->assertTrue($not_all);
        } finally {
            DB::rollback();
        }
    }

    protected function init(){
        System::clearCache();
    }

    protected function getColumnFilterData(array $filter_settings, array $options = [])
    {
        $options = array_merge(
            [
                'login_user_id' => TestDefine::TESTDATA_USER_LOGINID_ADMIN,
                'target_table_name' => null,
                'condition_join' => 'and',
            ], 
            $options
        );

        // Login user.
        $this->be(LoginUser::find($options['login_user_id']));

        $custom_table = CustomTable::getEloquent($options['target_table_name'] ?? TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);

        $custom_view = $this->createCustomView(
            $custom_table, 
            ViewType::SYSTEM, 
            ViewKindType::DEFAULT, 
            $custom_table->table_name . '-view-unittest', 
            ['condition_join' => $options['condition_join']?? 'and']
        );

        foreach ($filter_settings as $filter_setting)
        {
            if (!isset($filter_setting['condition_type']) || $filter_setting['condition_type'] == ConditionType::COLUMN) {
                $custom_column = CustomColumn::getEloquent($filter_setting['column_name'], $custom_table);
                $column_id = $custom_column->id;
            } else {
                $column_id = SystemColumn::getOption(['name' => $filter_setting['column_name']])['id'];
            }
            $custom_view_filter = $this->createCustomViewFilter(
                $custom_view->id,
                $filter_setting['condition_type'] ?? ConditionType::COLUMN,
                $custom_table->id,
                $column_id,
                $filter_setting['filter_condition'],
                $filter_setting['filter_value_text'] ?? null,
            );
        }

        $model = $custom_table->getValueModel()->query();
        $custom_view->filterModel($model);
        $data = $model->get();
        $this->assertTrue(count($data) > 0, 'data expects over 0, but data count is 0.');
        return $data;
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
