<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ValueType;
use Exceedone\Exment\Enums\CurrencySymbol;
use Exceedone\Exment\Enums\SystemTableName;

class CustomColumnTest extends UnitTestBase
{
    use CustomColumnTrait;

    // Text ----------------------------------------------------
    public function _testText($value_type)
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::TEXT);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, 'text');

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, 'text');
    }
    public function testTextValue()
    {
        return $this->_testText(ValueType::VALUE);
    }
    public function testTextText()
    {
        return $this->_testText(ValueType::TEXT);
    }
    public function testTextHtml()
    {
        return $this->_testText(ValueType::HTML);
    }


    // TextArea ----------------------------------------------------
    public const TEXTAREA_VALUE = 'text1\r\ntext2\r\ntext3\r\n<a href="abc">aaa</a>';
    public function _testTextarea($value_type, $matchedValue)
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::TEXTAREA);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::TEXTAREA_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testTextareaValue()
    {
        return $this->_testTextarea(ValueType::VALUE, static::TEXTAREA_VALUE);
    }
    public function testTextareaText()
    {
        return $this->_testTextarea(ValueType::TEXT, static::TEXTAREA_VALUE);
    }
    public function testTextareaHtml()
    {
        return $this->_testTextarea(ValueType::HTML, preg_replace('/ /', '<span style="margin-right: 0.5em;"></span>', replaceBreakEsc(static::TEXTAREA_VALUE)));
    }




    // Editor ----------------------------------------------------
    public const EDITOR_VALUE = "<p>normal</p>\r\n<p><strong>bold</strong></p>\r\n<p><span style=\"text-decoration: underline;\">under</span></p>\r\n<p><span style=\"color: #ff0000;\">red</span></p>";
    public function _testEditor($value_type, $matchedValue)
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::EDITOR);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::EDITOR_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testEditorValue()
    {
        return $this->_testEditor(ValueType::VALUE, static::EDITOR_VALUE);
    }
    public function testEditorText()
    {
        return $this->_testEditor(ValueType::TEXT, static::EDITOR_VALUE);
    }
    public function testEditorHtml()
    {
        return $this->_testEditor(ValueType::HTML, '<div class="show-tinymce">'.replaceBreak(html_clean(static::EDITOR_VALUE), false).'</div>');
    }





    // URL ----------------------------------------------------
    public const URL_VALUE = "https://github.com/exceedone/exment/";
    public function _testUrl($value_type, $matchedValue)
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::URL);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::URL_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testUrlValue()
    {
        return $this->_testUrl(ValueType::VALUE, static::URL_VALUE);
    }
    public function testUrlText()
    {
        return $this->_testUrl(ValueType::TEXT, static::URL_VALUE);
    }
    public function testUrlHtml()
    {
        return $this->_testUrl(ValueType::HTML, '<a href="' . static::URL_VALUE . '" target="_blank">' . static::URL_VALUE . "</a>");
    }





    // EMAIL ----------------------------------------------------
    public const EMAIL_VALUE = "test@foobar.test";
    public function _testEmail($value_type, $matchedValue)
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::EMAIL);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::EMAIL_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testEmailValue()
    {
        return $this->_testEmail(ValueType::VALUE, static::EMAIL_VALUE);
    }
    public function testEmailText()
    {
        return $this->_testEmail(ValueType::TEXT, static::EMAIL_VALUE);
    }
    public function testEmailHtml()
    {
        return $this->_testEmail(ValueType::HTML, static::EMAIL_VALUE);
    }




    // INTEGER ----------------------------------------------------
    public const INTEGER_VALUE = 1000;
    public function _testInteger($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::INTEGER, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::INTEGER_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testIntegerValue()
    {
        return $this->_testInteger(ValueType::VALUE, static::INTEGER_VALUE);
    }
    public function testIntegerText()
    {
        return $this->_testInteger(ValueType::TEXT, static::INTEGER_VALUE);
    }
    public function testIntegerHtml()
    {
        return $this->_testInteger(ValueType::HTML, static::INTEGER_VALUE);
    }
    //comma
    public function testIntegerValueComma()
    {
        return $this->_testInteger(ValueType::VALUE, '1000', ['number_format' => 1]);
    }
    public function testIntegerTextComma()
    {
        return $this->_testInteger(ValueType::TEXT, '1,000', ['number_format' => 1]);
    }
    public function testIntegerHtmlComma()
    {
        return $this->_testInteger(ValueType::HTML, '1,000', ['number_format' => 1]);
    }




    // DECIMAL ----------------------------------------------------
    public const DECIMAL_VALUE = 1000.25;
    public const DECIMAL_VALUE2 = 1000;
    public const DECIMAL_VALUE3 = 1000.2;
    public function _testDecimal($value_type, $matchedValue, $options = [], $originalValue = null)
    {
        $originalValue = $originalValue?? static::CURRENCY_VALUE;
        $custom_column = $this->getCustomColumnModel(ColumnType::DECIMAL, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, $originalValue);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testDecimalValue()
    {
        return $this->_testDecimal(ValueType::VALUE, static::DECIMAL_VALUE);
    }
    public function testDecimalText()
    {
        return $this->_testDecimal(ValueType::TEXT, static::DECIMAL_VALUE);
    }
    public function testDecimalHtml()
    {
        return $this->_testDecimal(ValueType::HTML, static::DECIMAL_VALUE);
    }
    //comma(only test, html)
    public function testDecimalValueComma()
    {
        return $this->_testDecimal(ValueType::VALUE, '1000.25', ['number_format' => 1, 'decimal_digit' => 2]);
    }
    public function testDecimalTextComma()
    {
        return $this->_testDecimal(ValueType::TEXT, '1,000.25', ['number_format' => 1, 'decimal_digit' => 2]);
    }
    public function testDecimalHtmlComma()
    {
        return $this->_testDecimal(ValueType::HTML, '1,000.25', ['number_format' => 1, 'decimal_digit' => 2]);
    }
    //percent(only html)
    public function testDecimalValuePercent()
    {
        return $this->_testDecimal(ValueType::VALUE, '1000.25', ['percent_format' => 1, 'decimal_digit' => 2]);
    }
    public function testDecimalTextPercent()
    {
        return $this->_testDecimal(ValueType::TEXT, '1000.25', ['percent_format' => 1, 'decimal_digit' => 2]);
    }
    public function testDecimalHtmlPercent()
    {
        return $this->_testDecimal(ValueType::HTML, '100025%', ['percent_format' => 1, 'decimal_digit' => 2]);
    }
    public function testDecimalText2()
    {
        return $this->_testDecimal(ValueType::TEXT, '1000', ['decimal_digit' => 2], static::DECIMAL_VALUE2);
    }
    public function testDecimalTextComma2()
    {
        return $this->_testDecimal(ValueType::TEXT, '1,000', ['number_format' => 1, 'decimal_digit' => 2], static::DECIMAL_VALUE2);
    }
    public function testDecimalText3()
    {
        return $this->_testDecimal(ValueType::TEXT, '1000.2', ['decimal_digit' => 2], static::DECIMAL_VALUE3);
    }
    public function testDecimalTextComma3()
    {
        return $this->_testDecimal(ValueType::TEXT, '1,000.2', ['number_format' => 1, 'decimal_digit' => 2], static::DECIMAL_VALUE3);
    }




    // CURRENCY ----------------------------------------------------
    public const CURRENCY_VALUE = 1000.25;
    public const CURRENCY_VALUE2 = 1000;
    public const CURRENCY_VALUE3 = 1000.2;
    public function _testCurrency($value_type, $matchedValue, $options = [], $originalValue = null)
    {
        $originalValue = $originalValue?? static::CURRENCY_VALUE;
        $custom_column = $this->getCustomColumnModel(ColumnType::CURRENCY, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, $originalValue);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testCurrencyValue()
    {
        return $this->_testCurrency(ValueType::VALUE, static::CURRENCY_VALUE, ['currency_symbol' => CurrencySymbol::JPY1]);
    }
    public function testCurrencyText()
    {
        return $this->_testCurrency(ValueType::TEXT, '¥' . static::CURRENCY_VALUE, ['currency_symbol' => CurrencySymbol::JPY1]);
    }
    public function testCurrencyHtml()
    {
        return $this->_testCurrency(ValueType::HTML, '&yen;' . static::CURRENCY_VALUE, ['currency_symbol' => CurrencySymbol::JPY1]);
    }
    //comma(only test, html)
    public function testCurrencyValueComma()
    {
        return $this->_testCurrency(ValueType::VALUE, '1000.25', ['currency_symbol' => CurrencySymbol::JPY1, 'number_format' => 1, 'decimal_digit' => 2]);
    }
    public function testCurrencyTextComma()
    {
        return $this->_testCurrency(ValueType::TEXT, '¥1,000.25', ['currency_symbol' => CurrencySymbol::JPY1, 'number_format' => 1, 'decimal_digit' => 2]);
    }
    public function testCurrencyHtmlComma()
    {
        return $this->_testCurrency(ValueType::HTML, '&yen;1,000.25', ['currency_symbol' => CurrencySymbol::JPY1, 'number_format' => 1, 'decimal_digit' => 2]);
    }
    public function testCurrencyText2()
    {
        return $this->_testCurrency(
            ValueType::TEXT,
            '1000.00円',
            ['currency_symbol' => CurrencySymbol::JPY2,
             'decimal_digit' => 2],
            static::CURRENCY_VALUE2
        );
    }
    public function testCurrencyTextComma2()
    {
        return $this->_testCurrency(
            ValueType::TEXT,
            '1,000.00円',
            ['currency_symbol' => CurrencySymbol::JPY2,
             'number_format' => 1,
             'decimal_digit' => 2],
            static::CURRENCY_VALUE2
        );
    }
    public function testCurrencyText3()
    {
        return $this->_testCurrency(
            ValueType::TEXT,
            '$1000.20',
            ['currency_symbol' => CurrencySymbol::USD,
             'decimal_digit' => 2],
            static::CURRENCY_VALUE3
        );
    }
    public function testCurrencyTextComma3()
    {
        return $this->_testCurrency(
            ValueType::TEXT,
            '$1,000.20',
            ['currency_symbol' => CurrencySymbol::USD,
             'number_format' => 1,
             'decimal_digit' => 2],
            static::CURRENCY_VALUE3
        );
    }




    // DATE ----------------------------------------------------
    public const DATE_VALUE = '2020/06/12';
    public const DATE_VALUE_FORMAT = '2020-06-12';
    public function _testDate($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::DATE, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::DATE_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testDateValue()
    {
        return $this->_testDate(ValueType::VALUE, static::DATE_VALUE);
    }
    public function testDateText()
    {
        return $this->_testDate(ValueType::TEXT, static::DATE_VALUE);
    }
    public function testDateHtml()
    {
        return $this->_testDate(ValueType::HTML, static::DATE_VALUE);
    }
    // format(only text and html)
    public function testDateValueFormat()
    {
        return $this->_testDate(ValueType::VALUE, static::DATE_VALUE, ['format' => 'Y-m-d']);
    }
    public function testDateTextFormat()
    {
        return $this->_testDate(ValueType::TEXT, static::DATE_VALUE_FORMAT, ['format' => 'Y-m-d']);
    }
    public function testDateHtmlFormat()
    {
        return $this->_testDate(ValueType::HTML, static::DATE_VALUE_FORMAT, ['format' => 'Y-m-d']);
    }







    // TIME ----------------------------------------------------
    public const TIME_VALUE = '20:10:00';
    public const TIME_VALUE_FORMAT = '201000';
    public function _testTime($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::TIME, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::TIME_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testTimeValue()
    {
        return $this->_testTime(ValueType::VALUE, static::TIME_VALUE);
    }
    public function testTimeText()
    {
        return $this->_testTime(ValueType::TEXT, static::TIME_VALUE);
    }
    public function testTimeHtml()
    {
        return $this->_testTime(ValueType::HTML, static::TIME_VALUE);
    }
    // format(only text and html)
    public function testTimeValueFormat()
    {
        return $this->_testTime(ValueType::VALUE, static::TIME_VALUE, ['format' => 'His']);
    }
    public function testTimeTextFormat()
    {
        return $this->_testTime(ValueType::TEXT, static::TIME_VALUE_FORMAT, ['format' => 'His']);
    }
    public function testTimeHtmlFormat()
    {
        return $this->_testTime(ValueType::HTML, static::TIME_VALUE_FORMAT, ['format' => 'His']);
    }







    // DATETIME ----------------------------------------------------
    public const DATETIME_VALUE = '2020/06/12 20:10:00';
    public const DATETIME_VALUE_FORMAT = '2020-06-12 201000';
    public function _testDateTime($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::DATETIME, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::DATETIME_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testDateTimeValue()
    {
        return $this->_testDateTime(ValueType::VALUE, static::DATETIME_VALUE);
    }
    public function testDateTimeText()
    {
        return $this->_testDateTime(ValueType::TEXT, static::DATETIME_VALUE);
    }
    public function testDateTimeHtml()
    {
        return $this->_testDateTime(ValueType::HTML, static::DATETIME_VALUE);
    }
    // format(only text and html)
    public function testDateTimeValueFormat()
    {
        return $this->_testDateTime(ValueType::VALUE, static::DATETIME_VALUE, ['format' => 'Y-m-d His']);
    }
    public function testDateTimeTextFormat()
    {
        return $this->_testDateTime(ValueType::TEXT, static::DATETIME_VALUE_FORMAT, ['format' => 'Y-m-d His']);
    }
    public function testDateTimeHtmlFormat()
    {
        return $this->_testDateTime(ValueType::HTML, static::DATETIME_VALUE_FORMAT, ['format' => 'Y-m-d His']);
    }





    // SELECT ----------------------------------------------------
    public const SELECT_VALUE = 'orange';
    public function _testSelect($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::SELECT_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testSelectValue()
    {
        return $this->_testSelect(ValueType::VALUE, static::SELECT_VALUE, ["select_item" => "orange\r\nbanana\r\napple"]);
    }
    public function testSelectText()
    {
        return $this->_testSelect(ValueType::TEXT, static::SELECT_VALUE, ["select_item" => "orange\r\nbanana\r\napple"]);
    }
    public function testSelectHtml()
    {
        return $this->_testSelect(ValueType::HTML, static::SELECT_VALUE, ["select_item" => "orange\r\nbanana\r\napple"]);
    }

    // SELECT(multiple) ----------------------------------------------------
    public const SELECT_VALUE_MULTIPLE = ['orange', 'banana'];
    public function _testSelectMultiple($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::SELECT_VALUE_MULTIPLE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testSelectMultipleValue()
    {
        return $this->_testSelectMultiple(ValueType::VALUE, static::SELECT_VALUE_MULTIPLE, ["select_item" => "orange\r\nbanana\r\napple"]);
    }
    public function testSelectMultipleText()
    {
        return $this->_testSelectMultiple(ValueType::TEXT, collect(static::SELECT_VALUE_MULTIPLE)->implode(exmtrans('common.separate_word')), ["select_item" => "orange\r\nbanana\r\napple"]);
    }
    public function testSelectMultipleHtml()
    {
        return $this->_testSelectMultiple(ValueType::HTML, collect(static::SELECT_VALUE_MULTIPLE)->implode(exmtrans('common.separate_word')), ["select_item" => "orange\r\nbanana\r\napple"]);
    }





    // SELECT_VALTEXT ----------------------------------------------------
    public const SELECT_VALTEXT_VALUE = 'orange';
    public const SELECT_VALTEXT_TEXT = 'Orange';
    public function _testSelectValText($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT_VALTEXT, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::SELECT_VALTEXT_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testSelectValTextValue()
    {
        return $this->_testSelectValText(ValueType::VALUE, static::SELECT_VALTEXT_VALUE, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }
    public function testSelectValTextText()
    {
        return $this->_testSelectValText(ValueType::TEXT, static::SELECT_VALTEXT_TEXT, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }
    public function testSelectValTextHtml()
    {
        return $this->_testSelectValText(ValueType::HTML, static::SELECT_VALTEXT_TEXT, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }

    // SELECT_VALTEXT(multiple) ----------------------------------------------------
    public const SELECT_VALTEXT_VALUE_MULTIPLE = ['orange', 'banana'];
    public const SELECT_VALTEXT_TEXT_MULTIPLE = ['Orange', 'Banana'];
    public function _testSelectValTextMultiple($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT_VALTEXT, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::SELECT_VALTEXT_VALUE_MULTIPLE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testSelectValTextMultipleValue()
    {
        return $this->_testSelectValTextMultiple(ValueType::VALUE, static::SELECT_VALTEXT_VALUE_MULTIPLE, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }
    public function testSelectValTextMultipleText()
    {
        return $this->_testSelectValTextMultiple(ValueType::TEXT, collect(static::SELECT_VALTEXT_TEXT_MULTIPLE)->implode(exmtrans('common.separate_word')), ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }
    public function testSelectValTextMultipleHtml()
    {
        return $this->_testSelectValTextMultiple(ValueType::HTML, collect(static::SELECT_VALTEXT_TEXT_MULTIPLE)->implode(exmtrans('common.separate_word')), ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }




    // SELECT_TABLE ----------------------------------------------------
    public function _testSelectTable($value_type, $matchedValue, $options = [])
    {
        $options['select_target_table'] = CustomTable::getEloquent('information')->id;

        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT_TABLE, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, 1);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testSelectTableValue()
    {
        return $this->_testSelectTable(ValueType::VALUE, CustomTable::getEloquent('information')->getValueModel(1));
    }
    public function testSelectTableText()
    {
        return $this->_testSelectTable(ValueType::TEXT, CustomTable::getEloquent('information')->getValueModel(1)->getLabel());
    }
    public function testSelectTableHtml()
    {
        return $this->_testSelectTable(ValueType::HTML, CustomTable::getEloquent('information')->getValueModel(1)->getUrl(true));
    }

    // SELECT_TABLE(multiple) ----------------------------------------------------
    public function _testSelectTableMultiple($value_type, $matchedValue, $options = [])
    {
        $options['select_target_table'] = CustomTable::getEloquent('information')->id;

        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT_TABLE, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, [1, 2]);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testSelectTableMultipleValue()
    {
        $custom_table = CustomTable::getEloquent('information');
        return $this->_testSelectTableMultiple(ValueType::VALUE, [$custom_table->getValueModel(1), $custom_table->getValueModel(2)]);
    }
    public function testSelectTableMultipleText()
    {
        $custom_table = CustomTable::getEloquent('information');
        return $this->_testSelectTableMultiple(ValueType::TEXT, collect([$custom_table->getValueModel(1)->getLabel(), $custom_table->getValueModel(2)->getLabel()])->implode(exmtrans('common.separate_word')));
    }
    public function testSelectTableMultipleHtml()
    {
        $custom_table = CustomTable::getEloquent('information');
        return $this->_testSelectTableMultiple(ValueType::HTML, collect([$custom_table->getValueModel(1)->getUrl(true), $custom_table->getValueModel(2)->getUrl(true)])->implode(exmtrans('common.separate_word')));
    }





    // USER ----------------------------------------------------
    public function _testUser($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::USER, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, 1);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testUserValue()
    {
        return $this->_testUser(ValueType::VALUE, CustomTable::getEloquent(SystemTableName::USER)->getValueModel(1));
    }
    public function testUserText()
    {
        return $this->_testUser(ValueType::TEXT, CustomTable::getEloquent(SystemTableName::USER)->getValueModel(1)->getLabel());
    }
    public function testUserHtml()
    {
        return $this->_testUser(ValueType::HTML, CustomTable::getEloquent(SystemTableName::USER)->getValueModel(1)->getUrl(true));
    }






    // ORGANIZATION ----------------------------------------------------
    public function _testOrganization($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::ORGANIZATION, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, 1);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testOrganizationValue()
    {
        return $this->_testOrganization(ValueType::VALUE, CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel(1));
    }
    public function testOrganizationText()
    {
        return $this->_testOrganization(ValueType::TEXT, CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel(1)->getLabel());
    }
    public function testOrganizationHtml()
    {
        return $this->_testOrganization(ValueType::HTML, CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel(1)->getUrl(true));
    }




    // YESNO ----------------------------------------------------
    public const YESNO_VALUE = 1;
    public function _testYesNo($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::YESNO, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::YESNO_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testYesNoValue()
    {
        return $this->_testYesNo(ValueType::VALUE, static::YESNO_VALUE);
    }
    public function testYesNoText()
    {
        return $this->_testYesNo(ValueType::TEXT, 'YES');
    }
    public function testYesNoHtml()
    {
        return $this->_testYesNo(ValueType::HTML, 'YES');
    }





    // BOOLEAN ----------------------------------------------------
    public const BOOLEAN_VALUE = 'man';
    public const BOOLEAN_TEXT = 'MAN';
    public function _testBoolean($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::BOOLEAN, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::BOOLEAN_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }
    public function testBooleanValue()
    {
        return $this->_testBoolean(ValueType::VALUE, static::BOOLEAN_VALUE, ['true_value' => 'man', 'true_label' => 'MAN', 'false_value' => 'woman', 'false_label' => 'WOMAN']);
    }
    public function testBooleanText()
    {
        return $this->_testBoolean(ValueType::TEXT, static::BOOLEAN_TEXT, ['true_value' => 'man', 'true_label' => 'MAN', 'false_value' => 'woman', 'false_label' => 'WOMAN']);
    }
    public function testBooleanHtml()
    {
        return $this->_testBoolean(ValueType::HTML, static::BOOLEAN_TEXT, ['true_value' => 'man', 'true_label' => 'MAN', 'false_value' => 'woman', 'false_label' => 'WOMAN']);
    }



    // AUTO_NUMBER ----------------------------------------------------
    // IMAGE ----------------------------------------------------
    // FILE ----------------------------------------------------
}
