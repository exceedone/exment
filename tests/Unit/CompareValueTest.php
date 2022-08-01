<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;

class CompareValueTest extends UnitTestBase
{
    // TEXT Equal ----------------------------------------------------
    public function testMatchTextMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TEXT, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['text1' => 'abc', 'text2' => 'abc' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testMatchTextNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TEXT, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['text1' => null, 'text2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testMatchTextNotMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TEXT, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['text1' => 'abc', 'text2' => 'def' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchTextNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TEXT, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['text1' => null, 'text2' => 'def' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchTextNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TEXT, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['text1' => 'abc', 'text2' => null ]]);

        $this->assertTrue(count($result) > 0);
    }


    // TEXT Not Equal ----------------------------------------------------
    public function testNotMatchTextMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TEXT, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['text1' => 'abc', 'text2' => 'abc' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testNotMatchTextNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TEXT, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['text1' => null, 'text2' => null ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testNotMatchTextNotMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TEXT, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['text1' => 'abc', 'text2' => 'def' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchTextNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TEXT, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['text1' => null, 'text2' => 'def' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchTextNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TEXT, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['text1' => 'abc', 'text2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }




    // INT Equal ----------------------------------------------------
    public function testMatchIntegerMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => 100 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testMatchIntegerNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => null, 'integer2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testMatchIntegerNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => 200 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchIntegerNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 200, 'integer2' => 100 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchIntegerNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => null, 'integer2' => 200 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchIntegerNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => null ]]);

        $this->assertTrue(count($result) > 0);
    }




    // INT Not Equal ----------------------------------------------------
    public function testNotMatchIntegerMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => 100 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testNotMatchIntegerNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => null, 'integer2' => null ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testNotMatchIntegerNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => 200 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchIntegerNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 200, 'integer2' => 100 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchIntegerNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => null, 'integer2' => 200 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchIntegerNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }




    // INT GT ----------------------------------------------------
    public function testGtIntegerMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => 100 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGtIntegerNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => null, 'integer2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtIntegerNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => 200 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGtIntegerNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 200, 'integer2' => 100 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtIntegerNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => null, 'integer2' => 200 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtIntegerNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }




    // INT GTE ----------------------------------------------------
    public function testGteIntegerMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => 100 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteIntegerNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => null, 'integer2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteIntegerNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => 200 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGteIntegerNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 200, 'integer2' => 100 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteIntegerNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => null, 'integer2' => 200 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteIntegerNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }




    // INT LT ----------------------------------------------------
    public function testLtIntegerMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => 100 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLtIntegerNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => null, 'integer2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtIntegerNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => 200 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtIntegerNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 200, 'integer2' => 100 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLtIntegerNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => null, 'integer2' => 200 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtIntegerNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }




    // INT LTE ----------------------------------------------------
    public function testLteIntegerMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => 100 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteIntegerNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => null, 'integer2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteIntegerNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => 200 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteIntegerNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 200, 'integer2' => 100 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLteIntegerNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => null, 'integer2' => 200 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteIntegerNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::INTEGER, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['integer1' => 100, 'integer2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }







    // DECIMAL Equal ----------------------------------------------------
    public function testMatchDecimalMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => 20.25 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testMatchDecimalNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => null, 'decimal2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testMatchDecimalNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => 50.72 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchDecimalNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 50.72, 'decimal2' => 20.25 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchDecimalNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => null, 'decimal2' => 50.72 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchDecimalNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => null ]]);

        $this->assertTrue(count($result) > 0);
    }




    // DECIMAL Not Equal ----------------------------------------------------
    public function testNotMatchDecimalMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => 20.25 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testNotMatchDecimalNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => null, 'decimal2' => null ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testNotMatchDecimalNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => 50.72 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchDecimalNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 50.72, 'decimal2' => 20.25 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchDecimalNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => null, 'decimal2' => 50.72 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchDecimalNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }




    // DECIMAL GT ----------------------------------------------------
    public function testGtDecimalMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => 20.25 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGtDecimalNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => null, 'decimal2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtDecimalNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => 50.72 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGtDecimalNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 50.72, 'decimal2' => 20.25 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtDecimalNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => null, 'decimal2' => 50.72 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtDecimalNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }




    // DECIMAL GTE ----------------------------------------------------
    public function testGteDecimalMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => 20.25 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteDecimalNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => null, 'decimal2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteDecimalNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => 50.72 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGteDecimalNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 50.72, 'decimal2' => 20.25 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteDecimalNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => null, 'decimal2' => 50.72 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteDecimalNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }




    // DECIMAL LT ----------------------------------------------------
    public function testLtDecimalMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => 20.25 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLtDecimalNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => null, 'decimal2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtDecimalNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => 50.72 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtDecimalNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 50.72, 'decimal2' => 20.25 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLtDecimalNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => null, 'decimal2' => 50.72 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtDecimalNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }




    // DECIMAL LTE ----------------------------------------------------
    public function testLteDecimalMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => 20.25 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteDecimalNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => null, 'decimal2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteDecimalNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => 50.72 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteDecimalNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 50.72, 'decimal2' => 20.25 ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLteDecimalNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => null, 'decimal2' => 50.72 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteDecimalNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DECIMAL, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['decimal1' => 20.25, 'decimal2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }









    // Date Equal ----------------------------------------------------
    public function testMatchDateMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => '2020-05-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testMatchDateNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => null, 'date2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testMatchDateNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => '2020-09-01' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchDateNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-09-01', 'date2' => '2020-05-01' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchDateNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => null, 'date2' => '2020-09-01' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchDateNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => null ]]);

        $this->assertTrue(count($result) > 0);
    }



    // Date Not Equal ----------------------------------------------------
    public function testNotMatchDateMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => '2020-05-01' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testNotMatchDateNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => null, 'date2' => null ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testNotMatchDateNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => '2020-09-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchDateNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-09-01', 'date2' => '2020-05-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchDateNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => null, 'date2' => '2020-09-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchDateNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }



    // Date GT ----------------------------------------------------
    public function testGtDateMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => '2020-05-01' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGtDateNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => null, 'date2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtDateNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => '2020-09-01' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGtDateNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-09-01', 'date2' => '2020-05-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtDateNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => null, 'date2' => '2020-09-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtDateNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }



    // Date GTE ----------------------------------------------------
    public function testGteDateMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => '2020-05-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteDateNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => null, 'date2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteDateNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => '2020-09-01' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGteDateNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-09-01', 'date2' => '2020-05-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteDateNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => null, 'date2' => '2020-09-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteDateNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }



    // Date LT ----------------------------------------------------
    public function testLtDateMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => '2020-05-01' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLtDateNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => null, 'date2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtDateNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => '2020-09-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtDateNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-09-01', 'date2' => '2020-05-01' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLtDateNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => null, 'date2' => '2020-09-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtDateNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }



    // Date LTE ----------------------------------------------------
    public function testLteDateMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => '2020-05-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteDateNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => null, 'date2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteDateNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => '2020-09-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteDateNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-09-01', 'date2' => '2020-05-01' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLteDateNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => null, 'date2' => '2020-09-01' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteDateNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATE, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['date1' => '2020-05-01', 'date2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }






    // Time Equal ----------------------------------------------------
    public function testMatchTimeMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => '08:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testMatchTimeNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => null, 'time2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testMatchTimeNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => '12:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchTimeNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '12:00', 'time2' => '08:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchTimeNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => null, 'time2' => '12:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchTimeNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => null ]]);

        $this->assertTrue(count($result) > 0);
    }



    // Time Not Equal ----------------------------------------------------
    public function testNotMatchTimeMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => '08:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testNotMatchTimeNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => null, 'time2' => null ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testNotMatchTimeNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => '12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchTimeNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '12:00', 'time2' => '08:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchTimeNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => null, 'time2' => '12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchTimeNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }



    // Time GT ----------------------------------------------------
    public function testGtTimeMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => '08:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGtTimeNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => null, 'time2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtTimeNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => '12:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGtTimeNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '12:00', 'time2' => '08:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtTimeNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => null, 'time2' => '12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtTimeNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }



    // Time GTE ----------------------------------------------------
    public function testGteTimeMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => '08:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteTimeNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => null, 'time2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteTimeNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => '12:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGteTimeNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '12:00', 'time2' => '08:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteTimeNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => null, 'time2' => '12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteTimeNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }



    // Time LT ----------------------------------------------------
    public function testLtTimeMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => '08:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLtTimeNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => null, 'time2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtTimeNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => '12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtTimeNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '12:00', 'time2' => '08:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLtTimeNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => null, 'time2' => '12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtTimeNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }



    // Time LTE ----------------------------------------------------
    public function testLteTimeMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => '08:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteTimeNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => null, 'time2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteTimeNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => '12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteTimeNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '12:00', 'time2' => '08:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLteTimeNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => null, 'time2' => '12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteTimeNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::TIME, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['time1' => '08:00', 'time2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }





    // DateTime Equal ----------------------------------------------------
    public function testMatchDateTimeMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => '2020-05-01 08:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testMatchDateTimeNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => null, 'datetime2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testMatchDateTimeNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => '2020-09-01 12:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchDateTimeNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-09-01 12:00', 'datetime2' => '2020-05-01 08:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchDateTimeNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => null, 'datetime2' => '2020-09-01 12:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testMatchDateTimeNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::EQ);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => null ]]);

        $this->assertTrue(count($result) > 0);
    }



    // DateTime Not Equal ----------------------------------------------------
    public function testNotMatchDateTimeMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => '2020-05-01 08:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testNotMatchDateTimeNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => null, 'datetime2' => null ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testNotMatchDateTimeNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => '2020-09-01 12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchDateTimeNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-09-01 12:00', 'datetime2' => '2020-05-01 08:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchDateTimeNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => null, 'datetime2' => '2020-09-01 12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotMatchDateTimeNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::NE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }



    // DateTime GT ----------------------------------------------------
    public function testGtDateTimeMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => '2020-05-01 08:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGtDateTimeNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => null, 'datetime2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtDateTimeNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => '2020-09-01 12:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGtDateTimeNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-09-01 12:00', 'datetime2' => '2020-05-01 08:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtDateTimeNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => null, 'datetime2' => '2020-09-01 12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGtDateTimeNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_GT);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }



    // DateTime GTE ----------------------------------------------------
    public function testGteDateTimeMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => '2020-05-01 08:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteDateTimeNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => null, 'datetime2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteDateTimeNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => '2020-09-01 12:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testGteDateTimeNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-09-01 12:00', 'datetime2' => '2020-05-01 08:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteDateTimeNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => null, 'datetime2' => '2020-09-01 12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testGteDateTimeNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_GTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }



    // DateTime LT ----------------------------------------------------
    public function testLtDateTimeMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => '2020-05-01 08:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLtDateTimeNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => null, 'datetime2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtDateTimeNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => '2020-09-01 12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtDateTimeNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-09-01 12:00', 'datetime2' => '2020-05-01 08:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLtDateTimeNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => null, 'datetime2' => '2020-09-01 12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLtDateTimeNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_LT);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }



    // DateTime LTE ----------------------------------------------------
    public function testLteDateTimeMatch()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => '2020-05-01 08:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteDateTimeNulls()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => null, 'datetime2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteDateTimeNotMatch1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => '2020-09-01 12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteDateTimeNotMatch2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-09-01 12:00', 'datetime2' => '2020-05-01 08:00' ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testLteDateTimeNull1()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => null, 'datetime2' => '2020-09-01 12:00' ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testLteDateTimeNull2()
    {
        $custom_table = $this->initCompareValueTest(ColumnType::DATETIME, FilterOption::COMPARE_LTE);

        $result = $custom_table->validatorCompareColumns(['value' => ['datetime1' => '2020-05-01 08:00', 'datetime2' => null ]]);

        $this->assertTrue(count($result) == 0);
    }











    protected function initCompareValueTest($column_type, $filter_option): CustomTable
    {
        $custom_table = CustomTable::getEloquent('custom_value_view_all');

        $columns = [
            ColumnType::TEXT => [
                ['column_name' => 'text1', 'column_view_name' => 'text1', 'column_type' => ColumnType::TEXT],
                ['column_name' => 'text2', 'column_view_name' => 'text2', 'column_type' => ColumnType::TEXT],
            ],
            ColumnType::DATE => [
                ['column_name' => 'date1', 'column_view_name' => 'date1', 'column_type' => ColumnType::DATE, 'options' => []],
                ['column_name' => 'date2', 'column_view_name' => 'date2', 'column_type' => ColumnType::DATE, 'options' => []],
            ],
            ColumnType::TIME => [
                ['column_name' => 'time1', 'column_view_name' => 'time1', 'column_type' => ColumnType::TIME, 'options' => []],
                ['column_name' => 'time2', 'column_view_name' => 'time2', 'column_type' => ColumnType::TIME, 'options' => []],
            ],
            ColumnType::DATETIME => [
                ['column_name' => 'datetime1', 'column_view_name' => 'datetime1', 'column_type' => ColumnType::DATETIME, 'options' => []],
                ['column_name' => 'datetime2', 'column_view_name' => 'datetime2', 'column_type' => ColumnType::DATETIME, 'options' => []],
            ],
            ColumnType::INTEGER => [
                ['column_name' => 'integer1', 'column_view_name' => 'integer1', 'column_type' => ColumnType::INTEGER, 'options' => []],
                ['column_name' => 'integer2', 'column_view_name' => 'integer2', 'column_type' => ColumnType::INTEGER, 'options' => []],
            ],
            ColumnType::DECIMAL => [
                ['column_name' => 'decimal1', 'column_view_name' => 'decimal1', 'column_type' => ColumnType::DECIMAL, 'options' => []],
                ['column_name' => 'decimal2', 'column_view_name' => 'decimal2', 'column_type' => ColumnType::DECIMAL, 'options' => []],
            ],
        ];

        $custom_columns = [];
        $custom_column_multis = [];

        // dummy id
        $dummy_id = 900000;

        $column_blocks = $columns[$column_type];

        $custom_column_id1 = null;
        $custom_column_id2 = null;

        foreach ($column_blocks as $index => $column) {
            $custom_column = new CustomColumn();
            $custom_column->custom_table_id = $custom_table->id;
            $custom_column->column_name = $column['column_name'];
            $custom_column->column_view_name = $column['column_view_name'];
            $custom_column->column_type = $column['column_type'];
            $custom_column->id = $dummy_id++;

            $custom_columns[] = $custom_column;

            if ($index == 0) {
                $custom_column_id1 = $custom_column->id;
            } else {
                $custom_column_id2 = $custom_column->id;
            }
        }

        $custom_column_multi = new CustomColumnMulti();
        $custom_column_multi->custom_table_id = $custom_table->id;
        $custom_column_multi->multisetting_type = MultisettingType::COMPARE_COLUMNS;
        $custom_column_multi->compare_column1_id = $custom_column_id1;
        $custom_column_multi->compare_column2_id = $custom_column_id2;
        $custom_column_multi->compare_type = $filter_option;

        $custom_column_multis[] = $custom_column_multi;

        System::clearRequestSession();
        System::requestSession(sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, CustomColumn::getTableName()), collect($custom_columns));
        System::requestSession(sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, CustomColumnMulti::getTableName()), collect($custom_column_multis));

        return $custom_table;
    }
}
