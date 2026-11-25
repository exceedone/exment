<?php

namespace Exceedone\Exment\Tests\Unit;

use Carbon\Carbon;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\LogManagement\LoginHistory;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * The logins of a user for the user (ISS00104): the user's own login history, and the notice of
 * a login from a new IP address.
 */
class LoginHistoryTest extends UnitTestBase
{
    /** @var LoginUser */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        LoginLog::resetTableExists();
        \DB::beginTransaction();
        $this->user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        // the records other tests left for the user
        LoginLog::query()->where('base_user_id', $this->user->base_user_id)->delete();
        System::login_notify_new_ip(true);
        System::login_notify_new_ip_mail(false);
        System::clearCache();
    }

    protected function tearDown(): void
    {
        \DB::rollBack();
        System::clearCache();
        parent::tearDown();
    }

    protected function loginFrom(string $ip): void
    {
        $request = Request::create('/admin/auth/login', 'POST', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36']);
        $this->app->instance('request', $request);
        event(new Login('admin', $this->user, false));
    }

    protected function success(string $ip, Carbon $at): void
    {
        LoginLog::create([
            'login_user_id' => $this->user->id,
            'base_user_id' => $this->user->base_user_id,
            'result' => LoginLog::RESULT_SUCCESS,
            'ip' => $ip,
            'created_at' => $at,
        ]);
    }

    protected function notices(): int
    {
        return NotifyNavbar::withoutGlobalScopes()
            ->where('target_user_id', $this->user->base_user_id)
            ->where('notify_subject', exmtrans('login.new_ip_subject'))
            ->count();
    }

    public function testTheFirstLoginIsNotNew(): void
    {
        $this->loginFrom('203.0.113.21');
        $this->assertSame(0, $this->notices());
        $this->assertSame(1, LoginLog::query()->where('base_user_id', $this->user->base_user_id)->count(), 'the login is written');
    }

    public function testALoginFromANewIpIsTold(): void
    {
        $this->success('198.51.100.5', Carbon::now()->subDays(1));
        $this->loginFrom('203.0.113.22');
        $this->assertSame(1, $this->notices());

        $notice = NotifyNavbar::withoutGlobalScopes()->where('target_user_id', $this->user->base_user_id)->orderByDesc('id')->first();
        $this->assertNotNull($notice);
        $this->assertStringContainsString('203.0.113.22', strval($notice->notify_body));
        $this->assertStringContainsString('Chrome (Windows)', strval($notice->notify_body));

        // the same address again: known now
        $this->loginFrom('203.0.113.22');
        $this->assertSame(1, $this->notices());
    }

    public function testAKnownIpIsNotTold(): void
    {
        $this->success('203.0.113.23', Carbon::now()->subDays(10));
        $this->loginFrom('203.0.113.23');
        $this->assertSame(0, $this->notices());
    }

    public function testAnIpUsedLongAgoIsNew(): void
    {
        $this->success('203.0.113.24', Carbon::now()->subDays(LoginHistory::NEW_IP_DAYS + 5));
        $this->loginFrom('203.0.113.24');
        $this->assertSame(1, $this->notices());
    }

    public function testNothingIsToldWhenTheSettingIsOff(): void
    {
        System::login_notify_new_ip(false);
        System::clearCache();
        $this->success('198.51.100.6', Carbon::now()->subDays(1));
        $this->loginFrom('203.0.113.25');
        $this->assertSame(0, $this->notices());
        $this->assertSame(2, LoginLog::query()->where('base_user_id', $this->user->base_user_id)->count(), 'the login is written');
    }

    public function testTheHistoryHasOnlyTheOwnLogins(): void
    {
        $other = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        LoginLog::create(['base_user_id' => $other->base_user_id, 'result' => LoginLog::RESULT_SUCCESS, 'ip' => '192.0.2.99', 'created_at' => Carbon::now()]);
        $this->success('192.0.2.1', Carbon::now()->subMinutes(2));
        LoginLog::create(['base_user_id' => $this->user->base_user_id, 'result' => LoginLog::RESULT_FAILURE, 'failure_reason' => LoginLog::REASON_WRONG_PASSWORD, 'ip' => '192.0.2.2', 'created_at' => Carbon::now()->subMinute()]);

        $this->be($this->user);
        $mine = LoginHistory::mine();
        $this->assertSame(['192.0.2.2', '192.0.2.1'], $mine->pluck('ip')->all(), 'the newest first');
        foreach ($mine as $row) {
            $this->assertEquals($this->user->base_user_id, $row->base_user_id);
        }
        $this->assertCount(1, LoginHistory::mine(1));
    }
}
