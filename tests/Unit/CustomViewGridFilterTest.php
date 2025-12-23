<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewGridFilter;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\DataItems\Grid\DefaultGrid;
use Carbon\Carbon;
use Exceedone\Exment\DataItems\Grid\SummaryGrid;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

class CustomViewGridFilterTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    protected $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

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

        $dbTableName = \getDBTableName($this->table_name);
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

        $dbTableName = \getDBTableName($this->table_name);
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

        $dbTableName = \getDBTableName($this->table_name);
        $json_func = $this->getJsonUpdateFunction();
        \DB::table($dbTableName)->where('id', 10)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.text', 'hoge')")]);

        $custom_column = CustomColumn::getEloquent('text', $this->table_name);
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

        $dbTableName = \getDBTableName($this->table_name);
        $json_func = $this->getJsonUpdateFunction();
        \DB::table($dbTableName)->where('id', 10)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.url', 'https://exment.net/docs/#/ja/')")]);

        $custom_column = CustomColumn::getEloquent('url', $this->table_name);
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

        $dbTableName = \getDBTableName($this->table_name);
        $json_func = $this->getJsonUpdateFunction();
        \DB::table($dbTableName)->where('id', 10)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.email', 'hoge@test.com')")]);

        $custom_column = CustomColumn::getEloquent('email', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('integer', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('decimal', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('currency', $this->table_name);
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

        $dbTableName = \getDBTableName($this->table_name);
        $json_func = $this->getJsonUpdateFunction();
        \DB::table($dbTableName)->where('id', 10)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.date', '2020-11-30')")]);
        \DB::table($dbTableName)->where('id', 20)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.date', '2020-12-01')")]);

        $custom_column = CustomColumn::getEloquent('date', $this->table_name);
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

        $dbTableName = \getDBTableName($this->table_name);
        $json_func = $this->getJsonUpdateFunction();
        \DB::table($dbTableName)->where('id', 10)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.time', '10:00:00')")]);
        \DB::table($dbTableName)->where('id', 20)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.time', '09:59:59')")]);

        $custom_column = CustomColumn::getEloquent('time', $this->table_name);
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

        $dbTableName = \getDBTableName($this->table_name);
        $json_func = $this->getJsonUpdateFunction();
        \DB::table($dbTableName)->where('id', 10)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.datetime', '2020-11-30 23:59:59')")]);
        \DB::table($dbTableName)->where('id', 20)
            ->update(['value' => \DB::raw("{$json_func}(value, '$.datetime', '2020-12-01 00:00:01')")]);

        $custom_column = CustomColumn::getEloquent('datetime', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('select', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('select_table', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('yesno', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('boolean', $this->table_name);
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

        $custom_table = CustomTable::getEloquent($this->table_name);
        $target = $custom_table->getValueModel()->find(10);
        $auto_number = substr($target->getValue('auto_number'), 0, 5);

        $custom_column = CustomColumn::getEloquent('auto_number', $this->table_name);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $this->__testGridFilter([$db_column_name => $auto_number], function ($data) {
            return array_get($data, 'id') == 10;
        }, 1);
    }

    /**
     * Grid Filter = user
     */
    public function testFuncFilterUser()
    {
        $this->init();

        $custom_column = CustomColumn::getEloquent('user', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('organization', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('select_multiple', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('select_valtext_multiple', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('select_table_multiple', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('user_multiple', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('organization_multiple', $this->table_name);
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

        $custom_column = CustomColumn::getEloquent('file_multiple', $this->table_name);
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

    /**
     * Grid Filter = workflow_status
     */
    public function testFuncFilterWorkflowStatus()
    {
        $this->init();
        $this->table_name = TestDefine::TESTDATA_TABLE_NAME_EDIT;

        $custom_table = CustomTable::getEloquent($this->table_name);
        $workflow = Workflow::getWorkflowByTable($custom_table);
        $workflow_status = $workflow->workflow_statuses->first(function($data) {
            return $data->status_name == 'status1';
        });
        $target_id = $workflow_status->id;

        $this->__testGridFilter(['workflow_status_to_id' => $target_id], function ($data) use($target_id) {
            if ($workflow_value = $data->workflow_value) {
                return $workflow_value->workflow_status_to_id == $target_id;
            }
            return false;
        });
    }

    /**
     * Grid Filter = workflow_work_users
     */
    public function testFuncFilterWorkflowUsers()
    {
        $this->init();
        $this->table_name = TestDefine::TESTDATA_TABLE_NAME_EDIT;

        // Login user.
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC));

        $this->__testGridFilter(['workflow_work_users' => 1], function ($data) {
            foreach($data->workflow_work_users as $user) {
                if ($user->id == \Exment::user()->base_user->id) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Grid Filter = parent_id
     */
    public function testFuncFilterParentID()
    {
        $this->init();
        $this->table_name = TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE;

        $this->__testGridFilter(['parent_id_child_table' => 3], function ($data) {
            return $data->parent_id == 3;
        });
    }

    /**
     * Grid Filter = comment
     */
    public function testFuncFilterComment()
    {
        $this->init();

        $this->saveComment(10, 'fugafuga');
        $this->saveComment(10, 'start hoge end');

        $this->__testGridFilter(['comment' => 'hoge'], function ($data) {
            return array_get($data, 'id') == 10;
        }, 1, function() {
            System::setRequestSession('setting.grid_filter_disable_flg', []);
        });
    }

    /**
     * Grid Filter = comment, filter japanese
     */
    public function testFuncFilterCommentJp()
    {
        $this->init();

        $this->saveComment(10, 'ダミーコメント');
        $this->saveComment(10, 'いろいろ\nてすと\nします');

        $this->__testGridFilter(['comment' => 'てすと'], function ($data) {
            return array_get($data, 'id') == 10;
        }, 1, function() {
            System::setRequestSession('setting.grid_filter_disable_flg', []);
        });
    }

    /**
     * Grid Filter = comment, multiple result
     */
    public function testFuncFilterCommentMulti()
    {
        $this->init();

        $this->saveComment(10, 'fugafuga');
        $this->saveComment(10, 'start hoge end');
        $this->saveComment(10, 'hogehoge');
        $this->saveComment(20, '\nhoge');

        $this->__testGridFilter(['comment' => 'hoge'], function ($data) {
            return in_array(array_get($data, 'id'), [10, 20]);
        }, 2, function() {
            System::setRequestSession('setting.grid_filter_disable_flg', []);
        });
    }

    /**
     * Summary Grid Filter = ID
     */
    public function testFuncSummaryFilterId()
    {
        $this->init();

        $custom_table = CustomTable::getEloquent($this->table_name);
        // 対象データを抽出
        $values = $custom_table->getValueModel()
            ->where('id', 1)
            ->get();

        $monthly_sum = $this->getGroupingData($values);

        $this->__testSummaryGridFilter(['id' => 1],
            $this->createAssertClosure(),
            $monthly_sum);
    }

    protected function getGroupingData($values)
    {
        $grouped = $values->groupBy(function($item) {
            if (is_nullorempty($date = $item->getValue('date'))) {
                return null;
            }
            // 'Y-m'形式で年月グルーピング
            return Carbon::parse($date)->format('Y-m');
        });

        // 月ごとに合計を算出
        return $grouped->map(function($items) {
            return $items->map(function($item) {
                return $item->getValue('integer');
            })->sum();
        });
    }

    protected function createAssertClosure() {
        return function ($data, $custom_table, $monthly_sum) {
            $idx = 0;
            $actual = null;
            foreach($data->toArray() as $val) {
                switch ($idx) {
                    case 0:
                        if ($monthly_sum->has($val)) {
                            $actual = $monthly_sum[$val];
                        } else {
                            return false;
                        }
                        break;
                    case 1:
                        return $val == $actual;
                }
                $idx++;
            }
        };
    }

    /**
     * Summary Grid Filter = updated_user
     */
    public function testFuncSummaryFilterUpdatedUser()
    {
        $this->init();

        $targets = [TestDefine::TESTDATA_USER_LOGINID_USER2, TestDefine::TESTDATA_USER_LOGINID_DEV_USERB];
        $custom_table = CustomTable::getEloquent($this->table_name);

        // 対象データを抽出
        $values = $custom_table->getValueModel()
            ->whereIn('updated_user_id', $targets)
            ->whereIn('value->select', ['bar', 'baz'])
            ->get();

        $monthly_sum = $this->getGroupingData($values);

        $this->__testSummaryGridFilter(['updated_user_id' => $targets],
            $this->createAssertClosure(),
            $monthly_sum);
    }

    /**
     * Summary Grid Filter = updated_user
     */
    public function testFuncSummaryFilterInteger()
    {
        $this->init();

        $custom_table = CustomTable::getEloquent($this->table_name);
        $custom_column = CustomColumn::getEloquent('integer', $custom_table);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $values = $custom_table->getValueModel()
            ->whereBetween('value->integer', [100, 100000])
            ->whereIn('value->select', ['bar', 'baz'])
            ->get();

        $monthly_sum = $this->getGroupingData($values);

        $this->__testSummaryGridFilter(["$db_column_name.start" => 100, "$db_column_name.end" => 100000],
            $this->createAssertClosure(),
            $monthly_sum);
    }

    /**
     * Summary Grid Filter = select_valtext
     */
    public function testFuncSummaryFilterSelectVal()
    {
        $this->init();

        $custom_table = CustomTable::getEloquent($this->table_name);
        $custom_column = CustomColumn::getEloquent('select_valtext', $custom_table);
        $db_column_name = $custom_column->getIndexColumnName(false);

        $values = $custom_table->getValueModel()
            ->whereIn('value->select_valtext', ['foo', 'baz'])
            ->whereIn('value->select', ['bar', 'baz'])
            ->get();

        $monthly_sum = $this->getGroupingData($values);

        $this->__testSummaryGridFilter(["$db_column_name" => ['foo', 'baz']], 
            $this->createAssertClosure(),
            $monthly_sum);
    }

    /**
     * Summary Grid Filter = workflow_status
     */
    public function testFuncSummaryFilterWorkflowStatus()
    {
        $this->init();

        $this->table_name = TestDefine::TESTDATA_TABLE_NAME_EDIT;
        $custom_table = CustomTable::getEloquent($this->table_name);
        $workflow = Workflow::getWorkflowByTable($custom_table);
        $workflow_status = $workflow->workflow_statuses->first(function($data) {
            return $data->status_name == 'status1';
        });
        $target_id = $workflow_status->id;

        $values = $custom_table->getValueModel()
            ->get()
            ->filter(function($item) {
                return $item->workflow_statusname == 'status1';
            });

        $monthly_sum = $this->getGroupingData($values);

        $this->__testSummaryGridFilter(['workflow_status_to_id' => $target_id],
            $this->createAssertClosure(),
            $monthly_sum);
    }

    /**
     * Summary Grid Filter = parent_id
     */
    public function testFuncSummaryFilterParentID()
    {
        $this->init();

        $this->table_name = TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE;
        $custom_table = CustomTable::getEloquent($this->table_name);

        $values = $custom_table->getValueModel()
            ->where('parent_id', 5)
            ->get();

        $monthly_sum = $this->getGroupingData($values);

        $this->__testSummaryGridFilter(['parent_id_child_table' => 5],
            $this->createAssertClosure(),
            $monthly_sum);
    }

    /**
     * Summary Grid Filter = parent_table odd_even
     */
    public function testFuncSummaryFilterParentColumn()
    {
        $this->init();

        $parent_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $target_column = CustomColumn::getEloquent('odd_even', $parent_table);

        $this->table_name = TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE;
        $custom_table = CustomTable::getEloquent($this->table_name);

        $values = $custom_table->getValueModel()
            ->get()
            ->filter(function($data) use($parent_table) {
                $parent_data = $parent_table->getValueModel($data->parent_id);
                return $parent_data->getValue('odd_even') == 'odd';
            })
        ;

        $monthly_sum = $this->getGroupingData($values);

        $this->__testSummaryGridFilter(["ckey_[filter_uuid]" => 'odd'],
            $this->createAssertClosure(),
            $monthly_sum,
            $this->createFilterClosure($target_column, $custom_table));
    }

    /**
     * Summary Grid Filter = reference_table multiples_of_3
     */
    public function testFuncSummaryFilterReferColumn()
    {
        $this->init();

        $target_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $target_column = CustomColumn::getEloquent('multiples_of_3', $target_table);

        $custom_table = CustomTable::getEloquent($this->table_name);
        $pivot_column = CustomColumn::getEloquent('select_table', $custom_table);

        $values = $custom_table->getValueModel()
            ->whereIn('value->select', ['bar', 'baz'])
            ->get()
            ->filter(function($data) {
                $select_data = $data->getValue('select_table');
                if ($select_data) {
                    return $select_data->getValue('multiples_of_3') == '1';
                }
                return false;
            })
        ;

        $monthly_sum = $this->getGroupingData($values);

        $this->__testSummaryGridFilter(["ckey_[filter_uuid]" => 1],
            $this->createAssertClosure(),
            $monthly_sum,
            $this->createFilterClosure($target_column, $pivot_column));
    }

    protected function createFilterClosure($target_column, $pivot_data = null)
    {
        return function ($custom_view_id) use ($target_column, $pivot_data) {
            // save CustomViewGridFilter Model
            $model = new CustomViewGridFilter();
            $model->custom_view_id = $custom_view_id;
            $model->view_column_type = ConditionType::COLUMN;
            $model->view_column_table_id = $target_column->custom_table_id;
            $model->view_column_target_id = $target_column->id;
            if (!is_nullorempty($pivot_data)) {
                if ($pivot_data instanceof CustomColumn) {
                    $model->SetOption('view_pivot_column_id', $pivot_data->id);
                    $model->SetOption('view_pivot_table_id', $pivot_data->custom_table_id);
                } elseif ($pivot_data instanceof CustomTable)  {
                    $model->SetOption('view_pivot_column_id', 'parent_id');
                    $model->SetOption('view_pivot_table_id', $pivot_data->id);
                }
            }
            $model->save();
            return $model;
        };
    }

    protected function saveComment($id, $comment)
    {
        // save Comment Model
        $model = CustomTable::getEloquent(SystemTableName::COMMENT)->getValueModel();
        $model->parent_id = $id;
        $model->parent_type = $this->table_name;
        $model->setValue([
            'comment_detail' => $comment,
        ]);
        $result = $model->save();

    }

    protected function init()
    {
        $this->initAllTest();
    }

    protected function __testGridFilter(array $filters, \Closure $testCallback, ?int $count = null, $prevTest = null)
    {
        //$this->init();

        $custom_table = CustomTable::getEloquent($this->table_name);
        $custom_view = CustomView::getAllData($custom_table);
        $default = new DefaultGrid($custom_table, $custom_view);

        if ($prevTest instanceof \Closure) {
            call_user_func($prevTest);
        }

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

    protected function __testSummaryGridFilter(array $filters, \Closure $testCallback, ?Collection $monthly_sum = null, $prevTest = null)
    {
        $this->init();

        $custom_table = CustomTable::getEloquent($this->table_name);
        $custom_view = CustomView::where('view_view_name', $custom_table->table_name . '-view-summary')->first();
        $default = new SummaryGrid($custom_table, $custom_view);

        if ($prevTest instanceof \Closure) {
            $result = call_user_func($prevTest, $custom_view->id);
            if ($result) {
                $newArray = [];
                foreach ($filters as $k => $v) {
                    $newKey = str_replace('[filter_uuid]', $result->suuid, $k);
                    $newArray[$newKey] = $v;
                }
                $filters = $newArray;
            }
        }

        $request = request();
        $request->merge($filters);
        $request->merge(['execute_filter' => '1']);
        $grid = $default->grid();
        $grid->getFilter()->disableIdFilter(false);
        $grid->paginate(100);

        $list = $grid->applyFilter(false);
        if ($monthly_sum) {
            $this->assertEquals($list->count(), $monthly_sum->count());
        } else {
            $this->assertEquals($list->count(), 1);
        }

        foreach ($list as $data) {
            $matchResult = $testCallback($data, $custom_table, $monthly_sum);

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
