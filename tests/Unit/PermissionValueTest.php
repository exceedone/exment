<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;

class PermissionValueTest extends UnitTestBase
{
    /**
     * @param string $loginId
     * @return void
     */
    protected function init($loginId)
    {
        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
        // @phpstan-ignore-next-line
        $this->be(LoginUser::find($loginId));
    }

    /**
     * @return void
     */
    public function testCustomValueAllTableAdmin()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        /** @var CustomTable $custom_table */
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        // @phpstan-ignore-next-line
        $ids = $custom_table->getValueModel()->all()->pluck('id')->toArray();

        $this->checkCustomValuePermission($custom_table, $ids);
    }

    /**
     * @return void
     */
    public function testCustomValueFilterAdmin()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        /** @var CustomTable $custom_table */
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW);
        // @phpstan-ignore-next-line
        $ids = $custom_table->getValueModel()->all()->pluck('id')->toArray();

        $this->checkCustomValuePermission($custom_table, $ids);
    }


    /**
     * @return void
     */
    public function testCustomValueAllTable()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_USER2);

        /** @var CustomTable $custom_table */
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        // @phpstan-ignore-next-line
        $ids = $custom_table->getValueModel()->all()->pluck('id')->toArray();

        $this->checkCustomValuePermission($custom_table, $ids);
    }

    /**
     * @return void
     */
    public function testCustomValueFilter()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_USER2);

        /** @var CustomTable $custom_table */
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW);
        // @phpstan-ignore-next-line
        $ids = $custom_table->getValueModel()->all()->pluck('id')->toArray();

        $this->checkCustomValuePermission($custom_table, $ids);
    }
}
