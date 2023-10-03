<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Linkage;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Not belongs test
 */
class SomeTest extends UnitTestBase
{
    public function testFloatDigit()
    {
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

    public function testFloatDigitMinus()
    {
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

    public function testFloatDigitZero()
    {
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

    public function testFloatDigitZeroMinus()
    {
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
        $this->__testSearchQueryIndexed("index_text", true, "index_001_001");
    }

    /**
     * test linkages when table columns reference same parent table.
     *
     * @return void
     */
    public function testSelectTableLinkages()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE_SELECT);
        $linkages = Linkage::getSelectTableLinkages($custom_table, false);
        // check linkage count (2 parent column * 10 child column)
        $this->assertEquals(count($linkages), 20);

        $parent_column = array_get($linkages[0], 'parent_column');
        $parent_column_id = $parent_column->id;
        $target_table_id = $parent_column->getOption('select_target_table');

        // check if exists diffrent column that reference the same table
        $other_column_exists = collect($linkages)->contains(function ($linkage) use ($parent_column_id, $target_table_id) {
            $parent_column = array_get($linkage, 'parent_column');
            $select_target_table = $parent_column->getOption('select_target_table');
            return $parent_column_id != $parent_column->id && $select_target_table == $target_table_id;
        });
        $this->assertTrue($other_column_exists);
    }


    /**
     * Search value test. Call getQueryKey function, set "->".
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
        $query = $custom_table->getValueQuery();
        $query->where($custom_column->getQueryKey(), $search_value);

        // get result
        $result = $query->get();
        $this->assertTrue($result->count() > 0, "search query is 0");
        // check value match
        foreach ($result as $row) {
            /** @var CustomValue $row */
            $this->assertMatch($row->getValue($custom_column), $search_value);
        }


        // get ids, and filter id
        $ids = $result->pluck('id');
        $query = $custom_table->getValueQuery();
        $query->whereNotIn('id', $ids->toArray());
        $notResult = $query->get();
        $this->assertTrue($notResult->count() > 0, "search query is 0");

        // check value match
        foreach ($notResult as $row) {
            /** @var CustomValue $row */
            $this->assertNotMatch($row->getValue($custom_column), $search_value);
        }
    }
}
