<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Tests\TestDefine;

class AccessibleUserTest extends UnitTestBase
{
    public function testFuncCustomValueEdit()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);

        $users = $custom_table->getAccessibleUsers()->map(function ($val) {
            return array_get($val, 'id');
        })->toArray();

        $target_users = CustomTable::getEloquent(SystemTableName::USER)->getValueModel()
            ->where('value->user_code', '<>', 'company2-userF')->pluck('id')->toArray();

        $this->_compareArray($users, $target_users);
    }

    public function testFuncInformationTable()
    {
        $custom_table = CustomTable::getEloquent('information');

        $users = $custom_table->getAccessibleUsers()->map(function ($val) {
            return array_get($val, 'id');
        })->toArray();

        $target_users = CustomTable::getEloquent(SystemTableName::USER)->getValueModel()->all()->pluck('id')->toArray();

        $this->_compareArray($users, $target_users);
    }

    public function testFuncNoPermission()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NO_PERMISSION);

        $users = $custom_table->getAccessibleUsers()->map(function ($val) {
            return array_get($val, 'id');
        })->toArray();

        $target_users = CustomTable::getEloquent(SystemTableName::USER)->getValueModel()
            ->where(function ($query) {
                $query->orWhere('value->user_code', 'admin')
                      ->orWhere('value->user_code', 'user1');
            })->pluck('id')->toArray();

        $this->_compareArray($users, $target_users);
    }

    public function testFuncCustomValueEditValue()
    {
        $this->initAllTest();

        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);

        $custom_value = $custom_table->getValueModel()->where('created_user_id', TestDefine::TESTDATA_USER_LOGINID_DEV1_USERD)
            ->first();

        $users = $custom_value->getAccessibleUsers()->map(function ($val) {
            return array_get($val, 'id');
        })->toArray();

        $target_users = CustomTable::getEloquent(SystemTableName::USER)->getValueModel()
            ->where(function ($query) {
                $query->orWhere('value->user_code', 'admin')
                      ->orWhere('value->user_code', 'user1')
                      ->orWhere('value->user_code', 'dev1-userD');
            })->pluck('id')->toArray();

        $this->_compareArray($users, $target_users);
    }

    public function testFuncCustomValueEditValue2()
    {
        $this->initAllTest();

        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);

        $custom_value = $custom_table->getValueModel()->where('created_user_id', TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC)
            ->first();

        $users = $custom_value->getAccessibleUsers()->map(function ($val) {
            return array_get($val, 'id');
        })->toArray();

        $target_users = CustomTable::getEloquent(SystemTableName::USER)->getValueModel()
            ->where(function ($query) {
                $query->orWhere('value->user_code', 'admin')
                      ->orWhere('value->user_code', 'user1')
                      ->orWhere('value->user_code', 'dev1-userC');
            })->pluck('id')->toArray();

        $this->_compareArray($users, $target_users);
    }

    protected function _compareArray(array $array1, array $array2)
    {
        $result = array_diff($array1, $array2);
        $this->assertTrue(empty($result));

        $result = array_diff($array2, $array1);
        $this->assertTrue(empty($result));
    }
}
