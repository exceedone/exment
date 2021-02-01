<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Not belongs test
 */
class SomeTest extends UnitTestBase
{
    public function testFloatDigit(){
        $this->assertMatch(floorDigit(37, 0), 37);
        $this->assertMatch(floorDigit(37, 1), 37);
        $this->assertMatch(floorDigit(37, 2), 37);

        $this->assertMatch(floorDigit(37.1, 0), 37);
        $this->assertMatch(floorDigit(37.1, 1), 37.1);
        $this->assertMatch(floorDigit(37.1, 2), 37.1);

        $this->assertMatch(floorDigit(36.3, 0), 36);
        $this->assertMatch(floorDigit(36.3, 1), 36.3);
        $this->assertMatch(floorDigit(36.3, 2), 36.3);

        $this->assertMatch(floorDigit(36.8, 0), 36);
        $this->assertMatch(floorDigit(36.8, 1), 36.8);
        $this->assertMatch(floorDigit(36.8, 2), 36.8);

        $this->assertMatch(floorDigit(36.81, 0), 36);
        $this->assertMatch(floorDigit(36.81, 1), 36.8);
        $this->assertMatch(floorDigit(36.81, 2), 36.81);
        $this->assertMatch(floorDigit(36.81, 3), 36.81);

        $this->assertMatch(floorDigit(36.2, 0), 36);
        $this->assertMatch(floorDigit(36.2, 1), 36.2);
        $this->assertMatch(floorDigit(36.29, 2), 36.29);
        $this->assertMatch(floorDigit(36.29, 3), 36.29);
    }
    
    public function testFloatDigitMinus(){
        $this->assertMatch(floorDigit(-37, 0), -37);
        $this->assertMatch(floorDigit(-37, 1), -37);
        $this->assertMatch(floorDigit(-37, 2), -37);

        $this->assertMatch(floorDigit(-37.1, 0), -37);
        $this->assertMatch(floorDigit(-37.1, 1), -37.1);
        $this->assertMatch(floorDigit(-37.1, 2), -37.1);

        $this->assertMatch(floorDigit(-36.3, 0), -36);
        $this->assertMatch(floorDigit(-36.3, 1), -36.3);
        $this->assertMatch(floorDigit(-36.3, 2), -36.3);

        $this->assertMatch(floorDigit(-36.8, 0), -36);
        $this->assertMatch(floorDigit(-36.8, 1), -36.8);
        $this->assertMatch(floorDigit(-36.8, 2), -36.8);

        $this->assertMatch(floorDigit(-36.81, 0), -36);
        $this->assertMatch(floorDigit(-36.81, 1), -36.8);
        $this->assertMatch(floorDigit(-36.81, 2), -36.81);
        $this->assertMatch(floorDigit(-36.81, 3), -36.81);

        $this->assertMatch(floorDigit(-36.2, 0), -36);
        $this->assertMatch(floorDigit(-36.2, 1), -36.2);
        $this->assertMatch(floorDigit(-36.29, 2), -36.29);
        $this->assertMatch(floorDigit(-36.29, 3), -36.29);
    }
    
    public function testFloatDigitZero(){
        $this->assertMatch(floorDigit(37, 0, true), '37');
        $this->assertMatch(floorDigit(37, 1, true), '37.0');
        $this->assertMatch(floorDigit(37, 2, true), '37.00');

        $this->assertMatch(floorDigit(37.1, 0, true), '37');
        $this->assertMatch(floorDigit(37.1, 1, true), '37.1');
        $this->assertMatch(floorDigit(37.1, 2, true), '37.10');

        $this->assertMatch(floorDigit(36.3, 0, true), '36');
        $this->assertMatch(floorDigit(36.3, 1, true), '36.3');
        $this->assertMatch(floorDigit(36.3, 2, true), '36.30');

        $this->assertMatch(floorDigit(36.8, 0, true), '36');
        $this->assertMatch(floorDigit(36.8, 1, true), '36.8');
        $this->assertMatch(floorDigit(36.8, 2, true), '36.80');

        $this->assertMatch(floorDigit(36.81, 0, true), '36');
        $this->assertMatch(floorDigit(36.81, 1, true), '36.8');
        $this->assertMatch(floorDigit(36.81, 2, true), '36.81');
        $this->assertMatch(floorDigit(36.81, 3, true), '36.810');

        $this->assertMatch(floorDigit(36.2, 0, true), '36');
        $this->assertMatch(floorDigit(36.2, 1, true), '36.2');
        $this->assertMatch(floorDigit(36.29, 2, true), '36.29');
        $this->assertMatch(floorDigit(36.29, 3, true), '36.290');
    }
    
    public function testFloatDigitZeroMinus(){
        $this->assertMatch(floorDigit(-37, 0, true), '-37');
        $this->assertMatch(floorDigit(-37, 1, true), '-37.0');
        $this->assertMatch(floorDigit(-37, 2, true), '-37.00');

        $this->assertMatch(floorDigit(-37.1, 0, true), '-37');
        $this->assertMatch(floorDigit(-37.1, 1, true), '-37.1');
        $this->assertMatch(floorDigit(-37.1, 2, true), '-37.10');

        $this->assertMatch(floorDigit(-36.3, 0, true), '-36');
        $this->assertMatch(floorDigit(-36.3, 1, true), '-36.3');
        $this->assertMatch(floorDigit(-36.3, 2, true), '-36.30');

        $this->assertMatch(floorDigit(-36.8, 0, true), '-36');
        $this->assertMatch(floorDigit(-36.8, 1, true), '-36.8');
        $this->assertMatch(floorDigit(-36.8, 2, true), '-36.80');

        $this->assertMatch(floorDigit(-36.81, 0, true), '-36');
        $this->assertMatch(floorDigit(-36.81, 1, true), '-36.8');
        $this->assertMatch(floorDigit(-36.81, 2, true), '-36.81');
        $this->assertMatch(floorDigit(-36.81, 3, true), '-36.810');

        $this->assertMatch(floorDigit(-36.2, 0, true), '-36');
        $this->assertMatch(floorDigit(-36.2, 1, true), '-36.2');
        $this->assertMatch(floorDigit(-36.29, 2, true), '-36.29');
        $this->assertMatch(floorDigit(-36.29, 3, true), '-36.290');
    }


    /**
     * Execute query custom column's search
     *
     * @return void
     */
    public function testSearchQueryNotIndexed()
    {
        $this->__testSearchQueryIndexed("text", false, "test_1");
    }


    /**
     * Execute query custom column's search
     *
     * @return void
     */
    public function testSearchQueryIndexed()
    {
        $this->__testSearchQueryIndexed("index_text", true, "index_1_1");
    }


    /**
     * Search value test. Call getQueryKey function, set "->>".
     *
     * @param string $column_name
     * @param boolean $index_enabled
     * @return void
     */
    public function __testSearchQueryIndexed(string $column_name, bool $index_enabled, string $search_value)
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $custom_column = CustomColumn::getEloquent($column_name, $custom_table);

        // check index enabled
        $this->assertTrue($custom_column->index_enabled == $index_enabled, "expects enabled is $index_enabled, but result is {$custom_column->index_enabled}.");

        // get search query
        $query = $custom_table->getValueModel()->query();
        $query->where($custom_column->getQueryKey(), $search_value);

        // get result
        $result = $query->get();
        $this->assertTrue($result->count() > 0, "search query is 0");
        // check value match
        foreach($result as $row){
            $this->assertMatch($row->getValue($custom_column), $search_value);
        }


        // get ids, and filter id
        $ids = $result->pluck('id');
        $query = $custom_table->getValueModel()->query();
        $query->whereNotIn('id', $ids->toArray());
        $notResult = $query->get();
        $this->assertTrue($notResult->count() > 0, "search query is 0");

        // check value match
        foreach($notResult as $row){
            $this->assertNotMatch($row->getValue($custom_column), $search_value);
        }
    }
}
