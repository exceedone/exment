<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;

class PermissionValueTest extends UnitTestBase
{
    protected function init($loginId)
    {
        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
        $this->be(LoginUser::find($loginId));
    }


    public function testCustomValueAllTableAdmin()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $ids = $custom_table->getValueModel()->all()->pluck('id')->toArray();

        $this->checkCustomValuePermission($custom_table, $ids);
    }

    public function testCustomValueFilterAdmin()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW);
        $ids = $custom_table->getValueModel()->all()->pluck('id')->toArray();

        $this->checkCustomValuePermission($custom_table, $ids);
    }


    public function testCustomValueAllTable()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $ids = $custom_table->getValueModel()->all()->pluck('id')->toArray();

        $this->checkCustomValuePermission($custom_table, $ids);
    }

    public function testCustomValueFilter()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW);
        $ids = $custom_table->getValueModel()->all()->pluck('id')->toArray();

        $this->checkCustomValuePermission($custom_table, $ids);
    }
}
