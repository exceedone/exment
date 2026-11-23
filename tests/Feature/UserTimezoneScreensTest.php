<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\OperationLog;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\UserSetting;
use Exceedone\Exment\Services\UserLocale;
use Exceedone\Exment\Services\UserTimezone;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The time zone of the user on the lists of the notifications and of the operation logs (ISS02835).
 * A site in Asia/Tokyo and an administrator in America/New_York.
 */
class UserTimezoneScreensTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const SITE_TZ = 'Asia/Tokyo';
    protected const USER_TZ = 'America/New_York';

    /** @var array<string, mixed> */
    protected $configBefore = [];
    /** @var string */
    protected $phpTimezoneBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();

        $this->configBefore = [
            'exment.timezone' => config('exment.timezone'),
            'app.timezone' => config('app.timezone'),
        ];
        $this->phpTimezoneBefore = date_default_timezone_get();
        config(['exment.timezone' => static::SITE_TZ, 'app.timezone' => static::SITE_TZ]);
        date_default_timezone_set(static::SITE_TZ);

        $login = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->be($login);
        System::clearRequestSession();
    }

    protected function tearDown(): void
    {
        UserTimezone::setCurrent(null);
        date_default_timezone_set($this->phpTimezoneBefore);
        config($this->configBefore);
        UserLocale::clearCache();

        parent::tearDown();
    }

    public function testNotificationListShowsTheTimeOfTheUser(): void
    {
        $notify = new NotifyNavbar();
        $notify->notify_id = 1;
        $notify->target_user_id = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id;
        $notify->trigger_user_id = $notify->target_user_id;
        $notify->read_flg = false;
        $notify->notify_subject = 'timezone navbar ' . short_uuid();
        $notify->notify_body = 'timezone navbar body';
        // newer than the other notifications: on the first page, newest first
        $notify->created_at = Carbon::parse('2027-01-15 09:30:00', static::SITE_TZ);
        $notify->save();

        $this->get(admin_url('notify_navbar') . '?_sort[column]=created_at&_sort[type]=desc')->assertOk()->assertSee('2027-01-15 09:30:00');

        $this->setTimezone(static::USER_TZ);
        $this->get(admin_url('notify_navbar') . '?_sort[column]=created_at&_sort[type]=desc')->assertOk()
            ->assertSee('2027-01-14 19:30:00')
            ->assertDontSee('2027-01-15 09:30:00');
    }

    public function testOperationLogsShowTheTimeOfTheUser(): void
    {
        $log = new OperationLog();
        $log->user_id = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->id;
        $log->path = 'admin/timezone-test';
        $log->method = 'GET';
        $log->ip = '127.0.0.1';
        $log->input = '{}';
        $log->created_at = Carbon::parse('2026-01-15 09:30:00', static::SITE_TZ);
        $log->save();

        $this->get(admin_url('auth/logs'))->assertOk()->assertSee('2026-01-15 09:30:00');
        $this->get(admin_url('auth/logs/' . $log->id))->assertOk()->assertSee('2026-01-15 09:30:00');

        $this->setTimezone(static::USER_TZ);
        $this->get(admin_url('auth/logs'))->assertOk()
            ->assertSee('2026-01-14 19:30:00')
            ->assertDontSee('2026-01-15 09:30:00');
        $this->get(admin_url('auth/logs/' . $log->id))->assertOk()->assertSee('2026-01-14 19:30:00');
    }

    /**
     * The time zone of the login user (the user settings).
     */
    protected function setTimezone(string $timezone): void
    {
        $login = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        /** @var UserSetting $setting */
        $setting = UserSetting::firstOrCreate(['base_user_id' => $login->base_user_id]);
        $settings = $setting->settings ?? [];
        array_set($settings, UserTimezone::SETTING_KEY, $timezone);
        $setting->settings = $settings;
        $setting->save();
        UserLocale::clearCache();
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }
}
