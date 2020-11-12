<?php

namespace Exceedone\Exment\Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Notification;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

class NotifyTest extends TestCase
{
    use TestTrait;

    protected function init(bool $fake)
    {
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));

        if($fake){
            Notification::fake();
            Notification::assertNothingSent();
        }
    }

    /**
     * Check custom value notify user only once.
     *
     * @return void
     */
    public function testNotifyCustomValueCreateOnlyOnce()
    {
        $this->init(false);
        
        // save custom value
        $custom_value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT)->getValueModel();
        $custom_value->setValue([
            'text' => 'test',
        ])->save();

        // checking notify count
        $data = NotifyNavbar::where('parent_id', $custom_value->id)
            ->where('parent_type', $custom_value->custom_table_name)
            ->get();

        $this->assertTrue($data->count() === 1, 'NotifyNavbar count excepts 1, but count is ' . $data->count());
    }

}
