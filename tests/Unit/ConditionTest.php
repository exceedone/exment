<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\FormDataType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Carbon\Carbon;

/**
 * Condition test.
 * For form, workflow, operation, etc..
 */
class ConditionTest extends UnitTestBase
{
    // Custom column text ----------------------------------------------------
    public function testColumnTextEqTrue()
    {
        $this->_testColumnText('test_1', ['test_1'], FilterOption::EQ, true);
        $this->_testColumnText(2, [2], FilterOption::EQ, true);
        $this->_testColumnText("2", [2], FilterOption::EQ, true);
        $this->_testColumnText(2, ["2"], FilterOption::EQ, true);
    }
    public function testColumnTextEqFalse()
    {
        $this->_testColumnText('test_1', ['test_3', null, 2], FilterOption::EQ, false);
        $this->_testColumnText(3, ['test_3', null, 2], FilterOption::EQ, false);
    }
    public function testColumnTextNeTrue()
    {
        $this->_testColumnText('test_1', ['test_3', null, 2], FilterOption::NE, true);
        $this->_testColumnText(3, ['test_3', null, 2], FilterOption::NE, true);
    }
    public function testColumnTextNeFalse()
    {
        $this->_testColumnText('test_1', ['test_1'], FilterOption::NE, false);
        $this->_testColumnText(2, [2], FilterOption::NE, false);
        $this->_testColumnText("2", [2], FilterOption::NE, false);
        $this->_testColumnText(2, ["2"], FilterOption::NE, false);
    }
    public function testColumnTextLikeTrue()
    {
        $this->_testColumnText('test_1', ['te', 'test_1'], FilterOption::LIKE, true);
        $this->_testColumnText(2, [2], FilterOption::LIKE, true);
    }
    public function testColumnTextLikeFalse()
    {
        $this->_testColumnText('test_1', ['test_3', null, 2], FilterOption::LIKE, false);
    }
    public function testColumnTextNotLikeTrue()
    {
        $this->_testColumnText('test_1', ['test_3', null, 2], FilterOption::NOT_LIKE, true);
    }
    public function testColumnTextNotLikeFalse()
    {
        $this->_testColumnText('test_1', ['te', 'test_1'], FilterOption::NOT_LIKE, false);
        $this->_testColumnText(2, [2], FilterOption::NOT_LIKE, false);
    }
    public function testColumnTextNotNullTrue()
    {
        $this->_testColumnTextNullCheck('test_1', FilterOption::NOT_NULL, true);
        $this->_testColumnTextNullCheck('test_2', FilterOption::NOT_NULL, true);
        $this->_testColumnTextNullCheck(2, FilterOption::NOT_NULL, true);
    }
    public function testColumnTextNotNullFalse()
    {
        $this->_testColumnTextNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnTextNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnTextNullTrue()
    {
        $this->_testColumnTextNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnTextNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnTextNullFalse()
    {
        $this->_testColumnTextNullCheck('test_1', FilterOption::NULL, false);
        $this->_testColumnTextNullCheck('test_2', FilterOption::NULL, false);
        $this->_testColumnTextNullCheck(2, FilterOption::NULL, false);
    }

    protected function _testColumnText($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn(ColumnType::TEXT, $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnTextNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck(ColumnType::TEXT, $target_value, $filterOption, $result);
    }

    // Custom column Integer ----------------------------------------------------
    public function testColumnIntegerEqTrue()
    {
        $this->_testColumnInteger(2, ["2", 2], FilterOption::EQ, true);
        $this->_testColumnInteger("2", [2], FilterOption::EQ, true);
    }
    public function testColumnIntegerEqFalse()
    {
        $this->_testColumnInteger(2, [3, "3", "3.2", 3.3], FilterOption::EQ, false);
    }
    public function testColumnIntegerNeTrue()
    {
        $this->_testColumnInteger(2, [3, "3", "3.2", 3.3], FilterOption::NE, true);
    }
    public function testColumnIntegerNeFalse()
    {
        $this->_testColumnInteger(2, ["2", 2], FilterOption::NE, false);
        $this->_testColumnInteger("2", [2], FilterOption::NE, false);
    }

    public function testColumnIntegerNotNullTrue()
    {
        $this->_testColumnIntegerNullCheck(2, FilterOption::NOT_NULL, true);
        $this->_testColumnIntegerNullCheck('2', FilterOption::NOT_NULL, true);
        $this->_testColumnIntegerNullCheck('0', FilterOption::NOT_NULL, true);
        $this->_testColumnIntegerNullCheck(0, FilterOption::NOT_NULL, true);
    }
    public function testColumnIntegerNotNullFalse()
    {
        $this->_testColumnIntegerNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnIntegerNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnIntegerNullTrue()
    {
        $this->_testColumnIntegerNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnIntegerNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnIntegerNullFalse()
    {
        $this->_testColumnIntegerNullCheck(2, FilterOption::NULL, false);
        $this->_testColumnIntegerNullCheck('2', FilterOption::NULL, false);
        $this->_testColumnIntegerNullCheck('0', FilterOption::NULL, false);
        $this->_testColumnIntegerNullCheck(0, FilterOption::NULL, false);
    }
    protected function _testColumnInteger($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn(ColumnType::INTEGER, $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnIntegerNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck(ColumnType::INTEGER, $target_value, $filterOption, $result);
    }


    // Custom column decimal ----------------------------------------------------
    public function testColumnDecimalEqTrue()
    {
        $this->_testColumnDecimal(3.56, ['3.56', 3.56], FilterOption::EQ, true);
        $this->_testColumnDecimal('0.91', ['0.91', 0.91], FilterOption::EQ, true);
        $this->_testColumnDecimal(2, ['2', 2, 2.0, '2.0'], FilterOption::EQ, true);
        $this->_testColumnDecimal(-4.2, ['-4.2', -4.2], FilterOption::EQ, true);
    }
    public function testColumnDecimalEqFalse()
    {
        $this->_testColumnDecimal('5.81', ['5.811', null, 5.8, 'text_1'], FilterOption::EQ, false);
        $this->_testColumnDecimal(3.3, ['33', null, 3.33, 'text_33'], FilterOption::EQ, false);
        $this->_testColumnDecimal(-4.2, ['4.2', 4.2, -4, '-4', null], FilterOption::EQ, false);
    }
    public function testColumnDecimalNeTrue()
    {
        $this->_testColumnDecimal('5.81', ['5.811', null, 5.8, 'text_1'], FilterOption::NE, true);
        $this->_testColumnDecimal(3.3, ['33', null, 3.33, 'text_33'], FilterOption::NE, true);
        $this->_testColumnDecimal(-4.2, ['4.2', 4.2, -4, '-4'], FilterOption::NE, true);
    }
    public function testColumnDecimalNeFalse()
    {
        $this->_testColumnDecimal(3.56, ['3.56', 3.56], FilterOption::NE, false);
        $this->_testColumnDecimal('0.91', ['0.91', 0.91], FilterOption::NE, false);
        $this->_testColumnDecimal(2, ['2', 2, 2.0, '2.0'], FilterOption::NE, false);
        $this->_testColumnDecimal(-4.2, ['-4.2', -4.2], FilterOption::NE, false);
    }
    public function testColumnDecimalGtTrue()
    {
        $this->_testColumnDecimal(3.35, ['3.34', 3.3], FilterOption::NUMBER_GT, true);
        $this->_testColumnDecimal('0.91', ['0.9', 0.909], FilterOption::NUMBER_GT, true);
        $this->_testColumnDecimal(2, ['1.99', 1, 1.9], FilterOption::NUMBER_GT, true);
        $this->_testColumnDecimal(-3.02, ['-3.03', -3.021], FilterOption::NUMBER_GT, true);
    }
    public function testColumnDecimalGtFalse()
    {
        $this->_testColumnDecimal('5.81', ['5.81', 5.9, null], FilterOption::NUMBER_GT, false);
        $this->_testColumnDecimal(3.3, ['3.3', 3.31, '4', null], FilterOption::NUMBER_GT, false);
        $this->_testColumnDecimal(-3.02, ['-3.01', -3.02, -3, null], FilterOption::NUMBER_GT, false);
    }
    public function testColumnDecimalLtTrue()
    {
        $this->_testColumnDecimal(3.35, ['3.36', 3.4], FilterOption::NUMBER_LT, true);
        $this->_testColumnDecimal('0.91', ['0.911', 0.92], FilterOption::NUMBER_LT, true);
        $this->_testColumnDecimal(2, ['2.01', 3, 2.1], FilterOption::NUMBER_LT, true);
        $this->_testColumnDecimal(-3.02, ['-3', 3.02, -3.01, 0], FilterOption::NUMBER_LT, true);
    }
    public function testColumnDecimalLtFalse()
    {
        $this->_testColumnDecimal('5.81', ['5.80', 5.8, '-5.81', null], FilterOption::NUMBER_LT, false);
        $this->_testColumnDecimal(3.3, ['3.3', 3.29, '3', null], FilterOption::NUMBER_LT, false);
        $this->_testColumnDecimal(-3.02, ['-3.1', -3.02, -4, null], FilterOption::NUMBER_LT, false);
    }
    public function testColumnDecimalGteTrue()
    {
        $this->_testColumnDecimal(3.35, ['3.350', 3.3], FilterOption::NUMBER_GTE, true);
        $this->_testColumnDecimal('0.91', ['0.9', 0.91], FilterOption::NUMBER_GTE, true);
        $this->_testColumnDecimal(2, ['2.0', 1, 1.99], FilterOption::NUMBER_GTE, true);
        $this->_testColumnDecimal(-3.02, ['-3.03', -3.021], FilterOption::NUMBER_GTE, true);
    }
    public function testColumnDecimalGteFalse()
    {
        $this->_testColumnDecimal('5.81', ['5.811', 5.9, null], FilterOption::NUMBER_GTE, false);
        $this->_testColumnDecimal(3.3, ['3.4', 3.31, '4', null], FilterOption::NUMBER_GTE, false);
        $this->_testColumnDecimal(-3.02, ['-3.01', -3.019, -3, null], FilterOption::NUMBER_GTE, false);
    }
    public function testColumnDecimalLteTrue()
    {
        $this->_testColumnDecimal(3.35, ['3.36', 3.35], FilterOption::NUMBER_LTE, true);
        $this->_testColumnDecimal('0.91', ['0.910', 0.911], FilterOption::NUMBER_LTE, true);
        $this->_testColumnDecimal(2, ['2.01', 2.0, 2], FilterOption::NUMBER_LTE, true);
        $this->_testColumnDecimal(-3.02, ['-3', 3.02, -3.02, 0], FilterOption::NUMBER_LTE, true);
    }
    public function testColumnDecimalLteFalse()
    {
        $this->_testColumnDecimal('5.81', ['5.80', 5.8, '-5.81', null], FilterOption::NUMBER_LTE, false);
        $this->_testColumnDecimal(3.3, ['3.29', 3.2, '3', null], FilterOption::NUMBER_LTE, false);
        $this->_testColumnDecimal(-3.02, ['-3.1', -3.03, -4, null], FilterOption::NUMBER_LTE, false);
    }
    public function testColumnDecimalNotNullTrue()
    {
        $this->_testColumnDecimalNullCheck(2.01, FilterOption::NOT_NULL, true);
        $this->_testColumnDecimalNullCheck('2.0', FilterOption::NOT_NULL, true);
        $this->_testColumnDecimalNullCheck('0.0', FilterOption::NOT_NULL, true);
        $this->_testColumnDecimalNullCheck(0, FilterOption::NOT_NULL, true);
    }
    public function testColumnDecimalNotNullFalse()
    {
        $this->_testColumnDecimalNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnDecimalNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnDecimalNullTrue()
    {
        $this->_testColumnDecimalNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnDecimalNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnDecimalNullFalse()
    {
        $this->_testColumnDecimalNullCheck(2.01, FilterOption::NULL, false);
        $this->_testColumnDecimalNullCheck('2.0', FilterOption::NULL, false);
        $this->_testColumnDecimalNullCheck('0.0', FilterOption::NULL, false);
        $this->_testColumnDecimalNullCheck(0, FilterOption::NULL, false);
    }
    protected function _testColumnDecimal($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn(ColumnType::DECIMAL, $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnDecimalNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck(ColumnType::DECIMAL, $target_value, $filterOption, $result);
    }


    // Custom column Date ----------------------------------------------------
    public function testColumnDateDayOnTrue()
    {
        $this->_testColumnDate("2021-02-01", ["2021-02-01"], FilterOption::DAY_ON, true);
        $this->_testColumnDate("2021-02-01", ["2021-2-1"], FilterOption::DAY_ON, true);
        $this->_testColumnDate("2021-02-01", ['2021/02/01'], FilterOption::DAY_ON, true);
        $this->_testColumnDate("2021-02-01", ['20210201'], FilterOption::DAY_ON, true);
    }
    public function testColumnDateDayOnFalse()
    {
        $this->_testColumnDate("2021-02-01", ["2020-02-01"], FilterOption::DAY_ON, false);
        $this->_testColumnDate(null, ["2021-02-01"], FilterOption::DAY_ON, false);
        $this->_testColumnDate("2021-02-01", ["2021-2-2"], FilterOption::DAY_ON, false);
        $this->_testColumnDate("2021-02-01", ['2021/01/01'], FilterOption::DAY_ON, false);
    }
    public function testColumnDateDayOnOrAfterTrue()
    {
        $this->_testColumnDate("2021-02-01", ["2021-02-01", "2021-01-31", "20210201"], FilterOption::DAY_ON_OR_AFTER, true);
        $this->_testColumnDate("2021-02-01", ["2021-2-1", "2021-1-31"], FilterOption::DAY_ON_OR_AFTER, true);
        $this->_testColumnDate("2021-02-01", ['2021/02/01', '2020/02/02'], FilterOption::DAY_ON_OR_AFTER, true);
    }
    public function testColumnDateDayOnOrAfterFalse()
    {
        $this->_testColumnDate("2021-02-01", ["2021-02-02", "2022-01-31", "20210202"], FilterOption::DAY_ON_OR_AFTER, false);
        $this->_testColumnDate(null, ["2021-02-01"], FilterOption::DAY_ON_OR_AFTER, false);
        $this->_testColumnDate("2021-02-01", ["2021-2-2", "2022-2-1"], FilterOption::DAY_ON_OR_AFTER, false);
        $this->_testColumnDate("2021-02-01", ['2021/02/02', '2022/01/01'], FilterOption::DAY_ON_OR_AFTER, false);
    }
    public function testColumnDateDayOnOrBeforeTrue()
    {
        $this->_testColumnDate("2021-01-31", ["2021-02-01", "2021-01-31", "20210131"], FilterOption::DAY_ON_OR_BEFORE, true);
        $this->_testColumnDate("2021-01-31", ["2021-2-1", "2021-1-31"], FilterOption::DAY_ON_OR_BEFORE, true);
        $this->_testColumnDate("2021-01-31", ['2021/02/01', '2021/1/31'], FilterOption::DAY_ON_OR_BEFORE, true);
    }
    public function testColumnDateDayOnOrBeforeFalse()
    {
        $this->_testColumnDate("2021-02-01", ["2021-01-31", "2020-02-01", "20201231"], FilterOption::DAY_ON_OR_BEFORE, false);
        $this->_testColumnDate(null, ["2021-02-01"], FilterOption::DAY_ON_OR_BEFORE, false);
        $this->_testColumnDate("2021-02-01", ["2021-1-31", "2020-2-1"], FilterOption::DAY_ON_OR_BEFORE, false);
        $this->_testColumnDate("2021-02-01", ['2021/1/31', '2020/01/01'], FilterOption::DAY_ON_OR_BEFORE, false);
    }
    public function testColumnDateDayTodayTrue()
    {
        $now = Carbon::now();
        $this->_testColumnDate($now->format('Y-m-d'), [null], FilterOption::DAY_TODAY, true);
        $this->_testColumnDate($now->format('Y/m/d'), [null], FilterOption::DAY_TODAY, true);
        $this->_testColumnDate($now->format('Ymd'), [null], FilterOption::DAY_TODAY, true);
        $this->_testColumnDate($now->format('Y-m-d H:i:s.u'), [null], FilterOption::DAY_TODAY, true);
    }
    public function testColumnDateDayTodayFalse()
    {
        $this->_testColumnDate(Carbon::today()->addDays(1)->format('Y-m-d'), [null], FilterOption::DAY_TODAY, false);
        $this->_testColumnDate(Carbon::today()->subDays(1)->format('Y/m/d'), [null], FilterOption::DAY_TODAY, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_TODAY, false);
    }
    public function testColumnDateDayTodayOrAfterTrue()
    {
        $this->_testColumnDate(Carbon::today()->format('Y-m-d'), [null], FilterOption::DAY_TODAY_OR_AFTER, true);
        $this->_testColumnDate(Carbon::today()->addDays(1)->format('Y-m-d'), [null], FilterOption::DAY_TODAY_OR_AFTER, true);
        $this->_testColumnDate(Carbon::today()->addMonths(1)->format('Ymd'), [null], FilterOption::DAY_TODAY_OR_AFTER, true);
        $this->_testColumnDate(Carbon::now()->format('Y-m-d H:i:s.u'), [null], FilterOption::DAY_TODAY_OR_AFTER, true);
    }
    public function testColumnDateDayTodayOrAfterFalse()
    {
        $this->_testColumnDate(Carbon::today()->subDays(1)->format('Y-m-d'), [null], FilterOption::DAY_TODAY_OR_AFTER, false);
        $this->_testColumnDate(Carbon::today()->subYears(1)->format('Y/m/d'), [null], FilterOption::DAY_TODAY_OR_AFTER, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_TODAY_OR_AFTER, false);
    }
    public function testColumnDateDayTodayOrBeforeTrue()
    {
        $this->_testColumnDate(Carbon::today()->format('Y-m-d'), [null], FilterOption::DAY_TODAY_OR_BEFORE, true);
        $this->_testColumnDate(Carbon::today()->subDays(1)->format('Y-m-d'), [null], FilterOption::DAY_TODAY_OR_BEFORE, true);
        $this->_testColumnDate(Carbon::today()->subMonths(1)->format('Ymd'), [null], FilterOption::DAY_TODAY_OR_BEFORE, true);
        $this->_testColumnDate(Carbon::now()->format('Y-m-d H:i:s.u'), [null], FilterOption::DAY_TODAY_OR_BEFORE, true);
    }
    public function testColumnDateDayTodayOrBeforeFalse()
    {
        $this->_testColumnDate(Carbon::today()->addDays(1)->format('Y-m-d'), [null], FilterOption::DAY_TODAY_OR_BEFORE, false);
        $this->_testColumnDate(Carbon::today()->addYears(1)->format('Y/m/d'), [null], FilterOption::DAY_TODAY_OR_BEFORE, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_TODAY_OR_AFTER, false);
    }
    public function testColumnDateDayYesterdayTrue()
    {
        $yesterday = Carbon::yesterday();
        $this->_testColumnDate($yesterday->format('Y-m-d'), [null], FilterOption::DAY_YESTERDAY, true);
        $this->_testColumnDate($yesterday->format('Y/m/d'), [null], FilterOption::DAY_YESTERDAY, true);
        $this->_testColumnDate($yesterday->format('Ymd'), [null], FilterOption::DAY_YESTERDAY, true);
        $this->_testColumnDate(Carbon::now()->subDays(1)->format('Y-m-d H:i:s.u'), [null], FilterOption::DAY_YESTERDAY, true);
    }
    public function testColumnDateDayYesterdayFalse()
    {
        $this->_testColumnDate(Carbon::yesterday()->addDays(1)->format('Y-m-d'), [null], FilterOption::DAY_YESTERDAY, false);
        $this->_testColumnDate(Carbon::yesterday()->subDays(1)->format('Y/m/d'), [null], FilterOption::DAY_YESTERDAY, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_YESTERDAY, false);
    }
    public function testColumnDateDayTomorrowTrue()
    {
        $tomorrow = Carbon::tomorrow();
        $this->_testColumnDate($tomorrow->format('Y-m-d'), [null], FilterOption::DAY_TOMORROW, true);
        $this->_testColumnDate($tomorrow->format('Y/m/d'), [null], FilterOption::DAY_TOMORROW, true);
        $this->_testColumnDate($tomorrow->format('Ymd'), [null], FilterOption::DAY_TOMORROW, true);
        $this->_testColumnDate(Carbon::now()->addDays(1)->format('Y-m-d H:i:s.u'), [null], FilterOption::DAY_TOMORROW, true);
    }
    public function testColumnDateDayTomorrowFalse()
    {
        $this->_testColumnDate(Carbon::tomorrow()->addDays(1)->format('Y-m-d'), [null], FilterOption::DAY_TOMORROW, false);
        $this->_testColumnDate(Carbon::tomorrow()->subDays(1)->format('Y/m/d'), [null], FilterOption::DAY_TOMORROW, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_TOMORROW, false);
    }
    public function testColumnDateDayThisMonthTrue()
    {
        $now = Carbon::now();
        $this->_testColumnDate($now->firstOfMonth()->format('Y-m-d'), [null], FilterOption::DAY_THIS_MONTH, true);
        $this->_testColumnDate($now->lastOfMonth()->format('Y/m/d'), [null], FilterOption::DAY_THIS_MONTH, true);
        $this->_testColumnDate($now->format('Ymd'), [null], FilterOption::DAY_THIS_MONTH, true);
        $this->_testColumnDate($now->format('Y-m-d H:i:s.u'), [null], FilterOption::DAY_THIS_MONTH, true);
    }
    public function testColumnDateDayThisMonthFalse()
    {
        $this->_testColumnDate(Carbon::today()->addMonths(1)->format('Y-m-d'), [null], FilterOption::DAY_THIS_MONTH, false);
        $this->_testColumnDate(Carbon::today()->subYears(1)->format('Y/m/d'), [null], FilterOption::DAY_THIS_MONTH, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_THIS_MONTH, false);
    }
    public function testColumnDateDayLastMonthTrue()
    {
        $this->_testColumnDate(Carbon::today()->firstOfMonth()->subMonths(1)->format('Y-m-d'), [null], FilterOption::DAY_LAST_MONTH, true);
    }
    public function testColumnDateDayLastMonthTrue2()
    {
        // Change now
        Carbon::setTestNow(new Carbon('2021-01-02 09:59:59'));
        $this->_testColumnDate((new Carbon('2020-12-21 09:59:59'))->firstOfMonth()->format('Y-m-d'), [null], FilterOption::DAY_LAST_MONTH, true);

        Carbon::setTestNow(new Carbon('2020-12-02 09:59:59'));
        $this->_testColumnDate((new Carbon('2020-11-05 09:59:59'))->firstOfMonth()->format('Y-m-d'), [null], FilterOption::DAY_LAST_MONTH, true);
    }
    public function testColumnDateDayLastMonthFalse()
    {
        $this->_testColumnDate(Carbon::today()->addMonths(1)->format('Y-m-d'), [null], FilterOption::DAY_LAST_MONTH, false);
        $this->_testColumnDate(Carbon::today()->format('Y/m/d'), [null], FilterOption::DAY_LAST_MONTH, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_LAST_MONTH, false);
    }
    public function testColumnDateDayLastMonthFalse2()
    {
        // Change now
        Carbon::setTestNow(new Carbon('2021-01-02 09:59:59'));
        // Wrong year
        $this->_testColumnDate((new Carbon('2021-12-21 09:59:59'))->firstOfMonth()->format('Y-m-d'), [null], FilterOption::DAY_LAST_MONTH, false);

        Carbon::setTestNow(new Carbon('2020-12-02 09:59:59'));
        $this->_testColumnDate((new Carbon('2021-11-05 09:59:59'))->firstOfMonth()->format('Y-m-d'), [null], FilterOption::DAY_LAST_MONTH, false);
    }
    public function testColumnDateDayNextMonthTrue()
    {
        $this->_testColumnDate(Carbon::today()->firstOfMonth()->addMonths(1)->format('Y-m-d'), [null], FilterOption::DAY_NEXT_MONTH, true);
    }
    public function testColumnDateDayNextMonthTrue2()
    {
        // Change now
        Carbon::setTestNow(new Carbon('2021-12-02 09:59:59'));
        $this->_testColumnDate((new Carbon('2022-01-21 09:59:59'))->firstOfMonth()->format('Y-m-d'), [null], FilterOption::DAY_NEXT_MONTH, true);

        Carbon::setTestNow(new Carbon('2020-10-02 09:59:59'));
        $this->_testColumnDate((new Carbon('2020-11-05 09:59:59'))->firstOfMonth()->format('Y-m-d'), [null], FilterOption::DAY_NEXT_MONTH, true);
    }
    public function testColumnDateDayNextMonthFalse()
    {
        $this->_testColumnDate(Carbon::today()->subMonths(1)->format('Y-m-d'), [null], FilterOption::DAY_NEXT_MONTH, false);
        $this->_testColumnDate(Carbon::today()->format('Y/m/d'), [null], FilterOption::DAY_NEXT_MONTH, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_NEXT_MONTH, false);
    }
    public function testColumnDateDayNextMonthFalse2()
    {        // Change now
        Carbon::setTestNow(new Carbon('2021-12-02 09:59:59'));
        // Wrong year
        $this->_testColumnDate((new Carbon('2021-01-21 09:59:59'))->firstOfMonth()->format('Y-m-d'), [null], FilterOption::DAY_NEXT_MONTH, false);

        Carbon::setTestNow(new Carbon('2020-01-02 09:59:59'));
        $this->_testColumnDate((new Carbon('2021-02-05 09:59:59'))->firstOfMonth()->format('Y-m-d'), [null], FilterOption::DAY_NEXT_MONTH, false);
    }
    public function testColumnDateDayThisYearTrue()
    {
        $firstday = new Carbon('first day of this year');
        $lastday = new Carbon('last day of this year');
        $this->_testColumnDate($firstday->format('Y-m-d'), [null], FilterOption::DAY_THIS_YEAR, true);
        $this->_testColumnDate($lastday->format('Y/m/d'), [null], FilterOption::DAY_THIS_YEAR, true);
        $this->_testColumnDate(Carbon::today()->format('Ymd'), [null], FilterOption::DAY_THIS_YEAR, true);
        $this->_testColumnDate(Carbon::now()->format('Y-m-d H:i:s.u'), [null], FilterOption::DAY_THIS_YEAR, true);
    }
    public function testColumnDateDayThisYearFalse()
    {
        $this->_testColumnDate(Carbon::today()->subYears(1)->format('Y-m-d'), [null], FilterOption::DAY_THIS_YEAR, false);
        $this->_testColumnDate(Carbon::today()->addYears(1)->format('Y/m/d'), [null], FilterOption::DAY_THIS_YEAR, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_THIS_YEAR, false);
    }
    public function testColumnDateDayLastYearTrue()
    {
        $firstday = new Carbon('first day of last year');
        $lastday = new Carbon('last day of last year');
        $this->_testColumnDate($firstday->format('Y-m-d'), [null], FilterOption::DAY_LAST_YEAR, true);
        $this->_testColumnDate($lastday->format('Y/m/d'), [null], FilterOption::DAY_LAST_YEAR, true);
        $this->_testColumnDate(Carbon::today()->subYears(1)->format('Ymd'), [null], FilterOption::DAY_LAST_YEAR, true);
        $this->_testColumnDate(Carbon::now()->subYears(1)->format('Y-m-d H:i:s.u'), [null], FilterOption::DAY_LAST_YEAR, true);
    }
    public function testColumnDateDayLastYearFalse()
    {
        $this->_testColumnDate(Carbon::today()->format('Y-m-d'), [null], FilterOption::DAY_LAST_YEAR, false);
        $this->_testColumnDate(Carbon::today()->addYears(1)->format('Y/m/d'), [null], FilterOption::DAY_LAST_YEAR, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_LAST_YEAR, false);
    }
    public function testColumnDateDayNextYearTrue()
    {
        $firstday = new Carbon('first day of next year');
        $lastday = new Carbon('last day of next year');
        $this->_testColumnDate($firstday->format('Y-m-d'), [null], FilterOption::DAY_NEXT_YEAR, true);
        $this->_testColumnDate($lastday->format('Y/m/d'), [null], FilterOption::DAY_NEXT_YEAR, true);
        $this->_testColumnDate(Carbon::today()->addYears(1)->format('Ymd'), [null], FilterOption::DAY_NEXT_YEAR, true);
        $this->_testColumnDate(Carbon::now()->addYears(1)->format('Y-m-d H:i:s.u'), [null], FilterOption::DAY_NEXT_YEAR, true);
    }
    public function testColumnDateDayNextYearFalse()
    {
        $this->_testColumnDate(Carbon::today()->format('Y-m-d'), [null], FilterOption::DAY_NEXT_YEAR, false);
        $this->_testColumnDate(Carbon::today()->subYears(1)->format('Y/m/d'), [null], FilterOption::DAY_NEXT_YEAR, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_NEXT_YEAR, false);
    }
    public function testColumnDateDayLastXDayOrAfterTrue()
    {
        $baseday = new Carbon('-3 day');
        $this->_testColumnDate($baseday->format('Y-m-d'), [3], FilterOption::DAY_LAST_X_DAY_OR_AFTER, true);
        $this->_testColumnDate($baseday->addDays(1)->format('Y/m/d'), [3], FilterOption::DAY_LAST_X_DAY_OR_AFTER, true);
        $this->_testColumnDate(Carbon::today()->format('Ymd'), [3], FilterOption::DAY_LAST_X_DAY_OR_AFTER, true);
        $this->_testColumnDate($baseday->format('Y-m-d H:i:s.u'), [3], FilterOption::DAY_LAST_X_DAY_OR_AFTER, true);
    }
    public function testColumnDateDayLastXDayOrAfterFalse()
    {
        $baseday = new Carbon('-10 day');
        $this->_testColumnDate($baseday->subDays(1)->format('Y-m-d'), [10], FilterOption::DAY_LAST_X_DAY_OR_AFTER, false);
        $this->_testColumnDate($baseday->subYears(1)->format('Y/m/d'), [10], FilterOption::DAY_LAST_X_DAY_OR_AFTER, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_LAST_X_DAY_OR_AFTER, false);
    }
    public function testColumnDateDayLastXDayOrBeforeTrue()
    {
        $baseday = new Carbon('-3 day');
        $this->_testColumnDate($baseday->format('Y-m-d'), [3], FilterOption::DAY_LAST_X_DAY_OR_BEFORE, true);
        $this->_testColumnDate($baseday->subDays(1)->format('Y/m/d'), [3], FilterOption::DAY_LAST_X_DAY_OR_BEFORE, true);
        $this->_testColumnDate($baseday->format('Ymd'), [3], FilterOption::DAY_LAST_X_DAY_OR_BEFORE, true);
        $this->_testColumnDate($baseday->format('Y-m-d H:i:s.u'), [3], FilterOption::DAY_LAST_X_DAY_OR_BEFORE, true);
    }
    public function testColumnDateDayLastXDayOrBeforeFalse()
    {
        $baseday = new Carbon('-10 day');
        $this->_testColumnDate($baseday->addDays(1)->format('Y-m-d'), [10], FilterOption::DAY_LAST_X_DAY_OR_BEFORE, false);
        $this->_testColumnDate($baseday->addMonths(1)->format('Y/m/d'), [10], FilterOption::DAY_LAST_X_DAY_OR_BEFORE, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_LAST_X_DAY_OR_BEFORE, false);
    }
    public function testColumnDateDayNextXDayOrAfterTrue()
    {
        $baseday = new Carbon('+3 day');
        $this->_testColumnDate($baseday->format('Y-m-d'), [3], FilterOption::DAY_NEXT_X_DAY_OR_AFTER, true);
        $this->_testColumnDate($baseday->addDays(1)->format('Y/m/d'), [3], FilterOption::DAY_NEXT_X_DAY_OR_AFTER, true);
        $this->_testColumnDate($baseday->format('Ymd'), [3], FilterOption::DAY_NEXT_X_DAY_OR_AFTER, true);
        $this->_testColumnDate($baseday->format('Y-m-d H:i:s.u'), [3], FilterOption::DAY_NEXT_X_DAY_OR_AFTER, true);
    }
    public function testColumnDateDayNextXDayOrAfterFalse()
    {
        $baseday = new Carbon('+10 day');
        $this->_testColumnDate($baseday->subDays(1)->format('Y-m-d'), [10], FilterOption::DAY_NEXT_X_DAY_OR_AFTER, false);
        $this->_testColumnDate($baseday->subYears(1)->format('Y/m/d'), [10], FilterOption::DAY_NEXT_X_DAY_OR_AFTER, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_NEXT_X_DAY_OR_AFTER, false);
    }
    public function testColumnDateDayNextXDayOrBeforeTrue()
    {
        $baseday = new Carbon('+3 day');
        $this->_testColumnDate($baseday->format('Y-m-d'), [3], FilterOption::DAY_NEXT_X_DAY_OR_BEFORE, true);
        $this->_testColumnDate($baseday->subDays(1)->format('Y/m/d'), [3], FilterOption::DAY_NEXT_X_DAY_OR_BEFORE, true);
        $this->_testColumnDate(Carbon::today()->format('Ymd'), [3], FilterOption::DAY_NEXT_X_DAY_OR_BEFORE, true);
        $this->_testColumnDate($baseday->format('Y-m-d H:i:s.u'), [3], FilterOption::DAY_NEXT_X_DAY_OR_BEFORE, true);
    }
    public function testColumnDateDayNextXDayOrBeforeFalse()
    {
        $baseday = new Carbon('+10 day');
        $this->_testColumnDate($baseday->addDays(1)->format('Y-m-d'), [10], FilterOption::DAY_NEXT_X_DAY_OR_BEFORE, false);
        $this->_testColumnDate($baseday->addMonths(1)->format('Y/m/d'), [10], FilterOption::DAY_NEXT_X_DAY_OR_BEFORE, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_NEXT_X_DAY_OR_BEFORE, false);
    }
    public function testColumnDateNotNullTrue()
    {
        $this->_testColumnDateNullCheck('2020-02-04', FilterOption::NOT_NULL, true);
        $this->_testColumnDateNullCheck('2021/12/01', FilterOption::NOT_NULL, true);
        $this->_testColumnDateNullCheck('2020-02-04 08:19:54', FilterOption::NOT_NULL, true);
    }
    public function testColumnDateNotNullFalse()
    {
        $this->_testColumnDateNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnDateNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnDateNullTrue()
    {
        $this->_testColumnDateNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnDateNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnDateNullFalse()
    {
        $this->_testColumnDateNullCheck('2020-02-04', FilterOption::NULL, false);
        $this->_testColumnDateNullCheck('2021/12/01', FilterOption::NULL, false);
        $this->_testColumnDateNullCheck('2020-02-04 08:19:54', FilterOption::NULL, false);
        $this->_testColumnDateNullCheck('1970.01.01 09:00:00', FilterOption::NULL, false);
    }
    protected function _testColumnDate($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn(ColumnType::DATE, $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnDateNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck(ColumnType::DATE, $target_value, $filterOption, $result);
    }


    // Custom column Select ----------------------------------------------------
    public function testColumnSelectExistsTrue()
    {
        $this->_testColumnSelect('foo', ['foo'], FilterOption::SELECT_EXISTS, true);
        $this->_testColumnSelect(123, [123, '123'], FilterOption::SELECT_EXISTS, true);
    }
    public function testColumnSelectExistsFalse()
    {
        $this->_testColumnSelect('bar', ["baz", null, '', 0, 123], FilterOption::SELECT_EXISTS, false);
    }
    public function testColumnSelectNotExistsTrue()
    {
        $this->_testColumnSelect('bar', ["baz", null, '', 0, 123], FilterOption::SELECT_NOT_EXISTS, true);
    }
    public function testColumnSelectNotExistsFalse()
    {
        $this->_testColumnSelect('foo', ['foo'], FilterOption::SELECT_NOT_EXISTS, false);
        $this->_testColumnSelect(123, [123, '123'], FilterOption::SELECT_NOT_EXISTS, false);
    }

    public function testColumnSelectNotNullTrue()
    {
        $this->_testColumnSelectNullCheck('bar', FilterOption::NOT_NULL, true);
        $this->_testColumnSelectNullCheck('2', FilterOption::NOT_NULL, true);
        $this->_testColumnSelectNullCheck('0', FilterOption::NOT_NULL, true);
        $this->_testColumnSelectNullCheck(0, FilterOption::NOT_NULL, true);
    }
    public function testColumnSelectNotNullFalse()
    {
        $this->_testColumnSelectNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnSelectNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnSelectNullTrue()
    {
        $this->_testColumnSelectNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnSelectNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnSelectNullFalse()
    {
        $this->_testColumnSelectNullCheck('baz', FilterOption::NULL, false);
        $this->_testColumnSelectNullCheck('2', FilterOption::NULL, false);
        $this->_testColumnSelectNullCheck('0', FilterOption::NULL, false);
        $this->_testColumnSelectNullCheck(0, FilterOption::NULL, false);
    }
    protected function _testColumnSelect($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn(ColumnType::SELECT, $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnSelectNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck(ColumnType::SELECT, $target_value, $filterOption, $result);
    }

    // Custom column Select multiple ----------------------------------------------------
    public function testColumnSelectMultiExistsTrue()
    {
        $this->_testColumnSelectMulti(['foo', 'bar'], ['foo', 'bar', ['foo', 'bar']], FilterOption::SELECT_EXISTS, true);
        $this->_testColumnSelectMulti([123, 456, 789], [123, '123', [123, 456], [789, 123]], FilterOption::SELECT_EXISTS, true);
        $this->_testColumnSelectMulti(['イタリア', 'カナダ'], [
            'イタリア', 'カナダ', ['日本', 'カナダ']], FilterOption::SELECT_EXISTS, true, TestDefine::TESTDATA_TABLE_NAME_UNICODE_DATA);
    }
    public function testColumnSelectMultiExistsFalse()
    {
        $this->_testColumnSelectMulti(['foo', 'bar'], ['baz', null, 0], FilterOption::SELECT_EXISTS, false);
        $this->_testColumnSelectMulti([123, 456, 789], [234, '567', [777]], FilterOption::SELECT_EXISTS, false);
        $this->_testColumnSelectMulti(['イタリア', 'カナダ'], [
            '日本', ['アメリカ', '中国']], FilterOption::SELECT_EXISTS, false, TestDefine::TESTDATA_TABLE_NAME_UNICODE_DATA);
    }
    public function testColumnSelectMultiNotExistsTrue()
    {
        $this->_testColumnSelectMulti(['foo', 'bar'], ['baz', null, 0], FilterOption::SELECT_NOT_EXISTS, true);
        $this->_testColumnSelectMulti([123, 456, 789], [234, '567', [777]], FilterOption::SELECT_NOT_EXISTS, true);
        $this->_testColumnSelectMulti(['イタリア', 'カナダ'], [
            '日本', ['イタリア', '中国'], ['アメリカ', 'カナダ']], FilterOption::SELECT_NOT_EXISTS, true, TestDefine::TESTDATA_TABLE_NAME_UNICODE_DATA);
    }
    public function testColumnSelectMultiNotExistsFalse()
    {
        $this->_testColumnSelectMulti(['foo', 'bar'], ['foo', 'bar', ['foo', 'bar']], FilterOption::SELECT_NOT_EXISTS, false);
        $this->_testColumnSelectMulti([123, 456, 789], [123, '123', [123, 456], [789, 123]], FilterOption::SELECT_NOT_EXISTS, false);
        $this->_testColumnSelectMulti(['イタリア', 'カナダ'], [
            ['イタリア', 'カナダ']], FilterOption::SELECT_NOT_EXISTS, false, TestDefine::TESTDATA_TABLE_NAME_UNICODE_DATA);
    }
    public function testColumnSelectMultiNotNullTrue()
    {
        $this->_testColumnSelectMultiNullCheck(['bar'], FilterOption::NOT_NULL, true);
        $this->_testColumnSelectMultiNullCheck(['bar', 'foo'], FilterOption::NOT_NULL, true);
        $this->_testColumnSelectMultiNullCheck(['2'], FilterOption::NOT_NULL, true);
        $this->_testColumnSelectMultiNullCheck(['0'], FilterOption::NOT_NULL, true);
        $this->_testColumnSelectMultiNullCheck([0], FilterOption::NOT_NULL, true);
    }
    public function testColumnSelectMultiNotNullFalse()
    {
        $this->_testColumnSelectMultiNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnSelectMultiNullCheck('', FilterOption::NOT_NULL, false);
        $this->markTestSkipped('現状空配列はNULLと見なされない');
//        $this->_testColumnSelectMultiNullCheck([], FilterOption::NOT_NULL, false);
    }
    public function testColumnSelectMultiNullTrue()
    {
        $this->_testColumnSelectMultiNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnSelectMultiNullCheck('', FilterOption::NULL, true);
        $this->markTestSkipped('現状空配列はNULLと見なされない');
//        $this->_testColumnSelectMultiNullCheck([], FilterOption::NULL, true);
    }
    public function testColumnSelectMultiNullFalse()
    {
        $this->_testColumnSelectMultiNullCheck(['baz'], FilterOption::NULL, false);
        $this->_testColumnSelectMultiNullCheck([1, 2], FilterOption::NULL, false);
        $this->_testColumnSelectMultiNullCheck(['2'], FilterOption::NULL, false);
        $this->_testColumnSelectMultiNullCheck(['0'], FilterOption::NULL, false);
        $this->_testColumnSelectMultiNullCheck([0], FilterOption::NULL, false);
    }
    protected function _testColumnSelectMulti($target_value, array $values, string $filterOption, bool $result, string $tableName = null)
    {
        $this->__testColumn('select_multiple', $target_value, $values, $filterOption, $result, $tableName);
    }
    protected function _testColumnSelectMultiNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck('select_multiple', $target_value, $filterOption, $result);
    }


    // Custom column SelectValText ----------------------------------------------------
    public function testColumnSelectValExistsTrue()
    {
        $this->_testColumnSelectVal('foo', ['foo'], FilterOption::SELECT_EXISTS, true);
        $this->_testColumnSelectVal(3, [3, '3'], FilterOption::SELECT_EXISTS, true);
    }
    public function testColumnSelectValExistsFalse()
    {
        $this->_testColumnSelectVal('bar', ["baz", null, '', 0, 1], FilterOption::SELECT_EXISTS, false);
    }
    public function testColumnSelectValNotExistsTrue()
    {
        $this->_testColumnSelectVal('2', ["baz", null, '', 0, 1], FilterOption::SELECT_NOT_EXISTS, true);
    }
    public function testColumnSelectValNotExistsFalse()
    {
        $this->_testColumnSelectVal('foo', ['foo'], FilterOption::SELECT_NOT_EXISTS, false);
        $this->_testColumnSelectVal(2, [2, '2'], FilterOption::SELECT_NOT_EXISTS, false);
    }

    public function testColumnSelectValNotNullTrue()
    {
        $this->_testColumnSelectValNullCheck('bar', FilterOption::NOT_NULL, true);
        $this->_testColumnSelectValNullCheck('2', FilterOption::NOT_NULL, true);
        $this->_testColumnSelectValNullCheck('0', FilterOption::NOT_NULL, true);
        $this->_testColumnSelectValNullCheck(0, FilterOption::NOT_NULL, true);
    }
    public function testColumnSelectValNotNullFalse()
    {
        $this->_testColumnSelectValNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnSelectValNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnSelectValNullTrue()
    {
        $this->_testColumnSelectValNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnSelectValNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnSelectValNullFalse()
    {
        $this->_testColumnSelectValNullCheck('baz', FilterOption::NULL, false);
        $this->_testColumnSelectValNullCheck('2', FilterOption::NULL, false);
        $this->_testColumnSelectValNullCheck('0', FilterOption::NULL, false);
        $this->_testColumnSelectValNullCheck(0, FilterOption::NULL, false);
    }
    protected function _testColumnSelectVal($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn(ColumnType::SELECT_VALTEXT, $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnSelectValNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck(ColumnType::SELECT_VALTEXT, $target_value, $filterOption, $result);
    }

    // Custom column Select value text multiple ----------------------------------------------------
    public function testColumnSelectValMultiExistsTrue()
    {
        $this->_testColumnSelectValMulti(['foo', 'bar'], ['foo', 'bar', ['foo', 'bar']], FilterOption::SELECT_EXISTS, true);
        $this->_testColumnSelectValMulti([123, 456, 789], [123, '123', [123, 456], [789, 123]], FilterOption::SELECT_EXISTS, true);
    }
    public function testColumnSelectValMultiExistsFalse()
    {
        $this->_testColumnSelectValMulti(['foo', 'bar'], ['baz', null, 0], FilterOption::SELECT_EXISTS, false);
        $this->_testColumnSelectValMulti([123, 456, 789], [234, '567', [777]], FilterOption::SELECT_EXISTS, false);
    }
    public function testColumnSelectValMultiNotExistsTrue()
    {
        $this->_testColumnSelectValMulti(['foo', 'bar'], ['baz', null, 0], FilterOption::SELECT_NOT_EXISTS, true);
        $this->_testColumnSelectValMulti([123, 456, 789], [234, '567', [777]], FilterOption::SELECT_NOT_EXISTS, true);
    }
    public function testColumnSelectValMultiNotExistsFalse()
    {
        $this->_testColumnSelectValMulti(['foo', 'bar'], ['foo', 'bar', ['foo', 'bar']], FilterOption::SELECT_NOT_EXISTS, false);
        $this->_testColumnSelectValMulti([123, 456, 789], [123, '123', [123, 456], [789, 123]], FilterOption::SELECT_NOT_EXISTS, false);
    }
    public function testColumnSelectValMultiNotNullTrue()
    {
        $this->_testColumnSelectValMultiNullCheck(['bar'], FilterOption::NOT_NULL, true);
        $this->_testColumnSelectValMultiNullCheck(['bar', 'foo'], FilterOption::NOT_NULL, true);
        $this->_testColumnSelectValMultiNullCheck(['2'], FilterOption::NOT_NULL, true);
        $this->_testColumnSelectValMultiNullCheck(['0'], FilterOption::NOT_NULL, true);
        $this->_testColumnSelectValMultiNullCheck([0], FilterOption::NOT_NULL, true);
    }
    public function testColumnSelectValMultiNotNullFalse()
    {
        $this->_testColumnSelectValMultiNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnSelectValMultiNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnSelectValMultiNullTrue()
    {
        $this->_testColumnSelectValMultiNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnSelectValMultiNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnSelectValMultiNullFalse()
    {
        $this->_testColumnSelectValMultiNullCheck(['baz'], FilterOption::NULL, false);
        $this->_testColumnSelectValMultiNullCheck([1, 2], FilterOption::NULL, false);
        $this->_testColumnSelectValMultiNullCheck(['2'], FilterOption::NULL, false);
        $this->_testColumnSelectValMultiNullCheck(['0'], FilterOption::NULL, false);
        $this->_testColumnSelectValMultiNullCheck([0], FilterOption::NULL, false);
    }
    protected function _testColumnSelectValMulti($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn('select_valtext_multiple', $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnSelectValMultiNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck('select_valtext_multiple', $target_value, $filterOption, $result);
    }



    // Custom column Select Table ----------------------------------------------------
    public function testColumnSelectTableExistsTrue()
    {
        $this->_testColumnSelectTable(5, [5, '5'], FilterOption::SELECT_EXISTS, true);
    }
    public function testColumnSelectTableExistsFalse()
    {
        $this->_testColumnSelectTable('3', ["4", null, '', 0, 5], FilterOption::SELECT_EXISTS, false);
    }
    public function testColumnSelectTableNotExistsTrue()
    {
        $this->_testColumnSelectTable(3, ["4", null, '', 0, 5], FilterOption::SELECT_NOT_EXISTS, true);
    }
    public function testColumnSelectTableNotExistsFalse()
    {
        $this->_testColumnSelectTable('7', [7, '7'], FilterOption::SELECT_NOT_EXISTS, false);
    }
    public function testColumnSelectTableNotNullTrue()
    {
        $this->_testColumnSelectTableNullCheck(10, FilterOption::NOT_NULL, true);
        $this->_testColumnSelectTableNullCheck('2', FilterOption::NOT_NULL, true);
        $this->_testColumnSelectTableNullCheck('0', FilterOption::NOT_NULL, true);
        $this->_testColumnSelectTableNullCheck(0, FilterOption::NOT_NULL, true);
    }
    public function testColumnSelectTableNotNullFalse()
    {
        $this->_testColumnSelectTableNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnSelectTableNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnSelectTableNullTrue()
    {
        $this->_testColumnSelectTableNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnSelectTableNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnSelectTableNullFalse()
    {
        $this->_testColumnSelectTableNullCheck(9, FilterOption::NULL, false);
        $this->_testColumnSelectTableNullCheck('2', FilterOption::NULL, false);
        $this->_testColumnSelectTableNullCheck('0', FilterOption::NULL, false);
        $this->_testColumnSelectTableNullCheck(0, FilterOption::NULL, false);
    }
    protected function _testColumnSelectTable($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn(ColumnType::SELECT_TABLE, $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnSelectTableNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck(ColumnType::SELECT_TABLE, $target_value, $filterOption, $result);
    }

    // Custom column Select table multiple ----------------------------------------------------
    public function testColumnSelectTableMultiExistsTrue()
    {
        $this->_testColumnSelectTableMulti([123, 456, 789], [123, '123', [123, 456], [789, 123]], FilterOption::SELECT_EXISTS, true);
    }
    public function testColumnSelectTableMultiExistsFalse()
    {
        $this->_testColumnSelectTableMulti([123, 456, 789], [234, '567', [777]], FilterOption::SELECT_EXISTS, false);
    }
    public function testColumnSelectTableMultiNotExistsTrue()
    {
        $this->_testColumnSelectTableMulti([123, 456, 789], [234, '567', null, 0, [777]], FilterOption::SELECT_NOT_EXISTS, true);
    }
    public function testColumnSelectTableMultiNotExistsFalse()
    {
        $this->_testColumnSelectTableMulti([123, 456, 789], [123, '123', [123, 456], [789, 123]], FilterOption::SELECT_NOT_EXISTS, false);
    }
    public function testColumnSelectTableMultiNotNullTrue()
    {
        $this->_testColumnSelectTableMultiNullCheck(['2'], FilterOption::NOT_NULL, true);
        $this->_testColumnSelectTableMultiNullCheck(['0'], FilterOption::NOT_NULL, true);
        $this->_testColumnSelectTableMultiNullCheck([0], FilterOption::NOT_NULL, true);
    }
    public function testColumnSelectTableMultiNotNullFalse()
    {
        $this->_testColumnSelectTableMultiNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnSelectTableMultiNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnSelectTableMultiNullTrue()
    {
        $this->_testColumnSelectTableMultiNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnSelectTableMultiNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnSelectTableMultiNullFalse()
    {
        $this->_testColumnSelectTableMultiNullCheck([1, 2], FilterOption::NULL, false);
        $this->_testColumnSelectTableMultiNullCheck(['2'], FilterOption::NULL, false);
        $this->_testColumnSelectTableMultiNullCheck(['0'], FilterOption::NULL, false);
        $this->_testColumnSelectTableMultiNullCheck([0], FilterOption::NULL, false);
    }
    protected function _testColumnSelectTableMulti($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn('select_table_multiple', $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnSelectTableMultiNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck('select_table_multiple', $target_value, $filterOption, $result);
    }


    // Custom column yes/no ----------------------------------------------------
    public function testColumnYesNoExistsTrue()
    {
        $this->_testColumnYesNo(1, [1, '1'], FilterOption::SELECT_EXISTS, true);
        $this->_testColumnYesNo(0, [0, '0'], FilterOption::SELECT_EXISTS, true);
    }
    public function testColumnYesNoExistsFalse()
    {
        $this->_testColumnYesNo('1', ["0", null, '', 0], FilterOption::SELECT_EXISTS, false);
    }
    public function testColumnYesNoNotExistsTrue()
    {
        $this->_testColumnYesNo(1, ["0", null, '', 0], FilterOption::SELECT_NOT_EXISTS, true);
    }
    public function testColumnYesNoNotExistsFalse()
    {
        $this->_testColumnYesNo('1', [1, '1'], FilterOption::SELECT_NOT_EXISTS, false);
    }

    public function testColumnYesNoNotNullTrue()
    {
        $this->_testColumnYesNoNullCheck(1, FilterOption::NOT_NULL, true);
        $this->_testColumnYesNoNullCheck('1', FilterOption::NOT_NULL, true);
        $this->_testColumnYesNoNullCheck('0', FilterOption::NOT_NULL, true);
        $this->_testColumnYesNoNullCheck(0, FilterOption::NOT_NULL, true);
    }
    public function testColumnYesNoNotNullFalse()
    {
        $this->_testColumnYesNoNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnYesNoNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnYesNoNullTrue()
    {
        $this->_testColumnYesNoNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnYesNoNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnYesNoNullFalse()
    {
        $this->_testColumnYesNoNullCheck(1, FilterOption::NULL, false);
        $this->_testColumnYesNoNullCheck('1', FilterOption::NULL, false);
        $this->_testColumnYesNoNullCheck('0', FilterOption::NULL, false);
        $this->_testColumnYesNoNullCheck(0, FilterOption::NULL, false);
    }
    protected function _testColumnYesNo($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn(ColumnType::YESNO, $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnYesNoNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck(ColumnType::YESNO, $target_value, $filterOption, $result);
    }


    // Custom column boolean ----------------------------------------------------
    public function testColumnBooleanExistsTrue()
    {
        $this->_testColumnBoolean('ok', ['ok'], FilterOption::SELECT_EXISTS, true);
        $this->_testColumnBoolean('ng', ['ng'], FilterOption::SELECT_EXISTS, true);
        $this->_testColumnBoolean(1, ['1', 1], FilterOption::SELECT_EXISTS, true);
    }
    public function testColumnBooleanExistsFalse()
    {
        $this->_testColumnBoolean('ok', ["ng", null, '', 0], FilterOption::SELECT_EXISTS, false);
        $this->_testColumnBoolean(0, ['1', 1], FilterOption::SELECT_EXISTS, false);
    }
    public function testColumnBooleanNotExistsTrue()
    {
        $this->_testColumnBoolean('ng', ["ok", null, '', 0], FilterOption::SELECT_NOT_EXISTS, true);
    }
    public function testColumnBooleanNotExistsFalse()
    {
        $this->_testColumnBoolean('ok', ['ok'], FilterOption::SELECT_NOT_EXISTS, false);
    }

    public function testColumnBooleanNotNullTrue()
    {
        $this->_testColumnBooleanNullCheck('ok', FilterOption::NOT_NULL, true);
        $this->_testColumnBooleanNullCheck('ng', FilterOption::NOT_NULL, true);
    }
    public function testColumnBooleanNotNullFalse()
    {
        $this->_testColumnBooleanNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnBooleanNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnBooleanNullTrue()
    {
        $this->_testColumnBooleanNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnBooleanNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnBooleanNullFalse()
    {
        $this->_testColumnBooleanNullCheck('ok', FilterOption::NULL, false);
        $this->_testColumnBooleanNullCheck('ng', FilterOption::NULL, false);
        $this->_testColumnBooleanNullCheck(1, FilterOption::NULL, false);
        $this->_testColumnBooleanNullCheck(0, FilterOption::NULL, false);
    }
    protected function _testColumnBoolean($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn(ColumnType::BOOLEAN, $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnBooleanNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck(ColumnType::BOOLEAN, $target_value, $filterOption, $result);
    }


    // Custom column user ----------------------------------------------------
    public function testColumnUserEqUserTrue()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->_testColumnUser(TestDefine::TESTDATA_USER_LOGINID_USER2, [null], FilterOption::USER_EQ_USER, true);
    }
    public function testColumnUserEqUserFalse()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->_testColumnUser(TestDefine::TESTDATA_USER_LOGINID_USER1, [null], FilterOption::USER_EQ_USER, false);
    }
    public function testColumnUserEqTrue()
    {
        $this->_testColumnUser(5, [5, '5'], FilterOption::USER_EQ, true);
    }
    public function testColumnUserEqFalse()
    {
        $this->_testColumnUser('3', ["4", null, '', 0, 5], FilterOption::USER_EQ, false);
    }
    public function testColumnUserNeTrue()
    {
        $this->_testColumnUser(3, ["4", null, '', 0, 5], FilterOption::USER_NE, true);
    }
    public function testColumnUserNeFalse()
    {
        $this->_testColumnUser('7', [7, '7'], FilterOption::USER_NE, false);
    }
    public function testColumnUserNotNullTrue()
    {
        $this->_testColumnUserNullCheck(10, FilterOption::NOT_NULL, true);
        $this->_testColumnUserNullCheck('2', FilterOption::NOT_NULL, true);
        $this->_testColumnUserNullCheck('0', FilterOption::NOT_NULL, true);
        $this->_testColumnUserNullCheck(0, FilterOption::NOT_NULL, true);
    }
    public function testColumnUserNotNullFalse()
    {
        $this->_testColumnUserNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnUserNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnUserNullTrue()
    {
        $this->_testColumnUserNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnUserNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnUserNullFalse()
    {
        $this->_testColumnUserNullCheck(9, FilterOption::NULL, false);
        $this->_testColumnUserNullCheck('2', FilterOption::NULL, false);
        $this->_testColumnUserNullCheck('0', FilterOption::NULL, false);
        $this->_testColumnUserNullCheck(0, FilterOption::NULL, false);
    }
    protected function _testColumnUser($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn(ColumnType::USER, $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnUserNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck(ColumnType::USER, $target_value, $filterOption, $result);
    }

    // Custom column user multiple ----------------------------------------------------
    public function testColumnUserMultiEqUserTrue()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->_testColumnUser(
            [TestDefine::TESTDATA_USER_LOGINID_USER1, TestDefine::TESTDATA_USER_LOGINID_USER2],
            [null],
            FilterOption::USER_EQ_USER,
            true
        );
    }
    public function testColumnUserMultiEqUserFalse()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->_testColumnUser(
            [TestDefine::TESTDATA_USER_LOGINID_USER1, TestDefine::TESTDATA_USER_LOGINID_USER2],
            [null],
            FilterOption::USER_EQ_USER,
            false
        );
    }
    public function testColumnUserMultiNeUserTrue()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->_testColumnUser(
            [TestDefine::TESTDATA_USER_LOGINID_USER1, TestDefine::TESTDATA_USER_LOGINID_ADMIN],
            [null],
            FilterOption::USER_NE_USER,
            true
        );
    }
    public function testColumnUserMultiNeUserFalse()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->_testColumnUser(
            [TestDefine::TESTDATA_USER_LOGINID_USER1, TestDefine::TESTDATA_USER_LOGINID_ADMIN],
            [null],
            FilterOption::USER_NE_USER,
            false
        );
    }
    public function testColumnUserMultiEqTrue()
    {
        $this->_testColumnUserMulti([123, 456, 789], [123, '123', [123, 456], [789, 123]], FilterOption::USER_EQ, true);
    }
    public function testColumnUserMultiEqFalse()
    {
        $this->_testColumnUserMulti([123, 456, 789], [234, '567', [777]], FilterOption::USER_EQ, false);
    }
    public function testColumnUserMultiNeTrue()
    {
        $this->_testColumnUserMulti([123, 456, 789], [234, '567', null, 0, [777]], FilterOption::USER_NE, true);
    }
    public function testColumnUserMultiNeFalse()
    {
        $this->_testColumnUserMulti([123, 456, 789], [123, '123', [123, 456], [789, 123]], FilterOption::USER_NE, false);
    }
    public function testColumnUserMultiNotNullTrue()
    {
        $this->_testColumnUserMultiNullCheck(['2'], FilterOption::NOT_NULL, true);
        $this->_testColumnUserMultiNullCheck(['0'], FilterOption::NOT_NULL, true);
        $this->_testColumnUserMultiNullCheck([0], FilterOption::NOT_NULL, true);
    }
    public function testColumnUserMultiNotNullFalse()
    {
        $this->_testColumnUserMultiNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnUserMultiNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnUserMultiNullTrue()
    {
        $this->_testColumnUserMultiNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnUserMultiNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnUserMultiNullFalse()
    {
        $this->_testColumnUserMultiNullCheck([1, 2], FilterOption::NULL, false);
        $this->_testColumnUserMultiNullCheck(['2'], FilterOption::NULL, false);
        $this->_testColumnUserMultiNullCheck(['0'], FilterOption::NULL, false);
        $this->_testColumnUserMultiNullCheck([0], FilterOption::NULL, false);
    }
    protected function _testColumnUserMulti($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn('user_multiple', $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnUserMultiNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck('user_multiple', $target_value, $filterOption, $result);
    }


    // Custom column organization ----------------------------------------------------
    public function testColumnOrganizationExistsTrue()
    {
        $this->_testColumnOrganization(5, [5, '5'], FilterOption::SELECT_EXISTS, true);
    }
    public function testColumnOrganizationExistsFalse()
    {
        $this->_testColumnOrganization('3', ["4", null, '', 0, 5], FilterOption::SELECT_EXISTS, false);
    }
    public function testColumnOrganizationNotExistsTrue()
    {
        $this->_testColumnOrganization(3, ["4", null, '', 0, 5], FilterOption::SELECT_NOT_EXISTS, true);
    }
    public function testColumnOrganizationNotExistsFalse()
    {
        $this->_testColumnOrganization('7', [7, '7'], FilterOption::SELECT_NOT_EXISTS, false);
    }
    public function testColumnOrganizationNotNullTrue()
    {
        $this->_testColumnOrganizationNullCheck(10, FilterOption::NOT_NULL, true);
        $this->_testColumnOrganizationNullCheck('2', FilterOption::NOT_NULL, true);
        $this->_testColumnOrganizationNullCheck('0', FilterOption::NOT_NULL, true);
        $this->_testColumnOrganizationNullCheck(0, FilterOption::NOT_NULL, true);
    }
    public function testColumnOrganizationNotNullFalse()
    {
        $this->_testColumnOrganizationNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnOrganizationNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnOrganizationNullTrue()
    {
        $this->_testColumnOrganizationNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnOrganizationNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnOrganizationNullFalse()
    {
        $this->_testColumnOrganizationNullCheck(9, FilterOption::NULL, false);
        $this->_testColumnOrganizationNullCheck('2', FilterOption::NULL, false);
        $this->_testColumnOrganizationNullCheck('0', FilterOption::NULL, false);
        $this->_testColumnOrganizationNullCheck(0, FilterOption::NULL, false);
    }
    protected function _testColumnOrganization($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn(ColumnType::ORGANIZATION, $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnOrganizationNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck(ColumnType::ORGANIZATION, $target_value, $filterOption, $result);
    }

    // Custom column organization multiple ----------------------------------------------------
    public function testColumnOrgMultiExistsTrue()
    {
        $this->_testColumnOrgMulti([123, 456, 789], [123, '123', [123, 456], [789, 123]], FilterOption::SELECT_EXISTS, true);
    }
    public function testColumnOrgMultiExistsFalse()
    {
        $this->_testColumnOrgMulti([123, 456, 789], [234, '567', [777]], FilterOption::SELECT_EXISTS, false);
    }
    public function testColumnOrgMultiNotExistsTrue()
    {
        $this->_testColumnOrgMulti([123, 456, 789], [234, '567', null, 0, [777]], FilterOption::SELECT_NOT_EXISTS, true);
    }
    public function testColumnOrgMultiNotExistsFalse()
    {
        $this->_testColumnOrgMulti([123, 456, 789], [123, '123', [123, 456], [789, 123]], FilterOption::SELECT_NOT_EXISTS, false);
    }
    public function testColumnOrgMultiNotNullTrue()
    {
        $this->_testColumnOrgMultiNullCheck(['2'], FilterOption::NOT_NULL, true);
        $this->_testColumnOrgMultiNullCheck(['0'], FilterOption::NOT_NULL, true);
        $this->_testColumnOrgMultiNullCheck([0], FilterOption::NOT_NULL, true);
    }
    public function testColumnOrgMultiNotNullFalse()
    {
        $this->_testColumnOrgMultiNullCheck(null, FilterOption::NOT_NULL, false);
        $this->_testColumnOrgMultiNullCheck('', FilterOption::NOT_NULL, false);
    }
    public function testColumnOrgMultiNullTrue()
    {
        $this->_testColumnOrgMultiNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnOrgMultiNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnOrgMultiNullFalse()
    {
        $this->_testColumnOrgMultiNullCheck([1, 2], FilterOption::NULL, false);
        $this->_testColumnOrgMultiNullCheck(['2'], FilterOption::NULL, false);
        $this->_testColumnOrgMultiNullCheck(['0'], FilterOption::NULL, false);
        $this->_testColumnOrgMultiNullCheck([0], FilterOption::NULL, false);
    }
    protected function _testColumnOrgMulti($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn('organization_multiple', $target_value, $values, $filterOption, $result);
    }
    protected function _testColumnOrgMultiNullCheck($target_value, string $filterOption, bool $result)
    {
        $this->__testColumnNullCheck('organization_multiple', $target_value, $filterOption, $result);
    }


    // Login user ----------------------------------------------------
    public function testLoginUserEqTrue()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $this->__testConditionColumn(ConditionTypeDetail::USER, null, [TestDefine::TESTDATA_USER_LOGINID_USER1], FilterOption::EQ, true);
    }
    public function testLoginUserEqFalse()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $this->__testConditionColumn(ConditionTypeDetail::USER, null, [TestDefine::TESTDATA_USER_LOGINID_USER2], FilterOption::EQ, false);
    }
    public function testLoginUserNeTrue()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $this->__testConditionColumn(ConditionTypeDetail::USER, null, [TestDefine::TESTDATA_USER_LOGINID_USER2], FilterOption::NE, true);
    }
    public function testLoginUserNeFalse()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $this->__testConditionColumn(ConditionTypeDetail::USER, null, [TestDefine::TESTDATA_USER_LOGINID_USER1], FilterOption::NE, false);
    }



    // Login user organization ----------------------------------------------------
    public function testLoginUserOrganizationEqTrue()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB));
        $this->__testConditionColumn(ConditionTypeDetail::ORGANIZATION, null, [TestDefine::TESTDATA_ORGANIZATION_DEV], FilterOption::EQ, true);
    }
    public function testLoginUserOrganizationEqFalse()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB));
        $this->__testConditionColumn(ConditionTypeDetail::ORGANIZATION, null, [TestDefine::TESTDATA_ORGANIZATION_COMPANY1], FilterOption::EQ, false);
    }
    public function testLoginUserOrganizationNeTrue()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB));
        $this->__testConditionColumn(ConditionTypeDetail::ORGANIZATION, null, [TestDefine::TESTDATA_ORGANIZATION_COMPANY1], FilterOption::NE, true);
    }
    public function testLoginUserOrganizationNeFalse()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB));
        $this->__testConditionColumn(ConditionTypeDetail::ORGANIZATION, null, [TestDefine::TESTDATA_ORGANIZATION_DEV], FilterOption::NE, false);
    }



    // Login user role group ----------------------------------------------------
    public function testLoginUserRoleEqTrue()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->__testConditionColumn(ConditionTypeDetail::ROLE, null, [TestDefine::TESTDATA_ROLEGROUP_GENERAL], FilterOption::EQ, true);
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB));
        $this->__testConditionColumn(ConditionTypeDetail::ROLE, null, [TestDefine::TESTDATA_ROLEGROUP_GENERAL], FilterOption::EQ, true);
    }
    public function testLoginUserRoleEqFalse()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $this->__testConditionColumn(ConditionTypeDetail::ROLE, null, [TestDefine::TESTDATA_ROLEGROUP_GENERAL], FilterOption::EQ, false);
    }
    public function testLoginUserRoleNeTrue()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $this->__testConditionColumn(ConditionTypeDetail::ROLE, null, [TestDefine::TESTDATA_ROLEGROUP_GENERAL], FilterOption::SELECT_NOT_EXISTS, true);
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->__testConditionColumn(ConditionTypeDetail::ROLE, null, [TestDefine::TESTDATA_ROLEGROUP_GENERAL], FilterOption::SELECT_NOT_EXISTS, true);
    }
    public function testLoginUserRoleNeFalse()
    {
        $this->be(Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB));
        $this->__testConditionColumn(ConditionTypeDetail::ROLE, null, [TestDefine::TESTDATA_ROLEGROUP_GENERAL], FilterOption::NE, false);
    }



    // form type ----------------------------------------------------
    public function testFormTypeEqTrue()
    {
        $this->__testConditionColumn(ConditionTypeDetail::FORM, null, [FormDataType::SHOW], FilterOption::EQ, true, function () {
            Model\System::setRequestSession(Model\Define::SYSTEM_KEY_SESSION_FORM_DATA_TYPE, FormDataType::SHOW);
        });
    }
    public function testFormTypeEqFalse()
    {
        $this->__testConditionColumn(ConditionTypeDetail::FORM, null, [FormDataType::SHOW], FilterOption::EQ, false, function () {
            Model\System::setRequestSession(Model\Define::SYSTEM_KEY_SESSION_FORM_DATA_TYPE, FormDataType::CREATE);
        });
    }
    public function testFormTypeNeTrue()
    {
        $this->__testConditionColumn(ConditionTypeDetail::FORM, null, [FormDataType::EDIT], FilterOption::NE, true, function () {
            Model\System::setRequestSession(Model\Define::SYSTEM_KEY_SESSION_FORM_DATA_TYPE, FormDataType::CREATE);
        });
    }
    public function testFormTypeNeFalse()
    {
        $this->__testConditionColumn(ConditionTypeDetail::FORM, null, [FormDataType::CREATE], FilterOption::NE, false, function () {
            Model\System::setRequestSession(Model\Define::SYSTEM_KEY_SESSION_FORM_DATA_TYPE, FormDataType::CREATE);
        });
    }




    // workflow ----------------------------------------------------
    public function testWorkflowStatusEqTrue1()
    {
        $this->__testWorkflowStatus(Model\Define::WORKFLOW_START_KEYNAME, null, FilterOption::WORKFLOW_EQ_STATUS, true);
    }
    public function testWorkflowStatusEqTrue2()
    {
        $this->__testWorkflowStatus(Model\Define::WORKFLOW_START_KEYNAME, Model\Define::WORKFLOW_START_KEYNAME, FilterOption::WORKFLOW_EQ_STATUS, true);
    }
    public function testWorkflowStatusEqTrue3()
    {
        $this->__testWorkflowStatus('status1', 'status1', FilterOption::WORKFLOW_EQ_STATUS, true);
    }

    public function testWorkflowStatusEqFalse1()
    {
        $this->__testWorkflowStatus(Model\Define::WORKFLOW_START_KEYNAME, 'status1', FilterOption::WORKFLOW_EQ_STATUS, false);
    }
    public function testWorkflowStatusEqFalse2()
    {
        $this->__testWorkflowStatus('status1', Model\Define::WORKFLOW_START_KEYNAME, FilterOption::WORKFLOW_EQ_STATUS, false);
    }
    public function testWorkflowStatusEqFalse3()
    {
        $this->__testWorkflowStatus('status1', null, FilterOption::WORKFLOW_EQ_STATUS, false);
    }

    public function testWorkflowStatusNeTrue1()
    {
        $this->__testWorkflowStatus(Model\Define::WORKFLOW_START_KEYNAME, 'status1', FilterOption::WORKFLOW_NE_STATUS, true);
    }
    public function testWorkflowStatusNeTrue2()
    {
        $this->__testWorkflowStatus('status1', Model\Define::WORKFLOW_START_KEYNAME, FilterOption::WORKFLOW_NE_STATUS, true);
    }
    public function testWorkflowStatusNeTrue3()
    {
        $this->__testWorkflowStatus('status1', null, FilterOption::WORKFLOW_NE_STATUS, true);
    }

    public function testWorkflowStatusNeFalse1()
    {
        $this->__testWorkflowStatus(Model\Define::WORKFLOW_START_KEYNAME, null, FilterOption::WORKFLOW_NE_STATUS, false);
    }
    public function testWorkflowStatusNeFalse2()
    {
        $this->__testWorkflowStatus(Model\Define::WORKFLOW_START_KEYNAME, Model\Define::WORKFLOW_START_KEYNAME, FilterOption::WORKFLOW_NE_STATUS, false);
    }
    public function testWorkflowStatusNeFalse3()
    {
        $this->__testWorkflowStatus('status1', 'status1', FilterOption::WORKFLOW_NE_STATUS, false);
    }

    public function testWorkflowWorkUser1()
    {
        $this->__testWorkflowWorkUser(true, FilterOption::WORKFLOW_EQ_WORK_USER, true);
    }
    public function testWorkflowWorkUser2()
    {
        $this->__testWorkflowWorkUser(false, FilterOption::WORKFLOW_EQ_WORK_USER, false);
    }



    /**
     * Execute test for custom column
     *
     * @param string $column_name
     * @param mixed $target_value dummy set to value
     * @param array $values dummy set to condition for loop item
     * @param string $filterOption
     * @param boolean $result
     * @return void
     */
    protected function __testColumn(string $column_name, $target_value, array $values, string $filterOption, bool $result, string $tableName = null)
    {
        $this->initAllTest();

        $table_name = $tableName ?? TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $custom_table = CustomTable::getEloquent($table_name);
        $custom_column = CustomColumn::getEloquent($column_name, $custom_table);

        foreach ($values as $value) {
            $custom_value = $custom_table->getValueModel();
            $custom_value->setValue($column_name, $target_value);

            $condition = new Model\Condition([
                'condition_type' => Enums\ConditionType::COLUMN,
                'condition_key' => $filterOption,
                'target_column_id' => $custom_column->id,
                'condition_value' => $value,
            ]);


            $messageValue = is_array($value) ? json_encode($value) : ($value ?? 'null');
            $messageTargetValue = is_array($target_value) ? json_encode($target_value) : ($target_value ?? 'null');

            $isMatchCondition = $condition->isMatchCondition($custom_value);
            $messageIsMatchCondition = $isMatchCondition ? 'true' : 'false';
            $messageResult = $result ? 'true' : 'false';

            $this->assertTrue($isMatchCondition == $result, "value condition {$messageValue} and {$messageTargetValue}, expect result is {$messageResult}, real result is {$messageIsMatchCondition}.");
        }
    }

    /**
     * Execute test for system column
     *
     * @param string $condition_type_detail
     * @param mixed $target_value
     * @param array $values
     * @param string $filterOption
     * @param boolean $result
     * @return void
     */
    protected function __testConditionColumn(string $condition_type_detail, $target_value, array $values, string $filterOption, bool $result, $prevTest = null)
    {
        $this->initAllTest();

        if ($prevTest instanceof \Closure) {
            call_user_func($prevTest);
        }

        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $custom_table = CustomTable::getEloquent($table_name);

        foreach ($values as $value) {
            $custom_value = $custom_table->getValueModel();

            $condition = new Model\Condition([
                'condition_type' => Enums\ConditionType::CONDITION,
                'condition_key' => $filterOption,
                'target_column_id' => $condition_type_detail,
                'condition_value' => $value,
            ]);

            $this->assertMatch($condition->isMatchCondition($custom_value), $result);
        }
    }

    /**
     * Execute test for custom column
     *
     * @param string $column_name
     * @param $target_value
     * @param string $filterOption
     * @param bool $result
     * @return void
     */
    protected function __testColumnNullCheck(string $column_name, $target_value, string $filterOption, bool $result)
    {
        $this->initAllTest();

        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $custom_table = CustomTable::getEloquent($table_name);
        $custom_column = CustomColumn::getEloquent($column_name, $custom_table);

        $custom_value = $custom_table->getValueModel();
        $custom_value->setValue($column_name, $target_value);

        $condition = new Model\Condition([
            'condition_type' => Enums\ConditionType::COLUMN,
            'condition_key' => $filterOption,
            'target_column_id' => $custom_column->id,
        ]);

        $this->assertMatch($condition->isMatchCondition($custom_value), $result);
    }

    /**
     * Execute test for workflow status
     *
     * @param string $status_name
     * @param $value
     * @param string $filterOption
     * @param bool $result
     * @return void
     */
    protected function __testWorkflowStatus(string $status_name, $value, string $filterOption, bool $result)
    {
        $this->initAllTest();

        $table_name = TestDefine::TESTDATA_TABLE_NAME_EDIT;
        $custom_table = CustomTable::getEloquent($table_name);

        // get value all
        $custom_values = $custom_table->getValueModel()->get();
        foreach ($custom_values as $custom_value) {
            $workflow_status_name = $custom_value->workflow_status_name;
            if ($workflow_status_name != $status_name) {
                continue;
            }

            // get workflow status
            /** @var Model\WorkflowStatus|null $workflow_status */
            $workflow_status = Model\WorkflowStatus::where('status_name', $value)->first();
            $condition = new Model\Condition([
                'condition_type' => Enums\ConditionType::WORKFLOW,
                'condition_key' => $filterOption,
                'target_column_id' => 201, //WORKFLOW_STATUS
                'condition_value' =>  $workflow_status ? $workflow_status->id : Model\Define::WORKFLOW_START_KEYNAME,
            ]);

            $this->assertMatch($condition->isMatchCondition($custom_value), $result);
            break;
        }
    }

    /**
     * Execute test for workflow work user
     *
     * @param bool $hasAuth
     * @param string $filterOption
     * @param bool $result
     * @return void
     */
    protected function __testWorkflowWorkUser(bool $hasAuth, string $filterOption, bool $result)
    {
        $this->initAllTest();
        $this->be(Model\LoginUser::find(1));

        $table_name = TestDefine::TESTDATA_TABLE_NAME_EDIT;
        $custom_table = CustomTable::getEloquent($table_name);

        // get value all
        $custom_values = $custom_table->getValueModel()->get();
        foreach ($custom_values as $custom_value) {
            $actions = $custom_value->getWorkflowActions(true, true);
            $hasAction = $actions->count() > 0;
            if ($hasAction !== $hasAuth) {
                continue;
            }

            // get workflow status
            $condition = new Model\Condition([
                'condition_type' => Enums\ConditionType::WORKFLOW,
                'condition_key' => $filterOption,
                'target_column_id' => 202, //WORKFLOW_WORK_USER
            ]);

            $this->assertMatch($condition->isMatchCondition($custom_value), $result);
            break;
        }
    }
}
