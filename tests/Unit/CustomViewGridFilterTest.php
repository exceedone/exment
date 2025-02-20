<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\DataItems\Grid\DefaultGrid;
use Carbon\Carbon;
use Illuminate\Support\Str;

class CustomViewGridFilterTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    /**
     * Grid Filter = ID
     */
    public function testFuncFilterId()
    {
        $this->init();

        $this->__testGridFilter(['id' => 10], function ($data) {
            $actual = array_get($data, 'id');
            return $actual == 10;
        }, 1);
    }

    /**
     * Grid Filter = created_at
     */
    public function testFuncFilterCreatedAt()
    {
        $this->init();

        $dbTableName = \getDBTableName(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        \DB::table($dbTableName)->where('id', 10)->update(['created_at' => '2025-02-01 00:00:00']);
        \DB::table($dbTableName)->where('id', 20)->update(['created_at' => '2025-02-01 23:59:59']);

        $this->__testGridFilter(['created_at.start' => '2025-02-01', 'created_at.end' => '2025-02-01'], function ($data) {
            $actual = array_get($data, 'created_at');
            $targetDate = Carbon::create(2025, 2, 1);
            return $actual->isSameDay($targetDate);
        }, 2);
    }

    /**
     * Grid Filter = updated_at
     */
    public function testFuncFilterUpdatedAt()
    {
        $this->init();

        $dbTableName = \getDBTableName(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        \DB::table($dbTableName)->where('id', 10)->update(['updated_at' => '2025-02-01 00:00:00']);
        \DB::table($dbTableName)->where('id', 20)->update(['updated_at' => '2025-02-02 23:59:59']);

        $this->__testGridFilter(['updated_at.start' => '2025-02-01', 'updated_at.end' => '2025-02-02'], function ($data) {
            $actual = array_get($data, 'updated_at');
            return $actual->between(Carbon::create(2025, 2, 1), Carbon::create(2025, 2, 2)->endOfDay());
        }, 2);
    }

    /**
     * Grid Filter = created_user_id
     */
    public function testFuncFilterCreatedUser()
    {
        $this->init();

        $this->__testGridFilter(['created_user_id' => TestDefine::TESTDATA_USER_LOGINID_USER1], function ($data) {
            $actual = array_get($data, 'created_user_id');
            return $actual == TestDefine::TESTDATA_USER_LOGINID_USER1;
        }, 10);
    }

    /**
     * Grid Filter = updated_user_id
     */
    public function testFuncFilterUpdatedUser()
    {
        $this->init();

        $this->__testGridFilter(['updated_user_id' => [TestDefine::TESTDATA_USER_LOGINID_USER2, TestDefine::TESTDATA_USER_LOGINID_DEV_USERB]], function ($data) {
            $actual = array_get($data, 'updated_user_id');
            return ($actual == TestDefine::TESTDATA_USER_LOGINID_USER2 || $actual == TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        }, 20);
    }

    /**
     * Grid Filter = text
     */
    public function testFuncFilterText()
    {
        $this->init();

        $dbTableName = \getDBTableName(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        \DB::table($dbTableName)->where('id', 10)->update(['value->text' => 'hoge']);

        $custom_column = CustomColumn::getEloquent('text', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => 'hoge'], function ($data) {
            $actual = array_get($data, 'value.text');
            return $actual == 'hoge';
        }, 1);
    }

    /**
     * Grid Filter = url
     */
    public function testFuncFilterUrl()
    {
        $this->init();

        $dbTableName = \getDBTableName(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        \DB::table($dbTableName)->where('id', 10)->update(['value->url' => 'https://exment.net/docs/#/ja/']);

        $custom_column = CustomColumn::getEloquent('url', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => 'https://exment.net/docs/#/ja/'], function ($data) {
            $actual = array_get($data, 'value.url');
            return $actual == 'https://exment.net/docs/#/ja/';
        }, 1);
    }

    /**
     * Grid Filter = email
     */
    public function testFuncFilterEmail()
    {
        $this->init();

        $dbTableName = \getDBTableName(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        \DB::table($dbTableName)->where('id', 10)->update(['value->email' => 'hoge@test.com']);

        $custom_column = CustomColumn::getEloquent('email', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => 'hoge@test.com'], function ($data) {
            $actual = array_get($data, 'value.email');
            return $actual == 'hoge@test.com';
        }, 1);
    }

    /**
     * Grid Filter = integer
     */
    public function testFuncFilterInteger()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('integer', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter(["$db_column_name.start" => 100, "$db_column_name.end" => 10000], function ($data) {
            $actual = array_get($data, 'value.integer');
            return $actual >= 100 && $actual <= 10000;
        });
    }

    /**
     * Grid Filter = decimal
     */
    public function testFuncFilterDecimal()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('decimal', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter(["$db_column_name.start" => -999.9, "$db_column_name.end" => 999.9], function ($data) {
            $actual = array_get($data, 'value.decimal');
            return $actual >= -999.9 && $actual <= 999.9;
        });
    }

    /**
     * Grid Filter = currency
     */
    public function testFuncFilterCurrency()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('currency', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter(["$db_column_name.start" => 1000, "$db_column_name.end" => 100000], function ($data) {
            $actual = array_get($data, 'value.currency');
            return $actual >= 1000 && $actual <= 100000;
        });
    }

    /**
     * Grid Filter = date
     */
    public function testFuncFilterDate()
    {
        $this->init();

        $dbTableName = \getDBTableName(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        \DB::table($dbTableName)->where('id', 10)->update(['value->date' => '2020-11-30']);
        \DB::table($dbTableName)->where('id', 20)->update(['value->date' => '2020-12-01']);

        $custom_column = CustomColumn::getEloquent('date', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter(["$db_column_name.start" => '2020-11-30', "$db_column_name.end" => '2020-12-01'], function ($data) {
            $actual = Carbon::parse(array_get($data, 'value.date'));
            return $actual->between(Carbon::create(2020, 11, 30), Carbon::create(2020, 12, 1));
        }, 2);
    }

    /**
     * Grid Filter = time
     */
    public function testFuncFilterTime()
    {
        $this->init();

        $dbTableName = \getDBTableName(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        \DB::table($dbTableName)->where('id', 10)->update(['value->time' => '10:00:00']);
        \DB::table($dbTableName)->where('id', 20)->update(['value->time' => '09:59:59']);

        $custom_column = CustomColumn::getEloquent('time', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter(["$db_column_name.start" => '09:59:59', "$db_column_name.end" => '10:00:00'], function ($data) {
            $actual = Carbon::createFromTimeString(array_get($data, 'value.time'));
            return $actual->gte(Carbon::createFromTimeString('09:59:59')) && $actual->lte(Carbon::createFromTimeString('10:00:00'));
        }, 2);
    }

    /**
     * Grid Filter = datetime
     */
    public function testFuncFilterDateTime()
    {
        $this->init();

        $dbTableName = \getDBTableName(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        \DB::table($dbTableName)->where('id', 10)->update(['value->datetime' => '2020-11-30 23:59:59']);
        \DB::table($dbTableName)->where('id', 20)->update(['value->datetime' => '2020-12-01 00:00:01']);

        $custom_column = CustomColumn::getEloquent('datetime', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter(["$db_column_name.start" => '2020-11-30 23:59:59', "$db_column_name.end" => '2020-12-01 00:00:01'], function ($data) {
            $actual = Carbon::createFromTimeString(array_get($data, 'value.datetime'));
            return $actual->gte(Carbon::createFromTimeString('2020-11-30 23:59:59')) && $actual->lte(Carbon::createFromTimeString('2020-12-01 00:00:01'));
        }, 2);
    }

    /**
     * Grid Filter = select
     */
    public function testFuncFilterSelect()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('select', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => 'bar'], function ($data) {
            $actual = array_get($data, 'value.select');
            return $actual == 'bar';
        });
    }

    /**
     * Grid Filter = select_table
     */
    public function testFuncFilterSelectTable()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('select_table', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => [1,2,3]], function ($data) {
            $actual = array_get($data, 'value.select_table');
            return in_array($actual, [1, 2, 3]);
        });
    }

    /**
     * Grid Filter = yesno
     */
    public function testFuncFilterYesNo()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('yesno', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => true], function ($data) {
            $actual = array_get($data, 'value.yesno');
            return boolval($actual);
        });
    }

    /**
     * Grid Filter = boolean
     */
    public function testFuncFilterBoolean()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('boolean', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => 'ng'], function ($data) {
            $actual = array_get($data, 'value.boolean');
            return $actual == 'ng';
        });
    }

    protected function init()
    {
        $this->initAllTest();
    }

    protected function __testGridFilter(array $filters, \Closure $testCallback, ?int $count = null)
    {
        $this->init();

        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $custom_view = CustomView::getAllData($custom_table);
        $default = new DefaultGrid($custom_table, $custom_view);
        $request = request();
        $request->merge($filters);
        $request->merge(['execute_filter' => '1']);
        $grid = $default->grid();
        $grid->getFilter()->disableIdFilter(false);

        $list = $grid->applyFilter(false);
        if ($count) {
            $this->assertEquals($list->count(), $count);
        } 

        foreach ($list as $data) {
            $matchResult = $testCallback($data);

            /** @var mixed $data */
            $this->assertTrue($matchResult, 'matchResult is false. Target id is ' . $data->id);
        }
    }
}
