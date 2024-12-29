<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\ColumnType;

/**
 * Import value(custom column)
 */
class CustomColumnImportValueTest extends UnitTestBase
{
    use CustomColumnTrait;

    // SELECT_VALTEXT ----------------------------------------------------
    public const SELECT_VALTEXT_VALUE = 'orange';
    public const SELECT_VALTEXT_TEXT = 'Orange';

    /**
     * @param mixed $checkValue
     * @param mixed $matchedValue
     * @param bool $result
     * @param array<mixed> $options
     * @return void
     */
    public function _testSelectValTextImportValue($checkValue, $matchedValue, bool $result, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT_VALTEXT, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::SELECT_VALTEXT_VALUE);

        $v = $column_item->getImportValue($checkValue);
        $this->checkImportValueResult($v, $matchedValue, $result);
    }

    /**
     * @return void
     */
    public function testSelectValTextValue()
    {
        $this->_testSelectValTextImportValue(static::SELECT_VALTEXT_VALUE, static::SELECT_VALTEXT_VALUE, true, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }

    /**
     * @return void
     */
    public function testSelectValTextText()
    {
        $this->_testSelectValTextImportValue(static::SELECT_VALTEXT_TEXT, static::SELECT_VALTEXT_VALUE, true, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }

    /**
     * @return void
     */
    public function testSelectValTextError()
    {
        $this->_testSelectValTextImportValue('aaaa', static::SELECT_VALTEXT_VALUE, false, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }





    // YESNO ----------------------------------------------------

    /**
     * @param mixed $checkValue
     * @param mixed $matchedValue
     * @param bool $result
     * @param array<mixed> $options
     * @return void
     */
    public function _testYesNoImportValue($checkValue, $matchedValue, bool $result, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::YESNO, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, 1);

        $v = $column_item->getImportValue($checkValue);
        $this->checkImportValueResult($v, $matchedValue, $result);
    }

    /**
     * @return void
     */
    public function testYesNoValue()
    {
        $this->_testYesNoImportValue(1, 1, true);
    }

    /**
     * @return void
     */
    public function testYesNoText()
    {
        $this->_testYesNoImportValue('YES', 1, true);
    }

    /**
     * @return void
     */
    public function testYesNoError()
    {
        $this->_testYesNoImportValue('sfjsfi', 1, false);
    }





    // BOOLEAN ----------------------------------------------------
    public const BOOLEAN_VALUE = 'man';
    public const BOOLEAN_TEXT = 'MAN';
    /**
     * @param mixed $checkValue
     * @param mixed $matchedValue
     * @param bool $result
     * @param array<mixed> $options
     * @return void
     */
    public function _testBooleanImportValue($checkValue, $matchedValue, bool $result, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::BOOLEAN, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::BOOLEAN_VALUE);

        $v = $column_item->getImportValue($checkValue);
        $this->checkImportValueResult($v, $matchedValue, $result);
    }

    /**
     * @return void
     */
    public function testBooleanValue()
    {
        $this->_testBooleanImportValue(static::BOOLEAN_VALUE, static::BOOLEAN_VALUE, true, ['true_value' => 'man', 'true_label' => 'MAN', 'false_value' => 'woman', 'false_label' => 'WOMAN']);
    }

    /**
     * @return void
     */
    public function testBooleanText()
    {
        $this->_testBooleanImportValue(static::BOOLEAN_TEXT, static::BOOLEAN_VALUE, true, ['true_value' => 'man', 'true_label' => 'MAN', 'false_value' => 'woman', 'false_label' => 'WOMAN']);
    }

    /**
     * @return void
     */
    public function testBooleanError()
    {
        $this->_testBooleanImportValue('ehfui', static::BOOLEAN_TEXT, false, ['true_value' => 'man', 'true_label' => 'MAN', 'false_value' => 'woman', 'false_label' => 'WOMAN']);
    }



    // SELECT_TABLE ----------------------------------------------------

    /**
     * @param mixed $checkValue
     * @param mixed $matchedValue
     * @param bool $result
     * @param array<mixed> $options
     * @param array<mixed> $setting
     * @return void
     */
    public function _testSelectTablImportValue($checkValue, $matchedValue, bool $result, $options = [], $setting = [])
    {
        $options['select_target_table'] = CustomTable::getEloquent('information')->id;

        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT_TABLE, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, 1);

        $v = $column_item->getImportValue($checkValue, $setting);
        $this->checkImportValueResult($v, $matchedValue, $result);
    }

    /**
     * @return void
     */
    public function testSelectTableTargetColumn()
    {
        $title = CustomTable::getEloquent('information')->getValueModel(1)->getValue('title');
        $this->_testSelectTablImportValue($title, 1, true, [], [
            'target_column_name' => 'title',
        ]);
    }

    /**
     * @return void
     */
    public function testSelectTableTargetColumnError()
    {
        $this->_testSelectTablImportValue('foobar', 1, false, [], [
            'target_column_name' => 'title',
        ]);
    }
    /**
     * @return void
     */
    public function testSelectTableDatalist()
    {
        $title = CustomTable::getEloquent('information')->getValueModel(1)->getValue('title');

        // datalist is key, id list
        $datalist = CustomTable::getEloquent('information')->getValueModel()->all()->mapWithKeys(function ($v) {
            return [array_get($v, "value.title") => $v->id];
        });
        $this->_testSelectTablImportValue($title, 1, true, [], [
            'target_column_name' => 'title',
            'datalist' => $datalist,
        ]);
    }

    /**
     * @return void
     */
    public function testSelectTableDatalistError()
    {
        // datalist is key, id list
        $datalist = CustomTable::getEloquent('information')->getValueModel()->all()->mapWithKeys(function ($v) {
            return [array_get($v, "value.title") => $v->id];
        });
        $this->_testSelectTablImportValue('foobar', 1, false, [], [
            'target_column_name' => 'title',
            'datalist' => $datalist,
        ]);
    }

    /**
     * Check import value result
     *
     * @param array<mixed>  $v
     * @param mixed $matchedValue
     * @param boolean $result
     * @return void
     */
    protected function checkImportValueResult(array $v, $matchedValue, bool $result)
    {
        $this->assertTrue(array_get($v, 'result') === $result);

        if ($result) {
            $this->assertMatch(array_get($v, 'value'), $matchedValue);
        }
    }
}
