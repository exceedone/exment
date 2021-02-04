<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Laravel\BrowserKitTesting\Constraints\HasElement;
use Laravel\BrowserKitTesting\Constraints\ReversePageConstraint;
use Laravel\BrowserKitTesting\Constraints\PageConstraint;
use Exceedone\Exment\Tests\Constraints\ExactSelectOption;
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
        // TODO:井坂　要修正
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
        // TODO:井坂　要修正
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
        // TODO:井坂　要修正
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
        // TODO:井坂　要修正
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
        // TODO 井坂 要修正
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
        $this->_testColumnDate(Carbon::today()->subMonths(1)->firstOfMonth()->format('Y-m-d'), [null], FilterOption::DAY_LAST_MONTH, true);
        $this->_testColumnDate(Carbon::today()->subMonths(1)->lastOfMonth()->format('Y/m/d'), [null], FilterOption::DAY_LAST_MONTH, true);
        $this->_testColumnDate(Carbon::today()->subMonths(1)->format('Ymd'), [null], FilterOption::DAY_LAST_MONTH, true);
        $this->_testColumnDate(Carbon::now()->subMonths(1)->format('Y-m-d H:i:s.u'), [null], FilterOption::DAY_LAST_MONTH, true);
    }
    public function testColumnDateDayLastMonthFalse()
    {
        $this->_testColumnDate(Carbon::today()->addMonths(1)->format('Y-m-d'), [null], FilterOption::DAY_LAST_MONTH, false);
        $this->_testColumnDate(Carbon::today()->format('Y/m/d'), [null], FilterOption::DAY_LAST_MONTH, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_LAST_MONTH, false);
    }
    public function testColumnDateDayNextMonthTrue()
    {
        $this->_testColumnDate(Carbon::today()->addMonths(1)->firstOfMonth()->format('Y-m-d'), [null], FilterOption::DAY_NEXT_MONTH, true);
        $this->_testColumnDate(Carbon::today()->addMonths(1)->lastOfMonth()->format('Y/m/d'), [null], FilterOption::DAY_NEXT_MONTH, true);
        $this->_testColumnDate(Carbon::today()->addMonths(1)->format('Ymd'), [null], FilterOption::DAY_NEXT_MONTH, true);
        $this->_testColumnDate(Carbon::now()->addMonths(1)->format('Y-m-d H:i:s.u'), [null], FilterOption::DAY_NEXT_MONTH, true);
    }
    public function testColumnDateDayNextMonthFalse()
    {
        $this->_testColumnDate(Carbon::today()->subMonths(1)->format('Y-m-d'), [null], FilterOption::DAY_NEXT_MONTH, false);
        $this->_testColumnDate(Carbon::today()->format('Y/m/d'), [null], FilterOption::DAY_NEXT_MONTH, false);
        $this->_testColumnDate(null, [null], FilterOption::DAY_NEXT_MONTH, false);
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
    }
    public function testColumnSelectMultiExistsFalse()
    {
        $this->_testColumnSelectMulti(['foo', 'bar'], ['baz', null, 0], FilterOption::SELECT_EXISTS, false);
        $this->_testColumnSelectMulti([123, 456, 789], [234, '567', [777]], FilterOption::SELECT_EXISTS, false);
    }
    public function testColumnSelectMultiNotExistsTrue()
    {
        $this->_testColumnSelectMulti(['foo', 'bar'], ['baz', null, 0], FilterOption::SELECT_NOT_EXISTS, true);
        $this->_testColumnSelectMulti([123, 456, 789], [234, '567', [777]], FilterOption::SELECT_NOT_EXISTS, true);
    }
    public function testColumnSelectMultiNotExistsFalse()
    {
        $this->_testColumnSelectMulti(['foo', 'bar'], ['foo', 'bar', ['foo', 'bar']], FilterOption::SELECT_NOT_EXISTS, false);
        $this->_testColumnSelectMulti([123, 456, 789], [123, '123', [123, 456], [789, 123]], FilterOption::SELECT_NOT_EXISTS, false);
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
    }
    public function testColumnSelectMultiNullTrue()
    {
        $this->_testColumnSelectMultiNullCheck(null, FilterOption::NULL, true);
        $this->_testColumnSelectMultiNullCheck('', FilterOption::NULL, true);
    }
    public function testColumnSelectMultiNullFalse()
    {
        $this->_testColumnSelectMultiNullCheck(['baz'], FilterOption::NULL, false);
        $this->_testColumnSelectMultiNullCheck([1, 2], FilterOption::NULL, false);
        $this->_testColumnSelectMultiNullCheck(['2'], FilterOption::NULL, false);
        $this->_testColumnSelectMultiNullCheck(['0'], FilterOption::NULL, false);
        $this->_testColumnSelectMultiNullCheck([0], FilterOption::NULL, false);
    }
    protected function _testColumnSelectMulti($target_value, array $values, string $filterOption, bool $result)
    {
        $this->__testColumn('select_multiple', $target_value, $values, $filterOption, $result);
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


    /**
     * Execute test for custom column
     *
     * @param string $column_name
     * @param mixed $target_value
     * @param array $values
     * @param string $filterOption
     * @param boolean $result
     * @return void
     */
    protected function __testColumn(string $column_name, $target_value, array $values, string $filterOption, bool $result)
    {
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $custom_table = CustomTable::getEloquent($table_name);
        $custom_column = CustomColumn::getEloquent($column_name, $custom_table);

        foreach($values as $value)
        {
            $custom_value = $custom_table->getValueModel();
            $custom_value->setValue($column_name, $target_value);

            $condition = new Model\Condition([
                'condition_type' => Enums\ConditionType::COLUMN,
                'condition_key' => $filterOption,
                'target_column_id' => $custom_column->id,
                'condition_value' => $value,
            ]);

            $this->assertMatch($condition->isMatchCondition($custom_value), $result);
        }
    }
    

    /**
     * Execute test for custom column
     *
     * @param string $column_name
     * @param mixed $target_value
     * @param array $values
     * @param string $filterOption
     * @param boolean $result
     * @return void
     */
    protected function __testColumnNullCheck(string $column_name, $target_value, string $filterOption, bool $result)
    {
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
}
