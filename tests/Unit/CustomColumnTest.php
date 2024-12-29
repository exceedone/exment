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

    /**
     * @param mixed $value_type
     * @return void
     */
    public function _testText($value_type)
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::TEXT);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, 'text');

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, 'text');
    }

    /**
     * @return void
     */
    public function testTextValue()
    {
        $this->_testText(ValueType::VALUE);
    }
    /**
     * @return void
     */
    public function testTextText()
    {
        $this->_testText(ValueType::TEXT);
    }
    /**
     * @return void
     */
    public function testTextHtml()
    {
        $this->_testText(ValueType::HTML);
    }


    // TextArea ----------------------------------------------------
    public const TEXTAREA_VALUE = 'text1\r\ntext2\r\ntext3\r\n<a href="abc">aaa</a>';

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @return void
     */
    public function _testTextarea($value_type, $matchedValue)
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::TEXTAREA);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::TEXTAREA_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }


    /**
     * @return void
     */
    public function testTextareaValue()
    {
        $this->_testTextarea(ValueType::VALUE, static::TEXTAREA_VALUE);
    }

    /**
     * @return void
     */
    public function testTextareaText()
    {
        $this->_testTextarea(ValueType::TEXT, static::TEXTAREA_VALUE);
    }

    /**
     * @return void
     */
    public function testTextareaHtml()
    {
        $this->_testTextarea(ValueType::HTML, preg_replace('/ /', '<span style="margin-right: 0.5em;"></span>', replaceBreakEsc(static::TEXTAREA_VALUE)));
    }




    // Editor ----------------------------------------------------
    public const EDITOR_VALUE = "<p>normal</p>\r\n<p><strong>bold</strong></p>\r\n<p><span style=\"text-decoration: underline;\">under</span></p>\r\n<p><span style=\"color: #ff0000;\">red</span></p>";

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @return void
     */
    public function _testEditor($value_type, $matchedValue)
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::EDITOR);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::EDITOR_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testEditorValue()
    {
        $this->_testEditor(ValueType::VALUE, static::EDITOR_VALUE);
    }

    /**
     * @return void
     */
    public function testEditorText()
    {
        $this->_testEditor(ValueType::TEXT, static::EDITOR_VALUE);
    }

    /**
     * @return void
     */
    public function testEditorHtml()
    {
        $this->_testEditor(ValueType::HTML, '<div class="show-tinymce">'.replaceBreak(html_clean(static::EDITOR_VALUE), false).'</div>');
    }





    // URL ----------------------------------------------------
    public const URL_VALUE = "https://github.com/exceedone/exment/";

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @return void
     */
    public function _testUrl($value_type, $matchedValue)
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::URL);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::URL_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testUrlValue()
    {
        $this->_testUrl(ValueType::VALUE, static::URL_VALUE);
    }

    /**
     * @return void
     */
    public function testUrlText()
    {
        $this->_testUrl(ValueType::TEXT, static::URL_VALUE);
    }

    /**
     * @return void
     */
    public function testUrlHtml()
    {
        $this->_testUrl(ValueType::HTML, '<a href="' . static::URL_VALUE . '" target="_blank">' . static::URL_VALUE . "</a>");
    }





    // EMAIL ----------------------------------------------------
    public const EMAIL_VALUE = "test@foobar.test";

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @return void
     */
    public function _testEmail($value_type, $matchedValue)
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::EMAIL);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::EMAIL_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testEmailValue()
    {
        $this->_testEmail(ValueType::VALUE, static::EMAIL_VALUE);
    }

    /**
     * @return void
     */
    public function testEmailText()
    {
        $this->_testEmail(ValueType::TEXT, static::EMAIL_VALUE);
    }

    /**
     * @return void
     */
    public function testEmailHtml()
    {
        $this->_testEmail(ValueType::HTML, static::EMAIL_VALUE);
    }




    // INTEGER ----------------------------------------------------
    public const INTEGER_VALUE = 1000;

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed>$options
     * @return void
     */
    public function _testInteger($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::INTEGER, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::INTEGER_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testIntegerValue()
    {
        $this->_testInteger(ValueType::VALUE, static::INTEGER_VALUE);
    }

    /**
     * @return void
     */
    public function testIntegerText()
    {
        $this->_testInteger(ValueType::TEXT, static::INTEGER_VALUE);
    }

    /**
     * @return void
     */
    public function testIntegerHtml()
    {
        $this->_testInteger(ValueType::HTML, static::INTEGER_VALUE);
    }
    //comma

    /**
     * @return void
     */
    public function testIntegerValueComma()
    {
        $this->_testInteger(ValueType::VALUE, '1000', ['number_format' => 1]);
    }

    /**
     * @return void
     */
    public function testIntegerTextComma()
    {
        $this->_testInteger(ValueType::TEXT, '1,000', ['number_format' => 1]);
    }

    /**
     * @return void
     */
    public function testIntegerHtmlComma()
    {
        $this->_testInteger(ValueType::HTML, '1,000', ['number_format' => 1]);
    }




    // DECIMAL ----------------------------------------------------
    public const DECIMAL_VALUE = 1000.25;
    public const DECIMAL_VALUE2 = 1000;
    public const DECIMAL_VALUE3 = 1000.2;

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @param mixed|null $originalValue
     * @return void
     */
    public function _testDecimal($value_type, $matchedValue, $options = [], $originalValue = null)
    {
        $originalValue = $originalValue?? static::CURRENCY_VALUE;
        $custom_column = $this->getCustomColumnModel(ColumnType::DECIMAL, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, $originalValue);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testDecimalValue()
    {
        $this->_testDecimal(ValueType::VALUE, static::DECIMAL_VALUE);
    }

    /**
     * @return void
     */
    public function testDecimalText()
    {
        $this->_testDecimal(ValueType::TEXT, static::DECIMAL_VALUE);
    }

    /**
     * @return void
     */
    public function testDecimalHtml()
    {
        $this->_testDecimal(ValueType::HTML, static::DECIMAL_VALUE);
    }
    //comma(only test, html)

    /**
     * @return void
     */
    public function testDecimalValueComma()
    {
        $this->_testDecimal(ValueType::VALUE, '1000.25', ['number_format' => 1, 'decimal_digit' => 2]);
    }

    /**
     * @return void
     */
    public function testDecimalTextComma()
    {
        $this->_testDecimal(ValueType::TEXT, '1,000.25', ['number_format' => 1, 'decimal_digit' => 2]);
    }

    /**
     * @return void
     */
    public function testDecimalHtmlComma()
    {
        $this->_testDecimal(ValueType::HTML, '1,000.25', ['number_format' => 1, 'decimal_digit' => 2]);
    }
    //percent(only html)

    /**
     * @return void
     */
    public function testDecimalValuePercent()
    {
        $this->_testDecimal(ValueType::VALUE, '1000.25', ['percent_format' => 1, 'decimal_digit' => 2]);
    }

    /**
     * @return void
     */
    public function testDecimalTextPercent()
    {
        $this->_testDecimal(ValueType::TEXT, '1000.25', ['percent_format' => 1, 'decimal_digit' => 2]);
    }

    /**
     * @return void
     */
    public function testDecimalHtmlPercent()
    {
        $this->_testDecimal(ValueType::HTML, '100025%', ['percent_format' => 1, 'decimal_digit' => 2]);
    }

    /**
     * @return void
     */
    public function testDecimalText2()
    {
        $this->_testDecimal(ValueType::TEXT, '1000', ['decimal_digit' => 2], static::DECIMAL_VALUE2);
    }

    /**
     * @return void
     */
    public function testDecimalTextComma2()
    {
        $this->_testDecimal(ValueType::TEXT, '1,000', ['number_format' => 1, 'decimal_digit' => 2], static::DECIMAL_VALUE2);
    }

    /**
     * @return void
     */
    public function testDecimalText3()
    {
        $this->_testDecimal(ValueType::TEXT, '1000.2', ['decimal_digit' => 2], static::DECIMAL_VALUE3);
    }

    /**
     * @return void
     */
    public function testDecimalTextComma3()
    {
        $this->_testDecimal(ValueType::TEXT, '1,000.2', ['number_format' => 1, 'decimal_digit' => 2], static::DECIMAL_VALUE3);
    }




    // CURRENCY ----------------------------------------------------
    public const CURRENCY_VALUE = 1000.25;
    public const CURRENCY_VALUE2 = 1000;
    public const CURRENCY_VALUE3 = 1000.2;

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @param null|mixed $originalValue
     * @return void
     */
    public function _testCurrency($value_type, $matchedValue, $options = [], $originalValue = null)
    {
        $originalValue = $originalValue?? static::CURRENCY_VALUE;
        $custom_column = $this->getCustomColumnModel(ColumnType::CURRENCY, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, $originalValue);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testCurrencyValue()
    {
        $this->_testCurrency(ValueType::VALUE, static::CURRENCY_VALUE, ['currency_symbol' => CurrencySymbol::JPY1]);
    }

    /**
     * @return void
     */
    public function testCurrencyText()
    {
        $this->_testCurrency(ValueType::TEXT, '¥' . static::CURRENCY_VALUE, ['currency_symbol' => CurrencySymbol::JPY1]);
    }

    /**
     * @return void
     */
    public function testCurrencyHtml()
    {
        $this->_testCurrency(ValueType::HTML, '&yen;' . static::CURRENCY_VALUE, ['currency_symbol' => CurrencySymbol::JPY1]);
    }
    //comma(only test, html)

    /**
     * @return void
     */
    public function testCurrencyValueComma()
    {
        $this->_testCurrency(ValueType::VALUE, '1000.25', ['currency_symbol' => CurrencySymbol::JPY1, 'number_format' => 1, 'decimal_digit' => 2]);
    }

    /**
     * @return void
     */
    public function testCurrencyTextComma()
    {
        $this->_testCurrency(ValueType::TEXT, '¥1,000.25', ['currency_symbol' => CurrencySymbol::JPY1, 'number_format' => 1, 'decimal_digit' => 2]);
    }

    /**
     * @return void
     */
    public function testCurrencyHtmlComma()
    {
        $this->_testCurrency(ValueType::HTML, '&yen;1,000.25', ['currency_symbol' => CurrencySymbol::JPY1, 'number_format' => 1, 'decimal_digit' => 2]);
    }

    /**
     * @return void
     */
    public function testCurrencyText2()
    {
        $this->_testCurrency(
            ValueType::TEXT,
            '1000.00円',
            ['currency_symbol' => CurrencySymbol::JPY2,
             'decimal_digit' => 2],
            static::CURRENCY_VALUE2
        );
    }

    /**
     * @return void
     */
    public function testCurrencyTextComma2()
    {
        $this->_testCurrency(
            ValueType::TEXT,
            '1,000.00円',
            ['currency_symbol' => CurrencySymbol::JPY2,
             'number_format' => 1,
             'decimal_digit' => 2],
            static::CURRENCY_VALUE2
        );
    }

    /**
     * @return void
     */
    public function testCurrencyText3()
    {
        $this->_testCurrency(
            ValueType::TEXT,
            '$1000.20',
            ['currency_symbol' => CurrencySymbol::USD,
             'decimal_digit' => 2],
            static::CURRENCY_VALUE3
        );
    }

    /**
     * @return void
     */
    public function testCurrencyTextComma3()
    {
        $this->_testCurrency(
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

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @return void
     */
    public function _testDate($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::DATE, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::DATE_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testDateValue()
    {
        $this->_testDate(ValueType::VALUE, static::DATE_VALUE);
    }

    /**
     * @return void
     */
    public function testDateText()
    {
        $this->_testDate(ValueType::TEXT, static::DATE_VALUE);
    }

    /**
     * @return void
     */
    public function testDateHtml()
    {
        $this->_testDate(ValueType::HTML, static::DATE_VALUE);
    }
    // format(only text and html)

    /**
     * @return void
     */
    public function testDateValueFormat()
    {
        $this->_testDate(ValueType::VALUE, static::DATE_VALUE, ['format' => 'Y-m-d']);
    }

    /**
     * @return void
     */
    public function testDateTextFormat()
    {
        $this->_testDate(ValueType::TEXT, static::DATE_VALUE_FORMAT, ['format' => 'Y-m-d']);
    }

    /**
     * @return void
     */
    public function testDateHtmlFormat()
    {
        $this->_testDate(ValueType::HTML, static::DATE_VALUE_FORMAT, ['format' => 'Y-m-d']);
    }







    // TIME ----------------------------------------------------
    public const TIME_VALUE = '20:10:00';
    public const TIME_VALUE_FORMAT = '201000';

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @return void
     */
    public function _testTime($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::TIME, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::TIME_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testTimeValue()
    {
        $this->_testTime(ValueType::VALUE, static::TIME_VALUE);
    }

    /**
     * @return void
     */
    public function testTimeText()
    {
        $this->_testTime(ValueType::TEXT, static::TIME_VALUE);
    }

    /**
     * @return void
     */
    public function testTimeHtml()
    {
        $this->_testTime(ValueType::HTML, static::TIME_VALUE);
    }
    // format(only text and html)

    /**
     * @return void
     */
    public function testTimeValueFormat()
    {
        $this->_testTime(ValueType::VALUE, static::TIME_VALUE, ['format' => 'His']);
    }

    /**
     * @return void
     */
    public function testTimeTextFormat()
    {
        $this->_testTime(ValueType::TEXT, static::TIME_VALUE_FORMAT, ['format' => 'His']);
    }

    /**
     * @return void
     */
    public function testTimeHtmlFormat()
    {
        $this->_testTime(ValueType::HTML, static::TIME_VALUE_FORMAT, ['format' => 'His']);
    }







    // DATETIME ----------------------------------------------------
    public const DATETIME_VALUE = '2020/06/12 20:10:00';
    public const DATETIME_VALUE_FORMAT = '2020-06-12 201000';

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @return void
     */
    public function _testDateTime($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::DATETIME, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::DATETIME_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testDateTimeValue()
    {
        $this->_testDateTime(ValueType::VALUE, static::DATETIME_VALUE);
    }

    /**
     * @return void
     */
    public function testDateTimeText()
    {
        $this->_testDateTime(ValueType::TEXT, static::DATETIME_VALUE);
    }

    /**
     * @return void
     */
    public function testDateTimeHtml()
    {
        $this->_testDateTime(ValueType::HTML, static::DATETIME_VALUE);
    }
    // format(only text and html)

    /**
     * @return void
     */
    public function testDateTimeValueFormat()
    {
        $this->_testDateTime(ValueType::VALUE, static::DATETIME_VALUE, ['format' => 'Y-m-d His']);
    }

    /**
     * @return void
     */
    public function testDateTimeTextFormat()
    {
        $this->_testDateTime(ValueType::TEXT, static::DATETIME_VALUE_FORMAT, ['format' => 'Y-m-d His']);
    }

    /**
     * @return void
     */
    public function testDateTimeHtmlFormat()
    {
        $this->_testDateTime(ValueType::HTML, static::DATETIME_VALUE_FORMAT, ['format' => 'Y-m-d His']);
    }





    // SELECT ----------------------------------------------------
    public const SELECT_VALUE = 'orange';

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @return void
     */
    public function _testSelect($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::SELECT_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testSelectValue()
    {
        $this->_testSelect(ValueType::VALUE, static::SELECT_VALUE, ["select_item" => "orange\r\nbanana\r\napple"]);
    }
    /**
     * @return void
     */
    public function testSelectText()
    {
        $this->_testSelect(ValueType::TEXT, static::SELECT_VALUE, ["select_item" => "orange\r\nbanana\r\napple"]);
    }

    /**
     * @return void
     */
    public function testSelectHtml()
    {
        $this->_testSelect(ValueType::HTML, static::SELECT_VALUE, ["select_item" => "orange\r\nbanana\r\napple"]);
    }

    // SELECT(multiple) ----------------------------------------------------
    public const SELECT_VALUE_MULTIPLE = ['orange', 'banana'];
    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @return void
     */
    public function _testSelectMultiple($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::SELECT_VALUE_MULTIPLE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testSelectMultipleValue()
    {
        $this->_testSelectMultiple(ValueType::VALUE, static::SELECT_VALUE_MULTIPLE, ["select_item" => "orange\r\nbanana\r\napple"]);
    }

    /**
     * @return void
     */
    public function testSelectMultipleText()
    {
        $this->_testSelectMultiple(ValueType::TEXT, collect(static::SELECT_VALUE_MULTIPLE)->implode(exmtrans('common.separate_word')), ["select_item" => "orange\r\nbanana\r\napple"]);
    }

    /**
     * @return void
     */
    public function testSelectMultipleHtml()
    {
        $this->_testSelectMultiple(ValueType::HTML, collect(static::SELECT_VALUE_MULTIPLE)->implode(exmtrans('common.separate_word')), ["select_item" => "orange\r\nbanana\r\napple"]);
    }





    // SELECT_VALTEXT ----------------------------------------------------
    public const SELECT_VALTEXT_VALUE = 'orange';
    public const SELECT_VALTEXT_TEXT = 'Orange';

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @return void
     */
    public function _testSelectValText($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT_VALTEXT, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::SELECT_VALTEXT_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testSelectValTextValue()
    {
        $this->_testSelectValText(ValueType::VALUE, static::SELECT_VALTEXT_VALUE, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }

    /**
     * @return void
     */
    public function testSelectValTextText()
    {
        $this->_testSelectValText(ValueType::TEXT, static::SELECT_VALTEXT_TEXT, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }

    /**
     * @return void
     */
    public function testSelectValTextHtml()
    {
        $this->_testSelectValText(ValueType::HTML, static::SELECT_VALTEXT_TEXT, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }

    // SELECT_VALTEXT(multiple) ----------------------------------------------------
    public const SELECT_VALTEXT_VALUE_MULTIPLE = ['orange', 'banana'];
    public const SELECT_VALTEXT_TEXT_MULTIPLE = ['Orange', 'Banana'];

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @return void
     */
    public function _testSelectValTextMultiple($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT_VALTEXT, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::SELECT_VALTEXT_VALUE_MULTIPLE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testSelectValTextMultipleValue()
    {
        $this->_testSelectValTextMultiple(ValueType::VALUE, static::SELECT_VALTEXT_VALUE_MULTIPLE, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }

    /**
     * @return void
     */
    public function testSelectValTextMultipleText()
    {
        $this->_testSelectValTextMultiple(ValueType::TEXT, collect(static::SELECT_VALTEXT_TEXT_MULTIPLE)->implode(exmtrans('common.separate_word')), ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }

    /**
     * @return void
     */
    public function testSelectValTextMultipleHtml()
    {
        $this->_testSelectValTextMultiple(ValueType::HTML, collect(static::SELECT_VALTEXT_TEXT_MULTIPLE)->implode(exmtrans('common.separate_word')), ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }




    // SELECT_TABLE ----------------------------------------------------

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @return void
     */
    public function _testSelectTable($value_type, $matchedValue, $options = [])
    {
        $options['select_target_table'] = CustomTable::getEloquent('information')->id;

        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT_TABLE, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, 1);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testSelectTableValue()
    {
        $this->_testSelectTable(ValueType::VALUE, CustomTable::getEloquent('information')->getValueModel(1));
    }

    /**
     * @return void
     */
    public function testSelectTableText()
    {
        $this->_testSelectTable(ValueType::TEXT, CustomTable::getEloquent('information')->getValueModel(1)->getLabel());
    }

    /**
     * @return void
     */
    public function testSelectTableHtml()
    {
        $this->_testSelectTable(ValueType::HTML, CustomTable::getEloquent('information')->getValueModel(1)->getUrl(true));
    }

    // SELECT_TABLE(multiple) ----------------------------------------------------

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @return void
     */
    public function _testSelectTableMultiple($value_type, $matchedValue, $options = [])
    {
        $options['select_target_table'] = CustomTable::getEloquent('information')->id;

        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT_TABLE, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, [1, 2]);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testSelectTableMultipleValue()
    {
        $custom_table = CustomTable::getEloquent('information');
        $this->_testSelectTableMultiple(ValueType::VALUE, [$custom_table->getValueModel(1), $custom_table->getValueModel(2)]);
    }

    /**
     * @return void
     */
    public function testSelectTableMultipleText()
    {
        $custom_table = CustomTable::getEloquent('information');
        $this->_testSelectTableMultiple(ValueType::TEXT, collect([$custom_table->getValueModel(1)->getLabel(), $custom_table->getValueModel(2)->getLabel()])->implode(exmtrans('common.separate_word')));
    }

    /**
     * @return void
     */
    public function testSelectTableMultipleHtml()
    {
        $custom_table = CustomTable::getEloquent('information');
        $this->_testSelectTableMultiple(ValueType::HTML, collect([$custom_table->getValueModel(1)->getUrl(true), $custom_table->getValueModel(2)->getUrl(true)])->implode(exmtrans('common.separate_word')));
    }





    // USER ----------------------------------------------------

    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @return void
     */
    public function _testUser($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::USER, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, 1);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testUserValue()
    {
        $this->_testUser(ValueType::VALUE, CustomTable::getEloquent(SystemTableName::USER)->getValueModel(1));
    }

    /**
     * @return void
     */
    public function testUserText()
    {
        $this->_testUser(ValueType::TEXT, CustomTable::getEloquent(SystemTableName::USER)->getValueModel(1)->getLabel());
    }

    /**
     * @return void
     */
    public function testUserHtml()
    {
        $this->_testUser(ValueType::HTML, CustomTable::getEloquent(SystemTableName::USER)->getValueModel(1)->getUrl(true));
    }






    // ORGANIZATION ----------------------------------------------------
    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @return void
     */
    public function _testOrganization($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::ORGANIZATION, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, 1);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testOrganizationValue()
    {
        $this->_testOrganization(ValueType::VALUE, CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel(1));
    }
    /**
     * @return void
     */
    public function testOrganizationText()
    {
        $this->_testOrganization(ValueType::TEXT, CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel(1)->getLabel());
    }
    /**
     * @return void
     */
    public function testOrganizationHtml()
    {
        $this->_testOrganization(ValueType::HTML, CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel(1)->getUrl(true));
    }




    // YESNO ----------------------------------------------------
    public const YESNO_VALUE = 1;
    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @return void
     */
    public function _testYesNo($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::YESNO, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::YESNO_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testYesNoValue()
    {
        $this->_testYesNo(ValueType::VALUE, static::YESNO_VALUE);
    }

    /**
     * @return void
     */
    public function testYesNoText()
    {
        $this->_testYesNo(ValueType::TEXT, 'YES');
    }

    /**
     * @return void
     */
    public function testYesNoHtml()
    {
        $this->_testYesNo(ValueType::HTML, 'YES');
    }





    // BOOLEAN ----------------------------------------------------
    public const BOOLEAN_VALUE = 'man';
    public const BOOLEAN_TEXT = 'MAN';
    /**
     * @param mixed $value_type
     * @param mixed $matchedValue
     * @param array<mixed> $options
     * @return void
     */
    public function _testBoolean($value_type, $matchedValue, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::BOOLEAN, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::BOOLEAN_VALUE);

        $v = $column_item->{$value_type}();
        $this->assertMatch($v, $matchedValue);
    }

    /**
     * @return void
     */
    public function testBooleanValue()
    {
        $this->_testBoolean(ValueType::VALUE, static::BOOLEAN_VALUE, ['true_value' => 'man', 'true_label' => 'MAN', 'false_value' => 'woman', 'false_label' => 'WOMAN']);
    }

    /**
     * @return void
     */
    public function testBooleanText()
    {
        $this->_testBoolean(ValueType::TEXT, static::BOOLEAN_TEXT, ['true_value' => 'man', 'true_label' => 'MAN', 'false_value' => 'woman', 'false_label' => 'WOMAN']);
    }

    /**
     * @return void
     */
    public function testBooleanHtml()
    {
        $this->_testBoolean(ValueType::HTML, static::BOOLEAN_TEXT, ['true_value' => 'man', 'true_label' => 'MAN', 'false_value' => 'woman', 'false_label' => 'WOMAN']);
    }



    // AUTO_NUMBER ----------------------------------------------------
    // IMAGE ----------------------------------------------------
    // FILE ----------------------------------------------------
}
