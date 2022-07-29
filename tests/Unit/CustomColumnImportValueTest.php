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
    public function _testSelectValTextImportValue($checkValue, $matchedValue, bool $result, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT_VALTEXT, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::SELECT_VALTEXT_VALUE);

        $v = $column_item->getImportValue($checkValue);
        $this->checkImportValueResult($v, $matchedValue, $result);
    }
    public function testSelectValTextValue()
    {
        return $this->_testSelectValTextImportValue(static::SELECT_VALTEXT_VALUE, static::SELECT_VALTEXT_VALUE, true, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }
    public function testSelectValTextText()
    {
        return $this->_testSelectValTextImportValue(static::SELECT_VALTEXT_TEXT, static::SELECT_VALTEXT_VALUE, true, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }
    public function testSelectValTextError()
    {
        return $this->_testSelectValTextImportValue('aaaa', static::SELECT_VALTEXT_VALUE, false, ["select_item_valtext" => "orange,Orange\r\nbanana,Banana\r\napple,Apple"]);
    }





    // YESNO ----------------------------------------------------
    public function _testYesNoImportValue($checkValue, $matchedValue, bool $result, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::YESNO, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, 1);

        $v = $column_item->getImportValue($checkValue);
        $this->checkImportValueResult($v, $matchedValue, $result);
    }
    public function testYesNoValue()
    {
        return $this->_testYesNoImportValue(1, 1, true);
    }
    public function testYesNoText()
    {
        return $this->_testYesNoImportValue('YES', 1, true);
    }
    public function testYesNoError()
    {
        return $this->_testYesNoImportValue('sfjsfi', 1, false);
    }





    // BOOLEAN ----------------------------------------------------
    public const BOOLEAN_VALUE = 'man';
    public const BOOLEAN_TEXT = 'MAN';
    public function _testBooleanImportValue($checkValue, $matchedValue, bool $result, $options = [])
    {
        $custom_column = $this->getCustomColumnModel(ColumnType::BOOLEAN, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, static::BOOLEAN_VALUE);

        $v = $column_item->getImportValue($checkValue);
        $this->checkImportValueResult($v, $matchedValue, $result);
    }
    public function testBooleanValue()
    {
        return $this->_testBooleanImportValue(static::BOOLEAN_VALUE, static::BOOLEAN_VALUE, true, ['true_value' => 'man', 'true_label' => 'MAN', 'false_value' => 'woman', 'false_label' => 'WOMAN']);
    }
    public function testBooleanText()
    {
        return $this->_testBooleanImportValue(static::BOOLEAN_TEXT, static::BOOLEAN_VALUE, true, ['true_value' => 'man', 'true_label' => 'MAN', 'false_value' => 'woman', 'false_label' => 'WOMAN']);
    }
    public function testBooleanError()
    {
        return $this->_testBooleanImportValue('ehfui', static::BOOLEAN_TEXT, false, ['true_value' => 'man', 'true_label' => 'MAN', 'false_value' => 'woman', 'false_label' => 'WOMAN']);
    }



    // SELECT_TABLE ----------------------------------------------------
    public function _testSelectTablImportValue($checkValue, $matchedValue, bool $result, $options = [], $setting = [])
    {
        $options['select_target_table'] = CustomTable::getEloquent('information')->id;

        $custom_column = $this->getCustomColumnModel(ColumnType::SELECT_TABLE, $options);
        list($custom_value, $column_item) = $this->getCustomValueAndColumnItem($custom_column, 1);

        $v = $column_item->getImportValue($checkValue, $setting);
        $this->checkImportValueResult($v, $matchedValue, $result);
    }
    public function testSelectTableTargetColumn()
    {
        $title = CustomTable::getEloquent('information')->getValueModel(1)->getValue('title');
        return $this->_testSelectTablImportValue($title, 1, true, [], [
            'target_column_name' => 'title',
        ]);
    }
    public function testSelectTableTargetColumnError()
    {
        return $this->_testSelectTablImportValue('foobar', 1, false, [], [
            'target_column_name' => 'title',
        ]);
    }
    public function testSelectTableDatalist()
    {
        $title = CustomTable::getEloquent('information')->getValueModel(1)->getValue('title');

        // datalist is key, id list
        $datalist = CustomTable::getEloquent('information')->getValueModel()->all()->mapWithKeys(function ($v) {
            return [array_get($v, "value.title") => $v->id];
        });
        return $this->_testSelectTablImportValue($title, 1, true, [], [
            'target_column_name' => 'title',
            'datalist' => $datalist,
        ]);
    }
    public function testSelectTableDatalistError()
    {
        // datalist is key, id list
        $datalist = CustomTable::getEloquent('information')->getValueModel()->all()->mapWithKeys(function ($v) {
            return [array_get($v, "value.title") => $v->id];
        });
        return $this->_testSelectTablImportValue('foobar', 1, false, [], [
            'target_column_name' => 'title',
            'datalist' => $datalist,
        ]);
    }










    /**
     * Check import value result
     *
     * @param array $v
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
