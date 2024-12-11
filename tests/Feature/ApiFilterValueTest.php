<?php

namespace Exceedone\Exment\Tests\Feature;

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
use Exceedone\Exment\Tests\Browser\ExmentKitTestCase;

/**
 * Filter value test. For use custom view filter, form priority, workflow, etc.
 */
class ApiFilterValueTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }


    // text ----------------------------------------------------
    public function testConditionValueColumnTextEqual()
    {
        $this->_testConditionValueColumnText(FilterOption::EQ, true);
    }
    public function testConditionValueColumnTextNotEqual()
    {
        $this->_testConditionValueColumnText(FilterOption::NE, true);
    }
    public function testConditionValueColumnTextLike()
    {
        $this->_testConditionValueColumnText(FilterOption::LIKE, true);
    }
    public function testConditionValueColumnTextNotLike()
    {
        $this->_testConditionValueColumnText(FilterOption::NOT_LIKE, true);
    }
    public function testConditionValueColumnTextNotNull()
    {
        $this->_testConditionValueColumnText(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnTextNull()
    {
        $this->_testConditionValueColumnText(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnText(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::TEXT, $filterOption, $hasHtml, 'input[type="text"]');
    }



    // textarea ----------------------------------------------------
    public function testConditionValueColumnTextareaEqual()
    {
        $this->_testConditionValueColumnTextarea(FilterOption::EQ, true);
    }
    public function testConditionValueColumnTextareaNotEqual()
    {
        $this->_testConditionValueColumnTextarea(FilterOption::NE, true);
    }
    public function testConditionValueColumnTextareaLike()
    {
        $this->_testConditionValueColumnTextarea(FilterOption::LIKE, true);
    }
    public function testConditionValueColumnTextareaNotLike()
    {
        $this->_testConditionValueColumnTextarea(FilterOption::NOT_LIKE, true);
    }
    public function testConditionValueColumnTextareaNotNull()
    {
        $this->_testConditionValueColumnTextarea(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnTextareaNull()
    {
        $this->_testConditionValueColumnTextarea(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnTextarea(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::TEXTAREA, $filterOption, $hasHtml, 'textarea');
    }


    // editor ----------------------------------------------------
    public function testConditionValueColumnEditorEqual()
    {
        $this->_testConditionValueColumnEditor(FilterOption::EQ, true);
    }
    public function testConditionValueColumnEditorNotEqual()
    {
        $this->_testConditionValueColumnEditor(FilterOption::NE, true);
    }
    public function testConditionValueColumnEditorLike()
    {
        $this->_testConditionValueColumnEditor(FilterOption::LIKE, true);
    }
    public function testConditionValueColumnEditorNotLike()
    {
        $this->_testConditionValueColumnEditor(FilterOption::NOT_LIKE, true);
    }
    public function testConditionValueColumnEditorNotNull()
    {
        $this->_testConditionValueColumnEditor(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnEditorNull()
    {
        $this->_testConditionValueColumnEditor(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnEditor(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::EDITOR, $filterOption, $hasHtml, 'textarea');
    }


    // url ----------------------------------------------------
    public function testConditionValueColumnUrlEqual()
    {
        $this->_testConditionValueColumnUrl(FilterOption::EQ, true);
    }
    public function testConditionValueColumnUrlNotEqual()
    {
        $this->_testConditionValueColumnUrl(FilterOption::NE, true);
    }
    public function testConditionValueColumnUrlLike()
    {
        $this->_testConditionValueColumnUrl(FilterOption::LIKE, true);
    }
    public function testConditionValueColumnUrlNotLike()
    {
        $this->_testConditionValueColumnUrl(FilterOption::NOT_LIKE, true);
    }
    public function testConditionValueColumnUrlNotNull()
    {
        $this->_testConditionValueColumnUrl(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnUrlNull()
    {
        $this->_testConditionValueColumnUrl(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnUrl(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::URL, $filterOption, $hasHtml, 'input[type="url"]');
    }


    // email ----------------------------------------------------
    public function testConditionValueColumnEmailEqual()
    {
        $this->_testConditionValueColumnEmail(FilterOption::EQ, true);
    }
    public function testConditionValueColumnEmailNotEqual()
    {
        $this->_testConditionValueColumnEmail(FilterOption::NE, true);
    }
    public function testConditionValueColumnEmailLike()
    {
        $this->_testConditionValueColumnEmail(FilterOption::LIKE, true);
    }
    public function testConditionValueColumnEmailNotLike()
    {
        $this->_testConditionValueColumnEmail(FilterOption::NOT_LIKE, true);
    }
    public function testConditionValueColumnEmailNotNull()
    {
        $this->_testConditionValueColumnEmail(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnEmailNull()
    {
        $this->_testConditionValueColumnEmail(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnEmail(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::EMAIL, $filterOption, $hasHtml, 'input[type="email"]');
    }


    // integer ----------------------------------------------------
    public function testConditionValueColumnIntegerEqual()
    {
        $this->_testConditionValueColumnInteger(FilterOption::EQ, true);
    }
    public function testConditionValueColumnIntegerNotEqual()
    {
        $this->_testConditionValueColumnInteger(FilterOption::NE, true);
    }
    public function testConditionValueColumnIntegerGt()
    {
        $this->_testConditionValueColumnInteger(FilterOption::NUMBER_GT, true);
    }
    public function testConditionValueColumnIntegerLt()
    {
        $this->_testConditionValueColumnInteger(FilterOption::NUMBER_LT, true);
    }
    public function testConditionValueColumnIntegerGte()
    {
        $this->_testConditionValueColumnInteger(FilterOption::NUMBER_GTE, true);
    }
    public function testConditionValueColumnIntegerLte()
    {
        $this->_testConditionValueColumnInteger(FilterOption::NUMBER_LTE, true);
    }
    public function testConditionValueColumnIntegerNotNull()
    {
        $this->_testConditionValueColumnInteger(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnIntegerNull()
    {
        $this->_testConditionValueColumnInteger(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnInteger(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::INTEGER, $filterOption, $hasHtml, 'input[type="number"]');
    }



    // decimal ----------------------------------------------------
    public function testConditionValueColumnDecimalEqual()
    {
        $this->_testConditionValueColumnDecimal(FilterOption::EQ, true);
    }
    public function testConditionValueColumnDecimalNotEqual()
    {
        $this->_testConditionValueColumnDecimal(FilterOption::NE, true);
    }
    public function testConditionValueColumnDecimalGt()
    {
        $this->_testConditionValueColumnDecimal(FilterOption::NUMBER_GT, true);
    }
    public function testConditionValueColumnDecimalLt()
    {
        $this->_testConditionValueColumnDecimal(FilterOption::NUMBER_LT, true);
    }
    public function testConditionValueColumnDecimalGte()
    {
        $this->_testConditionValueColumnDecimal(FilterOption::NUMBER_GTE, true);
    }
    public function testConditionValueColumnDecimalLte()
    {
        $this->_testConditionValueColumnDecimal(FilterOption::NUMBER_LTE, true);
    }
    public function testConditionValueColumnDecimalNotNull()
    {
        $this->_testConditionValueColumnDecimal(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnDecimalNull()
    {
        $this->_testConditionValueColumnDecimal(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnDecimal(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::DECIMAL, $filterOption, $hasHtml, 'input[type="text"]');
    }




    // currency ----------------------------------------------------
    public function testConditionValueColumnCurrencyEqual()
    {
        $this->_testConditionValueColumnCurrency(FilterOption::EQ, true);
    }
    public function testConditionValueColumnCurrencyNotEqual()
    {
        $this->_testConditionValueColumnCurrency(FilterOption::NE, true);
    }
    public function testConditionValueColumnCurrencyGt()
    {
        $this->_testConditionValueColumnCurrency(FilterOption::NUMBER_GT, true);
    }
    public function testConditionValueColumnCurrencyLt()
    {
        $this->_testConditionValueColumnCurrency(FilterOption::NUMBER_LT, true);
    }
    public function testConditionValueColumnCurrencyGte()
    {
        $this->_testConditionValueColumnCurrency(FilterOption::NUMBER_GTE, true);
    }
    public function testConditionValueColumnCurrencyLte()
    {
        $this->_testConditionValueColumnCurrency(FilterOption::NUMBER_LTE, true);
    }
    public function testConditionValueColumnCurrencyNotNull()
    {
        $this->_testConditionValueColumnCurrency(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnCurrencyNull()
    {
        $this->_testConditionValueColumnCurrency(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnCurrency(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::CURRENCY, $filterOption, $hasHtml, 'input[type="text"]');
    }





    // date ----------------------------------------------------
    public function testConditionValueColumnDateDayOn()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_ON, true);
    }
    public function testConditionValueColumnDateDayOnOrAfter()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_ON_OR_AFTER, true);
    }
    public function testConditionValueColumnDateDayOnOrBefore()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_ON_OR_BEFORE, true);
    }
    public function testConditionValueColumnDateDayToday()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_TODAY, false);
    }
    public function testConditionValueColumnDateDayTodayOrAfter()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_TODAY_OR_AFTER, false);
    }
    public function testConditionValueColumnDateDayTodayOrBefore()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_TODAY_OR_BEFORE, false);
    }
    public function testConditionValueColumnDateDayYesterday()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_YESTERDAY, false);
    }
    public function testConditionValueColumnDateDayTomorrow()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_TOMORROW, false);
    }
    public function testConditionValueColumnDateDayThisMonth()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_THIS_MONTH, false);
    }
    public function testConditionValueColumnDateDayLastMonth()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_LAST_MONTH, false);
    }
    public function testConditionValueColumnDateDayNextMonth()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_NEXT_MONTH, false);
    }
    public function testConditionValueColumnDateDayThisYear()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_THIS_YEAR, false);
    }
    public function testConditionValueColumnDateDayLastYear()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_LAST_YEAR, false);
    }
    public function testConditionValueColumnDateDayNextYear()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_NEXT_YEAR, false);
    }
    public function testConditionValueColumnDayLastXDayOrAfter()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_LAST_X_DAY_OR_AFTER, true);
    }
    public function testConditionValueColumnDayNextXDayOrAfter()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_NEXT_X_DAY_OR_AFTER, true);
    }
    public function testConditionValueColumnDayLastXDayOrBefore()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_LAST_X_DAY_OR_BEFORE, true);
    }
    public function testConditionValueColumnDayNextXDayOrBefore()
    {
        $this->_testConditionValueColumnDate(FilterOption::DAY_NEXT_X_DAY_OR_BEFORE, true);
    }
    public function testConditionValueColumnDayNotNull()
    {
        $this->_testConditionValueColumnDate(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnDateNull()
    {
        $this->_testConditionValueColumnDate(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnDate(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::DATE, $filterOption, $hasHtml, 'input[type="text"]');
    }


    // time ----------------------------------------------------
    public function testConditionValueColumnTimeEqual()
    {
        $this->_testConditionValueColumnTime(FilterOption::EQ, true);
    }
    public function testConditionValueColumnTimeNotEqual()
    {
        $this->_testConditionValueColumnTime(FilterOption::NE, true);
    }
    public function testConditionValueColumnTimeLike()
    {
        $this->_testConditionValueColumnTime(FilterOption::LIKE, true);
    }
    public function testConditionValueColumnTimeNotLike()
    {
        $this->_testConditionValueColumnTime(FilterOption::NOT_LIKE, true);
    }
    public function testConditionValueColumnTimeNotNull()
    {
        $this->_testConditionValueColumnTime(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnTimeNull()
    {
        $this->_testConditionValueColumnTime(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnTime(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::TIME, $filterOption, $hasHtml, 'input[type="text"]');
    }





    // datetime ----------------------------------------------------
    public function testConditionValueColumnDatetimeDayOn()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_ON, true);
    }
    public function testConditionValueColumnDatetimeDayOnOrAfter()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_ON_OR_AFTER, true);
    }
    public function testConditionValueColumnDatetimeDayOnOrBefore()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_ON_OR_BEFORE, true);
    }
    public function testConditionValueColumnDatetimeDayToday()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_TODAY, false);
    }
    public function testConditionValueColumnDatetimeDayTodayOrAfter()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_TODAY_OR_AFTER, false);
    }
    public function testConditionValueColumnDatetimeDayTodayOrBefore()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_TODAY_OR_BEFORE, false);
    }
    public function testConditionValueColumnDatetimeDayYesterday()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_YESTERDAY, false);
    }
    public function testConditionValueColumnDatetimeDayTomorrow()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_TOMORROW, false);
    }
    public function testConditionValueColumnDatetimeDayThisMonth()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_THIS_MONTH, false);
    }
    public function testConditionValueColumnDatetimeDayLastMonth()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_LAST_MONTH, false);
    }
    public function testConditionValueColumnDatetimeDayNextMonth()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_NEXT_MONTH, false);
    }
    public function testConditionValueColumnDatetimeDayThisYear()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_THIS_YEAR, false);
    }
    public function testConditionValueColumnDatetimeDayLastYear()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_LAST_YEAR, false);
    }
    public function testConditionValueColumnDatetimeDayNextYear()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_NEXT_YEAR, false);
    }
    public function testConditionValueColumnDatetimeDayLastXDayOrAfter()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_LAST_X_DAY_OR_AFTER, true);
    }
    public function testConditionValueColumnDatetimeDayNextXDayOrAfter()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_NEXT_X_DAY_OR_AFTER, true);
    }
    public function testConditionValueColumnDatetimeDayLastXDayOrBefore()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_LAST_X_DAY_OR_BEFORE, true);
    }
    public function testConditionValueColumnDatetimeDayNextXDayOrBefore()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::DAY_NEXT_X_DAY_OR_BEFORE, true);
    }
    public function testConditionValueColumnDatetimeDayNotNull()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnDatetimeDatetimeNull()
    {
        $this->_testConditionValueColumnDatetime(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnDatetime(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::DATETIME, $filterOption, $hasHtml, 'input[type="text"]');
    }



    // select ----------------------------------------------------
    public function testConditionValueColumnSelectExists()
    {
        $this->_testConditionValueColumnSelect(FilterOption::SELECT_EXISTS, true, true);
    }
    public function testConditionValueColumnSelectNotExists()
    {
        $this->_testConditionValueColumnSelect(FilterOption::SELECT_NOT_EXISTS, true, true);
    }
    public function testConditionValueColumnSelectNotNull()
    {
        $this->_testConditionValueColumnSelect(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnSelectNull()
    {
        $this->_testConditionValueColumnSelect(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnSelect(string $filterOption, bool $hasHtml, bool $multiple = false)
    {
        $this->__testConditionValueApiColumn(ColumnType::SELECT, $filterOption, $hasHtml, new ExactSelectOption('select', ['foo' => 'foo', 'bar' => 'bar', 'baz' => 'baz', ]), $multiple);
    }



    // select-valtext ----------------------------------------------------
    public function testConditionValueColumnSelectValTextExists()
    {
        $this->_testConditionValueColumnSelectValText(FilterOption::SELECT_EXISTS, true, true);
    }
    public function testConditionValueColumnSelectValTextNotExists()
    {
        $this->_testConditionValueColumnSelectValText(FilterOption::SELECT_NOT_EXISTS, true, true);
    }
    public function testConditionValueColumnSelectValTextNotNull()
    {
        $this->_testConditionValueColumnSelectValText(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnSelectValTextNull()
    {
        $this->_testConditionValueColumnSelectValText(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnSelectValText(string $filterOption, bool $hasHtml, bool $multiple = false)
    {
        $this->__testConditionValueApiColumn(ColumnType::SELECT_VALTEXT, $filterOption, $hasHtml, new ExactSelectOption('select', ['foo' => 'FOO', 'bar' => 'BAR', 'baz' => 'BAZ', ]), $multiple);
    }



    // select-table ----------------------------------------------------
    public function testConditionValueColumnSelectTableextExists()
    {
        $this->_testConditionValueColumnSelectTable(FilterOption::SELECT_EXISTS, true, true);
    }
    public function testConditionValueColumnSelectTableNotExists()
    {
        $this->_testConditionValueColumnSelectTable(FilterOption::SELECT_NOT_EXISTS, true, true);
    }
    public function testConditionValueColumnSelectTableNotNull()
    {
        $this->_testConditionValueColumnSelectTable(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnSelectTableNull()
    {
        $this->_testConditionValueColumnSelectTable(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnSelectTable(string $filterOption, bool $hasHtml, bool $multiple = false)
    {
        $this->__testConditionValueApiColumn(ColumnType::SELECT_TABLE, $filterOption, $hasHtml, 'select', $multiple);
    }



    // yes-no ----------------------------------------------------
    public function testConditionValueColumnYesNoEqual()
    {
        $this->_testConditionValueColumnYesNo(FilterOption::EQ, true);
    }
    public function testConditionValueColumnYesNoNotEqual()
    {
        $this->_testConditionValueColumnYesNo(FilterOption::NE, true);
    }
    public function testConditionValueColumnYesNoLike()
    {
        $this->_testConditionValueColumnYesNo(FilterOption::LIKE, true);
    }
    public function testConditionValueColumnYesNoNotLike()
    {
        $this->_testConditionValueColumnYesNo(FilterOption::NOT_LIKE, true);
    }
    public function testConditionValueColumnYesNoNotNull()
    {
        $this->_testConditionValueColumnYesNo(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnYesNoNull()
    {
        $this->_testConditionValueColumnYesNo(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnYesNo(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::YESNO, $filterOption, $hasHtml, 'input[type="checkbox"]');
    }



    // boolean ----------------------------------------------------
    public function testConditionValueColumnBooleanEqual()
    {
        $this->_testConditionValueColumnBoolean(FilterOption::EQ, true);
    }
    public function testConditionValueColumnBooleanNotEqual()
    {
        $this->_testConditionValueColumnBoolean(FilterOption::NE, true);
    }
    public function testConditionValueColumnBooleanLike()
    {
        $this->_testConditionValueColumnBoolean(FilterOption::LIKE, true);
    }
    public function testConditionValueColumnBooleanNotLike()
    {
        $this->_testConditionValueColumnBoolean(FilterOption::NOT_LIKE, true);
    }
    public function testConditionValueColumnBooleanNotNull()
    {
        $this->_testConditionValueColumnBoolean(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnBooleanNull()
    {
        $this->_testConditionValueColumnBoolean(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnBoolean(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::BOOLEAN, $filterOption, $hasHtml, 'input[type="checkbox"]');
    }



    // auto-number ----------------------------------------------------
    public function testConditionValueColumnAutoNumberEqual()
    {
        $this->_testConditionValueColumnAutoNumber(FilterOption::EQ, true);
    }
    public function testConditionValueColumnAutoNumberNotEqual()
    {
        $this->_testConditionValueColumnAutoNumber(FilterOption::NE, true);
    }
    public function testConditionValueColumnAutoNumberLike()
    {
        $this->_testConditionValueColumnAutoNumber(FilterOption::LIKE, true);
    }
    public function testConditionValueColumnAutoNumberNotLike()
    {
        $this->_testConditionValueColumnAutoNumber(FilterOption::NOT_LIKE, true);
    }
    public function testConditionValueColumnAutoNumberNotNull()
    {
        $this->_testConditionValueColumnAutoNumber(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnAutoNumberNull()
    {
        $this->_testConditionValueColumnAutoNumber(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnAutoNumber(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::AUTO_NUMBER, $filterOption, $hasHtml, 'input[type="text"]');
    }



    // image ----------------------------------------------------
    public function testConditionValueColumnImageNotNull()
    {
        $this->_testConditionValueColumnImage(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnImageNull()
    {
        $this->_testConditionValueColumnImage(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnImage(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::IMAGE, $filterOption, $hasHtml, 'input[type="file"]');
    }


    // file ----------------------------------------------------
    public function testConditionValueColumnFileNotNull()
    {
        $this->_testConditionValueColumnFile(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnFileNull()
    {
        $this->_testConditionValueColumnFile(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnFile(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiColumn(ColumnType::FILE, $filterOption, $hasHtml, 'input[type="file"]');
    }



    // user ----------------------------------------------------
    public function testConditionValueColumnUserEqLoginUser()
    {
        $this->_testConditionValueColumnUser(FilterOption::USER_EQ_USER, false);
    }
    public function testConditionValueColumnUserNotEqLoginUser()
    {
        $this->_testConditionValueColumnUser(FilterOption::USER_NE_USER, false);
    }
    public function testConditionValueColumnUserEq()
    {
        $this->_testConditionValueColumnUser(FilterOption::USER_EQ, true, true);
    }
    public function testConditionValueColumnUserNe()
    {
        $this->_testConditionValueColumnUser(FilterOption::USER_NE, true, true);
    }
    public function testConditionValueColumnUserNotNull()
    {
        $this->_testConditionValueColumnUser(FilterOption::USER_NOT_NULL, false);
    }
    public function testConditionValueColumnUserNull()
    {
        $this->_testConditionValueColumnUser(FilterOption::USER_NULL, false);
    }
    protected function _testConditionValueColumnUser(string $filterOption, bool $hasHtml, bool $multiple = false)
    {
        $options = CustomTable::getEloquent('user')->getValueQuery()->get()->pluck('label', 'id')->toArray();
        $this->__testConditionValueApiColumn(ColumnType::USER, $filterOption, $hasHtml, new ExactSelectOption('select', $options), $multiple);
    }




    // organization ----------------------------------------------------
    public function testConditionValueColumnOrganizationExists()
    {
        $this->_testConditionValueColumnOrganization(FilterOption::SELECT_EXISTS, true, true);
    }
    public function testConditionValueColumnOrganizationNotExists()
    {
        $this->_testConditionValueColumnOrganization(FilterOption::SELECT_NOT_EXISTS, true, true);
    }
    public function testConditionValueColumnOrganizationNotNull()
    {
        $this->_testConditionValueColumnOrganization(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueColumnOrganizationNull()
    {
        $this->_testConditionValueColumnOrganization(FilterOption::NULL, false);
    }
    protected function _testConditionValueColumnOrganization(string $filterOption, bool $hasHtml, bool $multiple = false)
    {
        $options = CustomTable::getEloquent('organization')->getValueQuery()->get()->pluck('label', 'id')->toArray();
        $this->__testConditionValueApiColumn(ColumnType::ORGANIZATION, $filterOption, $hasHtml, new ExactSelectOption('select', $options), $multiple);
    }



    // system id ----------------------------------------------------
    public function testConditionValueSystemIdEqual()
    {
        $this->_testConditionValueSystemId(FilterOption::EQ, true);
    }
    public function testConditionValueSystemIdNotEqual()
    {
        $this->_testConditionValueSystemId(FilterOption::NE, true);
    }
    public function testConditionValueSystemIdLike()
    {
        $this->_testConditionValueSystemId(FilterOption::LIKE, true);
    }
    public function testConditionValueSystemIdNotLike()
    {
        $this->_testConditionValueSystemId(FilterOption::NOT_LIKE, true);
    }
    public function testConditionValueSystemIdNotNull()
    {
        $this->_testConditionValueSystemId(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueSystemIdNull()
    {
        $this->_testConditionValueSystemId(FilterOption::NULL, false);
    }
    protected function _testConditionValueSystemId(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiSystem(SystemColumn::ID, $filterOption, $hasHtml, 'input[type="text"]');
    }



    // system suuid ----------------------------------------------------
    public function testConditionValueSystemSuuidEqual()
    {
        $this->_testConditionValueSystemSuuid(FilterOption::EQ, true);
    }
    public function testConditionValueSystemSuuidNotEqual()
    {
        $this->_testConditionValueSystemSuuid(FilterOption::NE, true);
    }
    public function testConditionValueSystemSuuidLike()
    {
        $this->_testConditionValueSystemSuuid(FilterOption::LIKE, true);
    }
    public function testConditionValueSystemSuuidNotLike()
    {
        $this->_testConditionValueSystemSuuid(FilterOption::NOT_LIKE, true);
    }
    public function testConditionValueSystemSuuidNotNull()
    {
        $this->_testConditionValueSystemSuuid(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueSystemSuuidNull()
    {
        $this->_testConditionValueSystemSuuid(FilterOption::NULL, false);
    }
    protected function _testConditionValueSystemSuuid(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiSystem(SystemColumn::SUUID, $filterOption, $hasHtml, 'input[type="text"]');
    }



    // system CREATED_AT ----------------------------------------------------
    public function testConditionValueSystemCreatedAtDayOn()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_ON, true);
    }
    public function testConditionValueSystemCreatedAtDayOnOrAfter()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_ON_OR_AFTER, true);
    }
    public function testConditionValueSystemCreatedAtDayOnOrBefore()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_ON_OR_BEFORE, true);
    }
    public function testConditionValueSystemCreatedAtDayToday()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_TODAY, false);
    }
    public function testConditionValueSystemCreatedAtDayTodayOrAfter()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_TODAY_OR_AFTER, false);
    }
    public function testConditionValueSystemCreatedAtDayTodayOrBefore()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_TODAY_OR_BEFORE, false);
    }
    public function testConditionValueSystemCreatedAtDayYesterday()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_YESTERDAY, false);
    }
    public function testConditionValueSystemCreatedAtDayTomorrow()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_TOMORROW, false);
    }
    public function testConditionValueSystemCreatedAtDayThisMonth()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_THIS_MONTH, false);
    }
    public function testConditionValueSystemCreatedAtDayLastMonth()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_LAST_MONTH, false);
    }
    public function testConditionValueSystemCreatedAtDayNextMonth()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_NEXT_MONTH, false);
    }
    public function testConditionValueSystemCreatedAtDayThisYear()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_THIS_YEAR, false);
    }
    public function testConditionValueSystemCreatedAtDayLastYear()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_LAST_YEAR, false);
    }
    public function testConditionValueSystemCreatedAtDayNextYear()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_NEXT_YEAR, false);
    }
    public function testConditionValueSystemCreatedAtDayLastXDayOrAfter()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_LAST_X_DAY_OR_AFTER, true);
    }
    public function testConditionValueSystemCreatedAtDayNextXDayOrAfter()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_NEXT_X_DAY_OR_AFTER, true);
    }
    public function testConditionValueSystemCreatedAtDayLastXDayOrBefore()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_LAST_X_DAY_OR_BEFORE, true);
    }
    public function testConditionValueSystemCreatedAtDayNextXDayOrBefore()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::DAY_NEXT_X_DAY_OR_BEFORE, true);
    }
    public function testConditionValueSystemCreatedAtDayNotNull()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueSystemCreatedAtDatetimeNull()
    {
        $this->_testConditionValueSystemCreatedAt(FilterOption::NULL, false);
    }
    protected function _testConditionValueSystemCreatedAt(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiSystem(SystemColumn::CREATED_AT, $filterOption, $hasHtml, 'input[type="text"]');
    }





    // system UPDATED_AT ----------------------------------------------------
    public function testConditionValueSystemUpdatedAtDayOn()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_ON, true);
    }
    public function testConditionValueSystemUpdatedAtDayOnOrAfter()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_ON_OR_AFTER, true);
    }
    public function testConditionValueSystemUpdatedAtDayOnOrBefore()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_ON_OR_BEFORE, true);
    }
    public function testConditionValueSystemUpdatedAtDayToday()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_TODAY, false);
    }
    public function testConditionValueSystemUpdatedAtDayTodayOrAfter()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_TODAY_OR_AFTER, false);
    }
    public function testConditionValueSystemUpdatedAtDayTodayOrBefore()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_TODAY_OR_BEFORE, false);
    }
    public function testConditionValueSystemUpdatedAtDayYesterday()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_YESTERDAY, false);
    }
    public function testConditionValueSystemUpdatedAtDayTomorrow()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_TOMORROW, false);
    }
    public function testConditionValueSystemUpdatedAtDayThisMonth()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_THIS_MONTH, false);
    }
    public function testConditionValueSystemUpdatedAtDayLastMonth()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_LAST_MONTH, false);
    }
    public function testConditionValueSystemUpdatedAtDayNextMonth()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_NEXT_MONTH, false);
    }
    public function testConditionValueSystemUpdatedAtDayThisYear()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_THIS_YEAR, false);
    }
    public function testConditionValueSystemUpdatedAtDayLastYear()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_LAST_YEAR, false);
    }
    public function testConditionValueSystemUpdatedAtDayNextYear()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_NEXT_YEAR, false);
    }
    public function testConditionValueSystemUpdatedAtDayLastXDayOrAfter()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_LAST_X_DAY_OR_AFTER, true);
    }
    public function testConditionValueSystemUpdatedAtDayNextXDayOrAfter()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_NEXT_X_DAY_OR_AFTER, true);
    }
    public function testConditionValueSystemUpdatedAtDayLastXDayOrBefore()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_LAST_X_DAY_OR_BEFORE, true);
    }
    public function testConditionValueSystemUpdatedAtDayNextXDayOrBefore()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::DAY_NEXT_X_DAY_OR_BEFORE, true);
    }
    public function testConditionValueSystemUpdatedAtDayNotNull()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::NOT_NULL, false);
    }
    public function testConditionValueSystemUpdatedAtDatetimeNull()
    {
        $this->_testConditionValueSystemUpdatedAt(FilterOption::NULL, false);
    }
    protected function _testConditionValueSystemUpdatedAt(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionValueApiSystem(SystemColumn::UPDATED_AT, $filterOption, $hasHtml, 'input[type="text"]');
    }




    // system Created user ----------------------------------------------------
    public function testConditionValueSystemCreatedUserEqLoginUser()
    {
        $this->_testConditionValueApiSystemCreatedUser(FilterOption::USER_EQ_USER, false);
    }
    public function testConditionValueSystemCreatedUserNotEqLoginUser()
    {
        $this->_testConditionValueApiSystemCreatedUser(FilterOption::USER_NE_USER, false);
    }
    public function testConditionValueSystemCreatedUserEq()
    {
        $this->_testConditionValueApiSystemCreatedUser(FilterOption::USER_EQ, true, true);
    }
    public function testConditionValueSystemCreatedUserNe()
    {
        $this->_testConditionValueApiSystemCreatedUser(FilterOption::USER_NE, true, true);
    }
    public function testConditionValueSystemCreatedUserNotNull()
    {
        $this->_testConditionValueApiSystemCreatedUser(FilterOption::USER_NOT_NULL, false);
    }
    public function testConditionValueSystemCreatedUserNull()
    {
        $this->_testConditionValueApiSystemCreatedUser(FilterOption::USER_NULL, false);
    }
    protected function _testConditionValueApiSystemCreatedUser(string $filterOption, bool $hasHtml, bool $multiple = false)
    {
        $this->__testConditionValueApiSystem(SystemColumn::UPDATED_USER, $filterOption, $hasHtml, 'select', $multiple);
    }




    // system Updated user ----------------------------------------------------
    public function testConditionValueSystemUpdatedUserEqLoginUser()
    {
        $this->_testConditionValueApiSystemUpdatedUser(FilterOption::USER_EQ_USER, false);
    }
    public function testConditionValueSystemUpdatedUserNotEqLoginUser()
    {
        $this->_testConditionValueApiSystemUpdatedUser(FilterOption::USER_NE_USER, false);
    }
    public function testConditionValueSystemUpdatedUserEq()
    {
        $this->_testConditionValueApiSystemUpdatedUser(FilterOption::USER_EQ, true, true);
    }
    public function testConditionValueSystemUpdatedUserNe()
    {
        $this->_testConditionValueApiSystemUpdatedUser(FilterOption::USER_NE, true, true);
    }
    public function testConditionValueSystemUpdatedUserNotNull()
    {
        $this->_testConditionValueApiSystemUpdatedUser(FilterOption::USER_NOT_NULL, false);
    }
    public function testConditionValueSystemUpdatedUserNull()
    {
        $this->_testConditionValueApiSystemUpdatedUser(FilterOption::USER_NULL, false);
    }
    protected function _testConditionValueApiSystemUpdatedUser(string $filterOption, bool $hasHtml, bool $multiple = false)
    {
        $this->__testConditionValueApiSystem(SystemColumn::UPDATED_USER, $filterOption, $hasHtml, 'select', $multiple);
    }



    // Condition detail user ----------------------------------------------------
    public function testConditionValueConditionDetailUserEq()
    {
        $this->_testConditionValueApiConditionDetailUser(FilterOption::EQ, true);
    }
    public function testConditionValueConditionDetailUserNe()
    {
        $this->_testConditionValueApiConditionDetailUser(FilterOption::NE, true);
    }
    protected function _testConditionValueApiConditionDetailUser(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionApiConditionDetail(ConditionTypeDetail::USER, $filterOption, $hasHtml, 'select', true);
    }



    // Condition detail org ----------------------------------------------------
    public function testConditionValueConditionDetailOrgEq()
    {
        $this->_testConditionValueApiConditionDetailOrg(FilterOption::EQ, true);
    }
    public function testConditionValueConditionDetailOrgNe()
    {
        $this->_testConditionValueApiConditionDetailOrg(FilterOption::NE, true);
    }
    protected function _testConditionValueApiConditionDetailOrg(string $filterOption, bool $hasHtml)
    {
        $this->__testConditionApiConditionDetail(ConditionTypeDetail::ORGANIZATION, $filterOption, $hasHtml, 'select', true);
    }



    // Condition detail role ----------------------------------------------------
    public function testConditionValueConditionDetailRoleEq()
    {
        $this->_testConditionValueApiConditionDetailRole(FilterOption::EQ, true);
    }
    public function testConditionValueConditionDetailRoleNe()
    {
        $this->_testConditionValueApiConditionDetailRole(FilterOption::NE, true);
    }
    protected function _testConditionValueApiConditionDetailRole(string $filterOption, bool $hasHtml)
    {
        $options = Model\RoleGroup::all()->pluck('role_group_view_name', 'id')->toArray();
        $this->__testConditionApiConditionDetail(ConditionTypeDetail::ROLE, $filterOption, $hasHtml, new ExactSelectOption('select', $options), true);
    }



    // Condition detail form ----------------------------------------------------
    public function testConditionValueConditionDetailFormEq()
    {
        $this->_testConditionValueApiConditionDetailForm(FilterOption::EQ, true);
    }
    public function testConditionValueConditionDetailFormNe()
    {
        $this->_testConditionValueApiConditionDetailForm(FilterOption::NE, true);
    }
    protected function _testConditionValueApiConditionDetailForm(string $filterOption, bool $hasHtml)
    {
        $options = Enums\FormDataType::transArray('condition.form_data_type_options');
        $this->__testConditionApiConditionDetail(ConditionTypeDetail::FORM, $filterOption, $hasHtml, new ExactSelectOption('select', $options), true);
    }



    // Workflow item ----------------------------------------------------
    public function testConditionValueWorkflowStatusEq()
    {
        $this->_testConditionValueApiWorkflowStatus(FilterOption::EQ, true);
    }
    public function testConditionValueWorkflowStatus()
    {
        $this->_testConditionValueApiWorkflowStatus(FilterOption::NE, true);
    }
    protected function _testConditionValueApiWorkflowStatus(string $filterOption, bool $hasHtml)
    {
        $workflow = Model\Workflow::getWorkflowByTable(Model\CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT));
        $options = $workflow->getStatusOptions()->toArray();
        $this->__testConditionApiWorkflow('workflow_status', $filterOption, $hasHtml, new ExactSelectOption('select', $options), true);
    }

    public function testConditionValueWorkflowWorkUser()
    {
        $this->__testConditionApiWorkflow('workflow_work_users', FilterOption::USER_EQ_USER, false, 'select');
    }

    /**
     * Test condition api result.
     * This condition api returns select options, ex {'id': 1, 'name': 'eq'}
     *
     * @param string $column_name
     * @param string $cond_key
     * @param bool $hasHtml
     * @param $selector
     * @param bool $multiple
     * @return void
     */
    protected function __testConditionValueApiColumn(string $column_name, string $cond_key, bool $hasHtml, $selector, bool $multiple = false)
    {
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS;
        $custom_table = CustomTable::getEloquent($table_name);
        $custom_column = CustomColumn::getEloquent($column_name, $custom_table);

        $url = admin_urls_query('webapi', $custom_table->table_name, 'filter-value', [
            'target' => $custom_column->id,
            'table_id' => $custom_table->id,
            'cond_key' => $cond_key,


            // fixed key for api.
            'cond_name' => 'custom_view_filters[1][view_filter_condition]',
            'replace_search' => 'view_filter_condition',
            'replace_word' => 'view_filter_condition_value',
            'show_condition_key' => '1',
        ]);

        $this->checkTestResult($url, $hasHtml, $selector, $multiple);
    }

    /**
     * Test condition api result for system
     * This condition api returns select options, ex {'id': 1, 'name': 'eq'}
     *
     * @param string $system_column_name
     * @param string $cond_key
     * @param bool $hasHtml
     * @param $selector
     * @param bool $multiple
     * @return void
     */
    protected function __testConditionValueApiSystem(string $system_column_name, string $cond_key, bool $hasHtml, $selector, bool $multiple = false)
    {
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS;
        $custom_table = CustomTable::getEloquent($table_name);
        $syetem_column = SystemColumn::getOption(['name' => $system_column_name]);

        $url = admin_urls_query('webapi', $custom_table->table_name, 'filter-value', [
            'target' => $syetem_column['name'],
            'table_id' => $custom_table->id,
            'cond_key' => $cond_key,

            // fixed key for api.
            'cond_name' => 'custom_view_filters[1][view_filter_condition]',
            'replace_search' => 'view_filter_condition',
            'replace_word' => 'view_filter_condition_value',
            'show_condition_key' => '1',
        ]);

        $this->checkTestResult($url, $hasHtml, $selector, $multiple);
    }

    /**
     * Test condition api result for system
     * This condition api returns select options, ex {'id': 1, 'name': 'eq'}
     *
     * @param string $condition_type_detail
     * @param string $cond_key
     * @param bool $hasHtml
     * @param $selector
     * @param bool $multiple
     * @return void
     */
    protected function __testConditionApiConditionDetail(string $condition_type_detail, string $cond_key, bool $hasHtml, $selector, bool $multiple = false)
    {
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS;
        $custom_table = CustomTable::getEloquent($table_name);

        $url = admin_urls_query('webapi', $custom_table->table_name, 'filter-value', [
            'target' => ConditionTypeDetail::getEnum($condition_type_detail)->upperKey(),
            'table_id' => $custom_table->id,
            'cond_key' => $cond_key,

            // fixed key for api.
            'cond_name' => 'custom_view_filters[1][view_filter_condition]',
            'replace_search' => 'view_filter_condition',
            'replace_word' => 'view_filter_condition_value',
            'show_condition_key' => '1',
        ]);

        $this->checkTestResult($url, $hasHtml, $selector, $multiple);
    }

    /**
     * Test condition api result for workflow
     * This condition api returns select options, ex {'id': 1, 'name': 'eq'}
     *
     * @param string $type
     * @param string $cond_key
     * @param bool $hasHtml
     * @param $selector
     * @param bool $multiple
     * @return void
     */
    protected function __testConditionApiWorkflow(string $type, string $cond_key, bool $hasHtml, $selector, bool $multiple = false)
    {
        // workflow table
        $table_name = TestDefine::TESTDATA_TABLE_NAME_EDIT;
        $custom_table = CustomTable::getEloquent($table_name);

        $url = admin_urls_query('webapi', $custom_table->table_name, 'filter-value', [
            'target' => "$type?table_id={$custom_table->id}",
            'cond_key' => $cond_key,

            // fixed key for api.
            'cond_name' => 'custom_view_filters[1][view_filter_condition]',
            'replace_search' => 'view_filter_condition',
            'replace_word' => 'view_filter_condition_value',
            'show_condition_key' => '1',
        ]);

        $this->checkTestResult($url, $hasHtml, $selector, $multiple);
    }


    protected function checkTestResult(string $url, bool $hasHtml, $selector, bool $multiple = false)
    {
        $this->get($url);

        $response = $this->response->getContent();
        $this->assertTrue(is_json($response), "response is not json. response is $response");

        $json = json_decode_ex($response, true);
        $html = array_get($json, 'html');

        // check element result
        if ($selector instanceof PageConstraint) {
            $hasElement = $selector;
        } else {
            $hasElement = new HasElement($selector);
        }

        if (!$hasHtml) {
            $hasElement = new ReversePageConstraint($hasElement);
        }
        $this->assertThat($html, $hasElement);

        // check name
        $hasElement = new HasElement('[name="custom_view_filters[1][view_filter_condition_value]' . ($multiple ? '[]' : '') . '"]');
        if (!$hasHtml) {
            $hasElement = new ReversePageConstraint($hasElement);
        }
        $this->assertThat($html, $hasElement);
    }
}
