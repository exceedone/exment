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
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS;
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
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS;
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
