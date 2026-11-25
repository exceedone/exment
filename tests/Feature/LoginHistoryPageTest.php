<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The login history on the page of the user setting, and the settings of the notice of a login
 * from a new IP address on the page of the login settings (ISS00104).
 */
class LoginHistoryPageTest extends FeatureTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    /**
     * @param int|string $id the login user of the test data (TestDefine)
     */
    protected function loginAs($id): LoginUser
    {
        $user = LoginUser::findOrFail($id);
        $this->be($user);
        System::clearRequestSession();
        return $user;
    }

    public function testTheUserSeesOnlyTheOwnLogins(): void
    {
        $other = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        LoginLog::create(['base_user_id' => $other->base_user_id, 'result' => LoginLog::RESULT_SUCCESS, 'ip' => '192.0.2.201', 'created_at' => now()]);
        $user = $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        LoginLog::create(['base_user_id' => $user->base_user_id, 'result' => LoginLog::RESULT_FAILURE, 'failure_reason' => LoginLog::REASON_WRONG_PASSWORD, 'ip' => '192.0.2.202', 'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0', 'created_at' => now()]);

        // an id of another user in the request is not used
        $page = $this->get(admin_url('auth/setting') . '?base_user_id=' . $other->base_user_id)->assertOk();
        $page->assertSee(exmtrans('login.history_header'));
        $page->assertSee('192.0.2.202');
        $page->assertSee('Firefox (Linux)');
        $page->assertSee(exmtrans('log_management.reason.wrong_password'));
        $page->assertDontSee('192.0.2.201');
    }

    public function testTheSettingsOfTheNotice(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertFalse(boolval(System::login_notify_new_ip()), 'off by default');
        $this->assertFalse(boolval(System::login_notify_new_ip_mail()), 'off by default');

        $this->get(admin_url('login_setting'))->assertOk()
            ->assertSee(exmtrans('login.login_notify_new_ip'))
            ->assertSee(exmtrans('login.login_notify_new_ip_mail'));
    }
}
