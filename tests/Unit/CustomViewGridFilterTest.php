<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\File as ExmentFile;
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
        $json_func = $this->getJsonUpdateFunction();
        \DB::table($dbTableName)->where('id', 10)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.text', 'hoge')")]);

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
        $json_func = $this->getJsonUpdateFunction();
        \DB::table($dbTableName)->where('id', 10)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.url', 'https://exment.net/docs/#/ja/')")]);

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
        $json_func = $this->getJsonUpdateFunction();
        \DB::table($dbTableName)->where('id', 10)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.email', 'hoge@test.com')")]);

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
        $json_func = $this->getJsonUpdateFunction();
        \DB::table($dbTableName)->where('id', 10)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.date', '2020-11-30')")]);
        \DB::table($dbTableName)->where('id', 20)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.date', '2020-12-01')")]);

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
        $json_func = $this->getJsonUpdateFunction();
        \DB::table($dbTableName)->where('id', 10)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.time', '10:00:00')")]);
        \DB::table($dbTableName)->where('id', 20)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.time', '09:59:59')")]);

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
        $json_func = $this->getJsonUpdateFunction();
        \DB::table($dbTableName)->where('id', 10)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.datetime', '2020-11-30 23:59:59')")]);
        \DB::table($dbTableName)->where('id', 20)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.datetime', '2020-12-01 00:00:01')")]);

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

    /**
     * Grid Filter = auto number
     */
    public function testFuncFilterAutoNumber()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('auto_number', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => 'a'], function ($data) {
            $actual = array_get($data, 'value.auto_number');
            return Str::startsWith($actual, 'a');
        });
    }

    /**
     * Grid Filter = user
     */
    public function testFuncFilterUser()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('user', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => [2,4]], function ($data) {
            $actual = array_get($data, 'value.user');
            return in_array($actual, [2,4]);
        });
    }

    /**
     * Grid Filter = organization
     */
    public function testFuncFilterOrganization()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('organization', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => [1,5]], function ($data) {
            $actual = array_get($data, 'value.organization');
            return in_array($actual, [1,5]);
        });
    }

    /**
     * Grid Filter = select_multiple
     */
    public function testFuncFilterSelectMulti()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('select_multiple', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => ['foo','baz']], function ($data) {
            $actual = array_get($data, 'value.select_multiple');
            return in_array('foo', $actual) || in_array('baz', $actual);
        });
    }

    /**
     * Grid Filter = select_valtext_multiple
     */
    public function testFuncFilterSelectValMulti()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('select_valtext_multiple', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => ['baz']], function ($data) {
            $actual = array_get($data, 'value.select_valtext_multiple');
            return in_array('baz', $actual);
        });
    }

    /**
     * Grid Filter = select_table_multiple
     */
    public function testFuncFilterSelectTableMulti()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('select_table_multiple', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => [5,10]], function ($data) {
            $actual = array_get($data, 'value.select_table_multiple');
            return in_array(5, $actual) || in_array(10, $actual);
        });
    }

    /**
     * Grid Filter = user_multiple
     */
    public function testFuncFilterUserMulti()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('user_multiple', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => [3,4]], function ($data) {
            $actual = array_get($data, 'value.user_multiple');
            return in_array(3, $actual) || in_array(4, $actual);
        });
    }

    /**
     * Grid Filter = organization_multiple
     */
    public function testFuncFilterOrganizationMulti()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('organization_multiple', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => [2]], function ($data) {
            $actual = array_get($data, 'value.organization_multiple');
            return in_array(2, $actual);
        });
    }

    /**
     * Grid Filter = file_multiple
     */
    public function testFuncFilterFileMulti()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('file_multiple', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $custom_table = $custom_column->custom_table;
        $db_column_name = $custom_column->getIndexColumnName(false);

        $file = ExmentFile::storeAs(FileType::CUSTOM_VALUE_COLUMN, TestDefine::FILE_TESTSTRING, $custom_table->table_name, 'test1.txt');

        $custom_value = $custom_table->getValueModel(5);
        $custom_value->setValue('file_multiple', [$file->path])->save();

        $file->saveCustomValue($custom_value->id, $custom_column, $custom_table);
    
        $this->__testGridFilter([$db_column_name => 'test1'], function ($data) {
            $actual = array_get($data, 'value.file_multiple');
            return collect($actual)->contains(function($path) {
                $file = ExmentFile::getData($path);
                return Str::startsWith($file->filename, 'test1');
            });
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
        $grid->paginate(100);

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

    protected function getJsonUpdateFunction(): string
    {
        if (\Exment::isSqlServer()) {
            return 'JSON_MODIFY';
        } else {
            return 'JSON_SET';
        }
    }
}
