<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Services\Search\SearchService;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Tests\TestDefine;

class SearchServiceTest extends UnitTestBase
{
    // execute search service test
    public function testSearchDefault()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $service = new SearchService($custom_table);

        $service->where('index_text', 'index_001_001');

        $values = $service->get();
        $this->assertTrue($values->count() > 0);
        $values->each(function (CustomValue $value) {
            $this->assertMatch($value->getValue('index_text'), 'index_001_001');
        });
    }

    // execute search service test
    public function testSearchDefaultMultiWhere()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $service = new SearchService($custom_table);

        $service->where('text', 'test_1')
            ->where('odd_even', 'odd');

        $values = $service->get();
        $this->assertTrue($values->count() > 0);
        $values->each(function (CustomValue $value) {
            $this->assertMatch($value->getValue('text'), 'test_1');
            $this->assertMatch($value->getValue('odd_even'), 'odd');
        });
    }

    public function testSearchRelationOneMany()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $service = new SearchService($custom_table);

        // get parent custom column
        $parent_custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $parent_custom_column = CustomColumn::getEloquent('index_text', $parent_custom_table);
        $service->where($parent_custom_column, 'index_003_001');

        $values = $service->get();
        $this->assertTrue($values->count() > 0);
        $values->each(function (CustomValue $value) {
            // get parent value
            $parent_value = $value->getParentValue();
            $this->assertMatch($parent_value->getValue('index_text'), 'index_003_001');
        });
    }


    public function testSearchRelationOneManyMultiWhere()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $service = new SearchService($custom_table);

        // get parent custom column
        $parent_custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $parent_custom_column = CustomColumn::getEloquent('integer', $parent_custom_table);
        $service->where($parent_custom_column, '>', 1000)
            ->where('odd_even', 'odd');

        $values = $service->get();
        $this->assertTrue($values->count() > 0);
        $values->each(function (CustomValue $value) {
            // get parent value
            $parent_value = $value->getParentValue();
            $this->assertTrue($parent_value->getValue('integer') > 1000);
            $this->assertMatch($value->getValue('odd_even'), 'odd');
        });
    }

    public function testSearchRelationManyMany()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE_MANY_TO_MANY);
        $service = new SearchService($custom_table);

        // get parent custom column
        $parent_custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_MANY_TO_MANY);
        $parent_custom_column = CustomColumn::getEloquent('index_text', $parent_custom_table);
        $relation = CustomRelation::getRelationByParentChild($parent_custom_table, $custom_table);
        $service->where($parent_custom_column, 'index_003_001');

        $values = $service->get();
        $this->assertTrue($values->count() > 0);
        $values->each(function (CustomValue $value) use ($relation) {
            // get parent values(this list contains not filter target value)
            $parent_values = $value->getParentValue($relation);
            // Whether checking contains parent value
            $this->assertTrue($parent_values->contains(function ($parent_value) {
                return isMatchString($parent_value->getValue('index_text'), 'index_003_001');
            }));
        });
    }


    public function testSearchSelectTable()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE_SELECT);
        $service = new SearchService($custom_table);

        // get parent custom column
        $parent_custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_SELECT);
        $parent_custom_column = CustomColumn::getEloquent('index_text', $parent_custom_table);
        $service->where($parent_custom_column, 'index_003_001');

        $values = $service->get();
        $this->assertTrue($values->count() > 0);
        $values->each(function (CustomValue $value) {
            // get parent value
            $parent_value = $value->getValue('parent_select_table');
            $this->assertMatch($parent_value->getValue('index_text'), 'index_003_001');
        });
    }



    // Order ----------------------------------------------------

    public function testOrderDefault()
    {
        $this->_testOrderDefault('index_text');
    }

    public function testOrderDefaultDesc()
    {
        $this->_testOrderDefault('index_text', 'desc');
    }

    public function testOrderDefaultCustomColumn()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $this->_testOrderDefault(CustomColumn::getEloquent('index_text', $custom_table));
    }

    public function _testOrderDefault($column, $direction = 'asc')
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $service = new SearchService($custom_table);

        $service->orderBy($column, $direction);

        $values = $service->get();
        $this->assertTrue($values->count() > 0);

        $checkValue = null;
        $values->each(function (CustomValue $value) use (&$checkValue, $direction) {
            $this->assertTrue(is_null($checkValue) || ($direction == 'asc' ? $value->getValue('index_text') >= $checkValue : $value->getValue('index_text') <= $checkValue));
            $checkValue = $value->getValue('index_text');
        });
    }



    public function testOrderOneMany()
    {
        $parent_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $this->_testOrderOneMany(CustomColumn::getEloquent('index_text', $parent_table));
    }

    public function testOrderOneManyDesc()
    {
        $parent_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $this->_testOrderOneMany(CustomColumn::getEloquent('index_text', $parent_table), 'desc');
    }


    public function _testOrderOneMany($column, $direction = 'asc')
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $service = new SearchService($custom_table);

        $service->orderBy($column, $direction);

        $values = $service->get();
        $this->assertTrue($values->count() > 0);

        $checkValue = null;
        $values->each(function (CustomValue $value) use (&$checkValue, $direction) {
            // get parent value
            $parent_value = $value->getParentValue();

            $this->assertTrue(is_null($checkValue) || ($direction == 'asc' ? $parent_value->getValue('index_text') >= $checkValue : $parent_value->getValue('index_text') <= $checkValue));
            $checkValue = $parent_value->getValue('index_text');
        });
    }



    public function testOrderManyMany()
    {
        // Not support order by many-to-many relation
        try {
            $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE_MANY_TO_MANY);
            $column = CustomColumn::getEloquent('index_text', $custom_table);
            $direction = 'desc';

            $service = new SearchService($custom_table);
            $service->orderBy($column, $direction);

            $this->assertTrue(false, 'Not support order by many-to-many relation');
        } catch (\Exception $ex) {
            $this->assertTrue(true);
        }
    }

    public function testOrderSelectTable()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE_SELECT);
        $service = new SearchService($custom_table);

        $parent_custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_SELECT);
        $parent_custom_column = CustomColumn::getEloquent('index_text', $parent_custom_table);
        // get parent custom column
        $service->orderBy($parent_custom_column);

        $values = $service->get();
        $this->assertTrue($values->count() > 0);

        $checkValue = null;
        $values->each(function (CustomValue $value) use (&$checkValue) {
            // get parent value
            $parent_value = $value->getValue('parent_select_table');

            $this->assertTrue(is_null($checkValue) || $parent_value->getValue('index_text') >= $checkValue);
            $checkValue = $parent_value->getValue('index_text');
        });
    }
}
