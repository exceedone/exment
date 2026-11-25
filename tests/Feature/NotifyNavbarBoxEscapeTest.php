<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\DashboardBoxItems\SystemItems\NotifyNavbar as NotifyNavbarBox;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The "notifications" box of a dashboard lists the subjects of the user's notifications. A
 * subject is text that whoever sends the notification writes, so the box shows it as text.
 */
class NotifyNavbarBoxEscapeTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
    }

    public function testTheSubjectIsShownAsText(): void
    {
        $notify = new NotifyNavbar();
        $notify->notify_id = 1;
        $notify->target_user_id = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id;
        $notify->trigger_user_id = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1)->base_user_id;
        $notify->read_flg = false;
        $notify->notify_subject = '<b class="escape-check">subject</b>';
        $notify->notify_body = 'body';
        $notify->save();

        $html = (string)(new NotifyNavbarBox(new DashboardBox()))->body();

        $this->assertStringNotContainsString('<b class="escape-check">', $html);
        $this->assertStringContainsString('&lt;b class=&quot;escape-check&quot;&gt;', $html);
    }
}
