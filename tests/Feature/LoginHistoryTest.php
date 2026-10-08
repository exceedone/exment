<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Auth\Events\Login;
use Illuminate\Testing\TestResponse;
use Exceedone\Exment\Console\ScheduleCommand;
use Exceedone\Exment\Controllers\LoginHistoryController;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\MailBodyType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemRoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginHistory;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Notifications\Mail\MailInfo;
use Exceedone\Exment\Services\DataImportExport\Providers\Export\LoginHistoryProvider;
use Exceedone\Exment\Services\GeoIp\GeoIpService;
use Exceedone\Exment\Services\Login\LoginHistoryService;
use Exceedone\Exment\Services\RefreshDataService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Login history: 1 record per successful login to the admin page, with IP address,
 * country / location (GeoIP) and a warning when the user logs in from an IP address
 * the user has not used. Listed on "login_history".
 *
 * This test creates its own users / histories and rolls everything back,
 * so it does not depend on `exment:inittest` data.
 * The GeoIP database file is optional: tests that need it are skipped when it is not placed.
 */
class LoginHistoryTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const PASSWORD = 'LoginHistory-2026!';
    protected const IP_JP = '133.242.0.1';
    protected const IP_US = '8.8.8.8';
    protected const IP_PRIVATE = '192.168.1.10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();

        // 2factor is off unless a test turns it on.
        config(['exment.login_use_2factor' => false]);
        // default setting: compare with the last 10 logins, notify system administrators on the bell icon, no mail.
        System::login_history_new_ip_count(LoginHistoryService::DEFAULT_NEW_IP_COUNT);
        System::login_history_notify_new_ip(true);
        System::login_history_notify_mail(false);
    }

    // ------------------------------------------------------------------ //
    //  Recording                                                          //
    // ------------------------------------------------------------------ //

    /**
     * Successful login from the login form: 1 history with who / from where.
     */
    public function testLoginRecordsHistory(): void
    {
        $login_user = $this->createLoginUser();

        $response = $this->postLogin($login_user, self::IP_JP, self::PASSWORD, 'Mozilla/5.0 LoginHistoryTest');

        $response->assertStatus(302);
        $this->assertTrue(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check(), 'precondition: login must succeed');

        $histories = $this->historiesOf($login_user);
        $this->assertCount(1, $histories);

        $history = $histories[0];
        $this->assertEquals($login_user->id, $history->login_user_id);
        $this->assertEquals($login_user->base_user_id, $history->base_user_id);
        $this->assertSame($login_user->user_code, $history->user_code);
        $this->assertSame($login_user->user_name, $history->user_name);
        $this->assertSame(LoginType::PURE, $history->login_type);
        $this->assertNull($history->login_provider);
        $this->assertSame(self::IP_JP, $history->ip_address);
        $this->assertSame('Mozilla/5.0 LoginHistoryTest', $history->user_agent);
        $this->assertFalse($history->is_new_ip, 'first login has nothing to compare with');
        $this->assertFalse($history->via_remember);
        $this->assertNull($history->auth_2factor_verified, '2factor is not used');
        $this->assertEquals($login_user->base_user_id, $history->created_user_id);
        $this->assertNotNull($history->created_at);
    }

    /**
     * Wrong password: nothing is recorded.
     */
    public function testFailedLoginRecordsNothing(): void
    {
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user, self::IP_JP, 'wrong-password');

        $this->assertFalse(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check());
        $this->assertCount(0, $this->historiesOf($login_user));
    }

    /**
     * Country and location are resolved from the IP address when the GeoIP database is placed.
     */
    public function testCountryAndLocationAreResolved(): void
    {
        if (!GeoIpService::isAvailable()) {
            $this->markTestSkipped('GeoIP database is not placed in this environment.');
        }

        $login_user = $this->createLoginUser();
        $this->postLogin($login_user, self::IP_JP);

        $history = $this->historiesOf($login_user)[0];
        $this->assertSame('JP', $history->country_code);
        $this->assertNotEmpty($history->country);
        $this->assertNotEmpty($history->location);
    }

    /**
     * GeoIP database is not placed: login succeeds and the history is recorded without country.
     */
    public function testLoginSucceedsWithoutGeoIpDatabase(): void
    {
        config(['exment.geoip_db_path' => storage_path('app/geoip/not_exists_' . short_uuid() . '.mmdb')]);
        $this->assertFalse(GeoIpService::isAvailable(), 'precondition: GeoIP must not be available');

        $login_user = $this->createLoginUser();
        $this->postLogin($login_user, self::IP_JP);

        $this->assertTrue(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check());
        $history = $this->historiesOf($login_user)[0];
        $this->assertSame(self::IP_JP, $history->ip_address);
        $this->assertNull($history->country_code);
        $this->assertNull($history->country);
        $this->assertNull($history->location);
    }

    /**
     * Private IP address (intranet): recorded without country.
     */
    public function testPrivateIpHasNoCountry(): void
    {
        $login_user = $this->createLoginUser();
        $this->postLogin($login_user, self::IP_PRIVATE);

        $history = $this->historiesOf($login_user)[0];
        $this->assertSame(self::IP_PRIVATE, $history->ip_address);
        $this->assertNull($history->country_code);
    }

    /**
     * "Auto login" means logged in by the "remember me" cookie.
     * Checking "remember me" on the login form is a normal login.
     */
    public function testRememberMeLogin(): void
    {
        $login_user = $this->createLoginUser();

        // login form with "remember me" checked
        $this->postLogin($login_user, self::IP_JP, self::PASSWORD, 'LoginHistoryTest', ['remember' => 1]);
        $this->assertTrue(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check(), 'precondition: login must succeed');

        $histories = $this->historiesOf($login_user);
        $this->assertCount(1, $histories);
        $this->assertFalse($histories[0]->via_remember, 'login form is not auto login');

        // session expired, only the "remember me" cookie remains
        $stored = \DB::table(SystemTableName::LOGIN_USER)->where('id', $login_user->id)->first();
        $this->assertNotNull($stored);
        $this->assertNotEmpty($stored->remember_token, 'precondition: remember token must be issued');
        $recallerName = \Auth::guard(Define::AUTHENTICATE_KEY_WEB)->getRecallerName();

        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->flushSession();

        $this->withServerVariables(['REMOTE_ADDR' => self::IP_JP])
            ->withCookie($recallerName, $login_user->id . '|' . $stored->remember_token . '|' . $stored->password)
            ->get(admin_url('auth/setting'))
            ->assertStatus(200);

        $histories = $this->historiesOf($login_user);
        $this->assertCount(2, $histories);
        $this->assertTrue($histories[1]->via_remember, 'login by the remember me cookie is auto login');
    }

    /**
     * Login of other guards (API) and login without session (console) are not recorded.
     */
    public function testOnlyBrowserLoginIsRecorded(): void
    {
        $login_user = $this->createLoginUser();

        LoginHistoryService::handleLogin(new Login(Define::AUTHENTICATE_KEY_API, $login_user, false));
        $this->assertCount(0, $this->historiesOf($login_user), 'api guard must not be recorded');

        $this->assertFalse(request()->hasSession(), 'precondition: no session');
        LoginHistoryService::handleLogin(new Login(Define::AUTHENTICATE_KEY_WEB, $login_user, false));
        $this->assertCount(0, $this->historiesOf($login_user), 'login without session must not be recorded');
    }

    // ------------------------------------------------------------------ //
    //  Warning: login from an IP address the user has not used            //
    // ------------------------------------------------------------------ //

    /**
     * 2nd login from another IP is warned. Once used, the IP is not warned again.
     */
    public function testLoginFromNewIpIsWarned(): void
    {
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user, self::IP_JP);
        $this->postLogin($login_user, self::IP_US);
        $this->postLogin($login_user, self::IP_US);
        $this->postLogin($login_user, self::IP_JP);

        $histories = $this->historiesOf($login_user);
        $this->assertCount(4, $histories);
        $this->assertSame(
            [[self::IP_JP, false], [self::IP_US, true], [self::IP_US, false], [self::IP_JP, false]],
            $histories->map(function ($history) {
                return [$history->ip_address, $history->is_new_ip];
            })->all()
        );
    }

    /**
     * Compared only with the user's own histories.
     */
    public function testOtherUsersHistoryIsNotCompared(): void
    {
        $userA = $this->createLoginUser();
        $userB = $this->createLoginUser();
        $this->createHistory($userA, ['ip_address' => self::IP_JP]);

        // user B has no history: nothing to compare with.
        $this->assertFalse(LoginHistoryService::isNewIp($userB->base_user_id, self::IP_US));
        // user A has: warned.
        $this->assertTrue(LoginHistoryService::isNewIp($userA->base_user_id, self::IP_US));
        $this->assertFalse(LoginHistoryService::isNewIp($userA->base_user_id, self::IP_JP));
    }

    /**
     * Compared with the IP addresses of the last N logins only (setting, default 10), not with all histories.
     */
    public function testOnlyRecentLoginsAreCompared(): void
    {
        $login_user = $this->createLoginUser();

        // 1 login from JP, then 10 logins from US: JP is no longer in the last 10 logins.
        $this->createHistory($login_user, ['ip_address' => self::IP_JP]);
        for ($i = 0; $i < LoginHistoryService::DEFAULT_NEW_IP_COUNT; $i++) {
            $this->createHistory($login_user, ['ip_address' => self::IP_US]);
        }

        $this->assertTrue(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_JP), 'JP was used 11 logins ago: out of the last 10');
        $this->assertFalse(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_US));

        // A larger setting value includes the JP login.
        System::login_history_new_ip_count(11);
        $this->assertFalse(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_JP));

        // Explicit count wins over the setting.
        $this->assertTrue(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_JP, 5));
        $this->assertFalse(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_JP, 20));

        // Invalid setting value falls back to the default.
        System::login_history_new_ip_count(0);
        $this->assertSame(LoginHistoryService::DEFAULT_NEW_IP_COUNT, LoginHistoryService::getNewIpCount());
        $this->assertTrue(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_JP));
    }

    /**
     * The same rule through the login form: the 12th login from the IP of the 1st login is warned.
     */
    public function testLoginFromIpOutOfRecentLoginsIsWarned(): void
    {
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user, self::IP_JP);
        for ($i = 0; $i < LoginHistoryService::DEFAULT_NEW_IP_COUNT; $i++) {
            $this->postLogin($login_user, self::IP_US);
        }
        $this->postLogin($login_user, self::IP_JP);

        $histories = $this->historiesOf($login_user);
        $this->assertCount(LoginHistoryService::DEFAULT_NEW_IP_COUNT + 2, $histories);
        $this->assertFalse($histories[0]->is_new_ip, '1st login: nothing to compare with');
        $this->assertTrue($histories[1]->is_new_ip, '2nd login: US is new');
        $this->assertFalse($histories[2]->is_new_ip, '3rd login: US was used');
        $this->assertTrue($histories->last()->is_new_ip, 'JP was not used in the last 10 logins');
    }

    /**
     * IPv6: the last 64 bits change frequently on the same device, so compared by the network prefix.
     */
    public function testIpv6IsComparedByNetworkPrefix(): void
    {
        $login_user = $this->createLoginUser();
        $this->createHistory($login_user, ['ip_address' => '2400:4050:1:2:aaaa:bbbb:cccc:dddd']);

        $this->assertFalse(LoginHistoryService::isNewIp($login_user->base_user_id, '2400:4050:1:2:1111:2222:3333:4444'), 'same /64 network');
        $this->assertTrue(LoginHistoryService::isNewIp($login_user->base_user_id, '2400:4050:1:3::1'), 'another network');
        $this->assertTrue(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_US));

        $this->assertSame('192.0.2.1', LoginHistoryService::getIpNetworkKey('::ffff:192.0.2.1'), 'IPv4-mapped IPv6 address is treated as IPv4');
        $this->assertSame('192.0.2.1', LoginHistoryService::getIpNetworkKey('192.0.2.1'));
        $this->assertSame('not-an-ip', LoginHistoryService::getIpNetworkKey('not-an-ip'));
    }

    // ------------------------------------------------------------------ //
    //  Notification to system administrators                              //
    // ------------------------------------------------------------------ //

    /**
     * Login from a new IP: each system administrator gets a notification (bell icon) with a link to the history.
     * Normal login: no notification.
     */
    public function testNewIpLoginNotifiesAdministrators(): void
    {
        $adminA = $this->createLoginUser();
        $adminB = $this->createLoginUser();
        System::system_admin_users([$adminA->base_user_id, $adminB->base_user_id]);

        $login_user = $this->createLoginUser();

        // 1st login: nothing to compare with -> no notification
        $this->postLogin($login_user, self::IP_JP);
        $this->assertCount(0, $this->notifiesTriggeredBy($login_user));

        // login from a new IP -> notified
        $this->postLogin($login_user, self::IP_US);
        $history = $this->historiesOf($login_user)[1];
        $this->assertTrue($history->is_new_ip, 'precondition: must be warned');

        $notifies = $this->notifiesTriggeredBy($login_user);
        $this->assertCount(2, $notifies);
        $this->assertEqualsCanonicalizing(
            [(int)$adminA->base_user_id, (int)$adminB->base_user_id],
            $notifies->map(function ($notify) {
                return (int)$notify->target_user_id;
            })->all()
        );

        $notify = $notifies[0];
        $this->assertTrue($notify->isLoginHistory());
        $this->assertEquals($history->id, $notify->parent_id);
        $this->assertFalse(boolval($notify->read_flg));
        $this->assertStringContainsString($login_user->user_name, $notify->notify_subject);
        $this->assertStringContainsString(self::IP_US, $notify->notify_body);
        $this->assertStringContainsString($login_user->user_code, $notify->notify_body);
        $this->assertStringContainsString('href="' . admin_urls('login_history', $history->id) . '"', $notify->notify_body);

        // same IP again: not warned -> no more notification
        $this->postLogin($login_user, self::IP_US);
        $this->assertCount(2, $this->notifiesTriggeredBy($login_user));
    }

    /**
     * Notification (bell icon and mail) can be turned off by the setting. The warning is still recorded and listed.
     */
    public function testNotificationCanBeTurnedOff(): void
    {
        \Notification::fake();

        $admin = $this->createLoginUser();
        System::system_admin_users([$admin->base_user_id]);
        System::login_history_notify_new_ip(false);
        System::login_history_notify_mail(true);

        $login_user = $this->createLoginUser();
        $this->postLogin($login_user, self::IP_JP);
        $this->postLogin($login_user, self::IP_US);

        $this->assertTrue($this->historiesOf($login_user)[1]->is_new_ip, 'the warning is still recorded');
        $this->assertCount(0, $this->notifiesTriggeredBy($login_user), 'no notification on the bell icon');
        \Notification::assertNothingSent();

        // turned on again: notified
        System::login_history_notify_new_ip(true);
        $this->postLogin($login_user, '198.51.100.55');
        $this->assertCount(1, $this->notifiesTriggeredBy($login_user));
        \Notification::assertCount(1);
    }

    /**
     * The warning is distinguished from normal notifications on the bell icon (warning icon, red),
     * and the notification pages link to the login history.
     */
    public function testWarningNotificationIsDistinguishedAndLinked(): void
    {
        $admin = $this->createLoginUser();
        System::system_admin_users([$admin->base_user_id]);

        $login_user = $this->createLoginUser();
        $history = $this->createHistory($login_user, ['ip_address' => self::IP_US, 'is_new_ip' => true]);
        LoginHistoryService::notifyNewIp($history);
        $warning = $this->notifiesTriggeredBy($login_user)[0];

        // a normal notification of the same administrator, to compare with
        $normal = new NotifyNavbar();
        $normal->notify_id = 0;
        $normal->target_user_id = $admin->base_user_id;
        $normal->trigger_user_id = $login_user->base_user_id;
        $normal->notify_subject = 'lh_normal_' . short_uuid();
        $normal->notify_body = 'normal';
        $normal->save();

        $this->loginAs($admin);

        // bell icon
        $items = [];
        foreach ((array)$this->get(admin_urls('webapi', 'notifyPage'))->assertStatus(200)->json('items') as $item) {
            $items[$item['id']] = $item;
        }
        $this->assertSame('fa-exclamation-triangle', $items[$warning->id]['icon']);
        $this->assertNotEmpty($items[$warning->id]['color']);
        $this->assertSame(exmtrans('login_history.header'), $items[$warning->id]['table_view_name']);
        $this->assertSame('fa-bell', $items[$normal->id]['icon']);
        $this->assertNull($items[$normal->id]['color']);

        // notification list: the target is shown as "login history", and the target table filter offers it
        $content = (string)$this->get(admin_url('notify_navbar'))->assertStatus(200)->getContent();
        $this->assertStringContainsString(exmtrans('login_history.header'), $content);
        $this->assertSame(exmtrans('login_history.header'), array_get(NotifyNavbar::getTargetTableOptions(), SystemTableName::LOGIN_HISTORY));

        // notification detail: has the button to the linked data
        $content = (string)$this->get(admin_urls('notify_navbar', $warning->id))->assertStatus(200)->getContent();
        $this->assertStringContainsString(admin_url("notify_navbar/rowdetail/{$warning->id}"), $content);

        // "show the linked data" redirects to the login history, and marks as read
        $this->get(admin_url("notify_navbar/rowdetail/{$warning->id}"))
            ->assertRedirect(admin_urls('login_history', $history->id));
        $this->assertTrue(boolval(NotifyNavbar::withoutGlobalScopes()->where('id', $warning->id)->value('read_flg')));
    }

    /**
     * The linked login history was deleted (manually, or by the auto-delete) after the warning was notified.
     * Every way to it (the link in the notification body and mail, "show the linked data", a bookmark) ends at the
     * login history page, which goes to the list with the "data not found" message instead of an error page.
     * The notification detail hides the button to the data, same as a notification whose custom value was deleted.
     */
    public function testWarningNotificationWhenHistoryIsDeleted(): void
    {
        $admin = $this->createLoginUser();
        System::system_admin_users([$admin->base_user_id]);

        $login_user = $this->createLoginUser();
        $history = $this->createHistory($login_user, ['ip_address' => self::IP_US, 'is_new_ip' => true]);
        LoginHistoryService::notifyNewIp($history);
        $warning = $this->notifiesTriggeredBy($login_user)[0];

        $this->loginAs($admin);

        // before deleting: the detail page has the button to the linked data
        $content = (string)$this->get(admin_urls('notify_navbar', $warning->id))->assertStatus(200)->getContent();
        $this->assertStringContainsString(admin_url("notify_navbar/rowdetail/{$warning->id}"), $content);

        LoginHistory::destroy($history->id);

        // the notification is kept and still says it is a login history, but without the button
        $content = (string)$this->get(admin_urls('notify_navbar', $warning->id))->assertStatus(200)->getContent();
        $this->assertStringContainsString(exmtrans('login_history.header'), $content);
        $this->assertStringNotContainsString(admin_url("notify_navbar/rowdetail/{$warning->id}"), $content);

        // the link in the notification body (and in the mail): to the list with an error toastr, not an error page
        $this->get(admin_urls('login_history', $history->id))
            ->assertRedirect(admin_url('login_history'))
            ->assertSessionHas('toastr');
        $toastr = app('session.store')->get('toastr');
        $this->assertSame('error', $toastr->first('type'));
        $this->assertSame(exmtrans('common.message.notfound'), $toastr->first('message'));

        // "show the linked data" (the icon on the notification list) goes the same way
        $this->get(admin_url("notify_navbar/rowdetail/{$warning->id}"))
            ->assertRedirect(admin_urls('login_history', $history->id));
    }

    /**
     * A custom table may be named "login_histories" too (the name is not reserved). The warning is judged by
     * isLoginHistory() before looking for a custom table of the parent type, so such a table never turns the warning
     * into a normal notification of that table (bell icon, list, detail, "show the linked data").
     */
    public function testWarningIsNotMistakenForCustomTableOfSameName(): void
    {
        $admin = $this->createLoginUser();
        System::system_admin_users([$admin->base_user_id]);

        $login_user = $this->createLoginUser();
        $history = $this->createHistory($login_user, ['ip_address' => self::IP_US, 'is_new_ip' => true]);
        LoginHistoryService::notifyNewIp($history);
        $warning = $this->notifiesTriggeredBy($login_user)[0];

        // A custom table with the same table_name as the system table. Only the definition row is created, not the data
        // table "exm__login_histories": looking for a custom value of the warning in it would be a database error.
        $table_view_name = 'lh_custom_' . short_uuid();
        \DB::table('custom_tables')->insert([
            'suuid' => short_uuid(),
            'table_name' => SystemTableName::LOGIN_HISTORY,
            'table_view_name' => $table_view_name,
            'options' => json_encode(['icon' => 'fa-table', 'color' => '#00ff00']),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        System::clearCache();
        $this->assertNotNull(CustomTable::getEloquent(SystemTableName::LOGIN_HISTORY), 'precondition: the custom table must be found by the name');

        $this->loginAs($admin);

        // bell icon: still the warning, not the icon and the name of the custom table
        $items = [];
        foreach ((array)$this->get(admin_urls('webapi', 'notifyPage'))->assertStatus(200)->json('items') as $item) {
            $items[$item['id']] = $item;
        }
        $this->assertSame('fa-exclamation-triangle', $items[$warning->id]['icon']);
        $this->assertSame('#dd4b39', $items[$warning->id]['color']);
        $this->assertSame(exmtrans('login_history.header'), $items[$warning->id]['table_view_name']);

        // notification list (rows and the target table filter) and detail: shown as a login history, with the button to it
        $content = (string)$this->get(admin_url('notify_navbar'))->assertStatus(200)->getContent();
        $this->assertStringContainsString(exmtrans('login_history.header'), $content);
        $this->assertStringNotContainsString($table_view_name, $content);
        $this->assertSame(exmtrans('login_history.header'), array_get(NotifyNavbar::getTargetTableOptions(), SystemTableName::LOGIN_HISTORY));

        $content = (string)$this->get(admin_urls('notify_navbar', $warning->id))->assertStatus(200)->getContent();
        $this->assertStringContainsString(exmtrans('login_history.header'), $content);
        $this->assertStringNotContainsString($table_view_name, $content);
        $this->assertStringContainsString(admin_url("notify_navbar/rowdetail/{$warning->id}"), $content);

        // "show the linked data" goes to the login history
        $this->get(admin_url("notify_navbar/rowdetail/{$warning->id}"))
            ->assertRedirect(admin_urls('login_history', $history->id));
    }

    /**
     * The notification body is displayed as HTML, so values the user can set are escaped.
     */
    public function testNotifyBodyIsEscaped(): void
    {
        $admin = $this->createLoginUser();
        System::system_admin_users([$admin->base_user_id]);

        $login_user = $this->createLoginUser();
        $history = $this->createHistory($login_user, [
            'ip_address' => self::IP_US,
            'user_name' => '<b>lh_xss_name</b>',
            'city' => '<script>alert(1)</script>',
            'is_new_ip' => true,
        ]);

        LoginHistoryService::notifyNewIp($history);

        $notifies = $this->notifiesTriggeredBy($login_user);
        $this->assertCount(1, $notifies);
        $body = $notifies[0]->notify_body;
        $this->assertStringNotContainsString('<b>lh_xss_name</b>', $body);
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('lh_xss_name', $body);
    }

    /**
     * Mail is sent to system administrators only when the setting is on.
     */
    public function testNotifyMailIsSentOnlyWhenEnabled(): void
    {
        \Notification::fake();

        $admin = $this->createLoginUser();
        System::system_admin_users([$admin->base_user_id]);

        $login_user = $this->createLoginUser();
        $this->postLogin($login_user, self::IP_JP);

        // setting off (default): only the bell notification
        System::login_history_notify_mail(false);
        $this->postLogin($login_user, self::IP_US);
        $this->assertCount(1, $this->notifiesTriggeredBy($login_user));
        \Notification::assertNothingSent();

        // setting on: mail to the administrator
        System::login_history_notify_mail(true);
        $this->postLogin($login_user, '198.51.100.55');
        $this->assertCount(2, $this->notifiesTriggeredBy($login_user));
        \Notification::assertCount(1);
        \Notification::assertSentTimes(MailSendJob::class, 1);

        $mailInfo = $this->sentMailInfo();
        $this->assertSame([$admin->email], array_values($mailInfo->getTo()));
        $this->assertStringContainsString($login_user->user_name, (string)$mailInfo->getSubject());
        $this->assertStringContainsString('198.51.100.55', (string)$mailInfo->getBody());
        $this->assertStringContainsString(admin_urls('login_history', $this->historiesOf($login_user)[2]->id), (string)$mailInfo->getBody());

        // normal login: no more mail
        $this->postLogin($login_user, '198.51.100.55');
        \Notification::assertCount(1);
    }

    /**
     * A value like "${...}" in the user name is not processed as a mail format.
     */
    public function testNotifyMailDoesNotProcessUserValueAsFormat(): void
    {
        \Notification::fake();

        $admin = $this->createLoginUser();
        System::system_admin_users([$admin->base_user_id]);

        $login_user = $this->createLoginUser();
        $history = $this->createHistory($login_user, [
            'ip_address' => self::IP_US,
            'user_name' => 'lh_${system:site_name}_name',
            'is_new_ip' => true,
        ]);

        LoginHistoryService::sendNotifyMail($history);

        $mailInfo = $this->sentMailInfo();
        $this->assertStringContainsString('lh_$ {system:site_name}_name', (string)$mailInfo->getSubject());
        $this->assertStringContainsString('lh_$ {system:site_name}_name', (string)$mailInfo->getBody());
    }

    /**
     * The mail body follows the system mail body type:
     * HTML: values are escaped and the link is an anchor. Plain text: values as they are and the url as text.
     */
    public function testNotifyMailBodyFollowsMailBodyType(): void
    {
        \Notification::fake();

        $admin = $this->createLoginUser();
        System::system_admin_users([$admin->base_user_id]);

        $login_user = $this->createLoginUser();
        $history = $this->createHistory($login_user, [
            'ip_address' => self::IP_US,
            'user_name' => 'lh_mail <name>',
            'is_new_ip' => true,
        ]);
        $url = admin_urls('login_history', $history->id);

        // html (default)
        System::system_mail_body_type(MailBodyType::HTML);
        LoginHistoryService::sendNotifyMail($history);
        $body = (string)$this->sentMailInfo()->getBody();
        $this->assertStringContainsString('<a href="' . $url . '">', $body);
        $this->assertStringContainsString('lh_mail &lt;name&gt;', $body);
        $this->assertStringNotContainsString('lh_mail <name>', $body);

        // plain text
        \Notification::fake();
        System::system_mail_body_type(MailBodyType::PLAIN);
        LoginHistoryService::sendNotifyMail($history);
        $body = (string)$this->sentMailInfo()->getBody();
        $this->assertStringNotContainsString('<a href', $body);
        $this->assertStringContainsString($url, $body);
        $this->assertStringContainsString('lh_mail <name>', $body);
        $this->assertStringContainsString(self::IP_US, $body);
    }

    /**
     * No system administrator has a mail address: nothing is sent, and no error.
     */
    public function testNotifyMailWithoutAddress(): void
    {
        \Notification::fake();
        System::system_admin_users([]);

        $login_user = $this->createLoginUser();
        $history = $this->createHistory($login_user, ['ip_address' => self::IP_US, 'is_new_ip' => true]);

        LoginHistoryService::notifyNewIp($history);
        LoginHistoryService::sendNotifyMail($history);

        $this->assertCount(0, $this->notifiesTriggeredBy($login_user));
        \Notification::assertNothingSent();
    }

    // ------------------------------------------------------------------ //
    //  2factor                                                            //
    // ------------------------------------------------------------------ //

    /**
     * 2factor is used: recorded as "not verified" at login, and "verified" after passing 2factor.
     */
    public function test2factorStatusIsRecorded(): void
    {
        $this->enable2factor();
        $login_user = $this->createLoginUser();

        $history = LoginHistoryService::record($login_user);
        $this->assertNotNull($history);
        $this->assertFalse($history->fresh()->auth_2factor_verified);

        $this->loginAs($login_user);
        session([Define::SYSTEM_KEY_SESSION_LOGIN_HISTORY_ID => $history->id]);
        LoginHistoryService::verified2factor();

        $this->assertTrue($history->fresh()->auth_2factor_verified);
    }

    /**
     * The whole 2factor flow through the browser (email provider): the history is recorded as "not verified" when the
     * password is accepted, its id survives the session regeneration of the login response, and the history becomes
     * "verified" when the one-time password is accepted. Until then, the IP is not a "used" IP.
     */
    public function test2factorIsVerifiedThroughLoginFlow(): void
    {
        \Notification::fake();
        $this->enable2factor();
        System::login_2factor_provider(Login2FactorProviderType::EMAIL);
        // the one-time password form is throttled per IP by a cache that outlives the test: not throttled here
        config(['exment.throttle' => false]);

        $login_user = $this->createLoginUser();

        // 1. password accepted: "not verified", the one-time password is issued, and the admin page asks for it
        $this->postLogin($login_user, self::IP_JP)->assertStatus(302);
        $this->assertTrue(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check(), 'precondition: password login must succeed');

        $history = $this->historiesOf($login_user)[0];
        $this->assertFalse($history->auth_2factor_verified);
        $this->assertEquals($history->id, session(Define::SYSTEM_KEY_SESSION_LOGIN_HISTORY_ID), 'the id must survive session regeneration');
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));
        $this->assertFalse(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_US), 'not verified: nothing to compare with');

        $code = \DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('login_user_id', $login_user->id)
            ->where('verify_type', '2factor_' . Login2FactorProviderType::EMAIL)
            ->value('verify_code');
        $this->assertNotNull($code, 'precondition: the one-time password must be issued');

        // 2. wrong one-time password (the issued one is 6 digits from 100000): still not verified
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post(admin_url('auth-2factor/verify'), ['verify_code' => '000000'])
            ->assertSessionHasErrors('verify_code');
        $this->assertFalse($history->fresh()->auth_2factor_verified);

        // 3. correct one-time password: verified, the admin page opens, and the IP is a used IP from now on
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post(admin_url('auth-2factor/verify'), ['verify_code' => $code])
            ->assertRedirect(admin_url(''));
        $this->assertTrue($history->fresh()->auth_2factor_verified);
        $this->get(admin_url('auth/setting'))->assertStatus(200);
        $this->assertFalse(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_JP));
        $this->assertTrue(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_US));
    }

    /**
     * The history of another user is never marked as verified, even if its id is in the session.
     */
    public function test2factorOfOtherUserIsNotVerified(): void
    {
        $this->enable2factor();
        $login_user = $this->createLoginUser();
        $otherUser = $this->createLoginUser();

        $history = LoginHistoryService::record($otherUser);
        $this->assertNotNull($history);

        $this->loginAs($login_user);
        session([Define::SYSTEM_KEY_SESSION_LOGIN_HISTORY_ID => $history->id]);
        LoginHistoryService::verified2factor();

        $this->assertFalse($history->fresh()->auth_2factor_verified);
    }

    /**
     * A login that did not pass 2factor is not treated as "the user used this IP".
     */
    public function testUnverified2factorHistoryIsNotTreatedAsUsedIp(): void
    {
        $login_user = $this->createLoginUser();
        $this->createHistory($login_user, ['ip_address' => self::IP_JP]);
        $unverified = $this->createHistory($login_user, ['ip_address' => self::IP_US, 'auth_2factor_verified' => false]);

        $this->assertTrue(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_US));

        $unverified->auth_2factor_verified = true;
        $unverified->save();
        $this->assertFalse(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_US));
    }

    /**
     * OAuth / SAML logins are not subject to 2factor: the identity provider authenticates the user and no OTP is asked
     * (AuthOAuthController / AuthSamlController mark the session as verified right after login, nothing calls verified2factor()).
     * So their histories are "not applicable" (null), not "not verified" (false), and count as "the user used this IP".
     * The default login and LDAP must pass 2factor.
     */
    public function testSsoLoginIsNotSubjectTo2factor(): void
    {
        $this->enable2factor();

        foreach ([LoginType::OAUTH => 'google', LoginType::SAML => 'azure'] as $login_type => $provider) {
            $login_user = $this->createLoginUser($login_type, $provider);

            $history = $this->recordFrom($login_user, self::IP_JP);
            session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);

            $this->assertNull($history->fresh()->auth_2factor_verified, "{$login_type}: not subject to 2factor");
            $this->assertFalse(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_JP), "{$login_type}: the IP of the SSO login is a used IP");
            $this->assertTrue(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_US), "{$login_type}: another IP is warned");

            // the next login from the same IP is not warned
            $this->assertFalse($this->recordFrom($login_user, self::IP_JP)->is_new_ip, "{$login_type}: same IP again");
        }

        foreach ([LoginType::PURE => null, LoginType::LDAP => 'ldap'] as $login_type => $provider) {
            $login_user = $this->createLoginUser($login_type, $provider);

            $history = $this->recordFrom($login_user, self::IP_JP);

            $this->assertFalse($history->fresh()->auth_2factor_verified, "{$login_type}: 2factor is required");
            $this->assertFalse(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_US), "{$login_type}: not verified yet, so nothing to compare with");
        }

        $this->assertTrue(LoginHistoryService::requires2factor(LoginType::PURE));
        $this->assertTrue(LoginHistoryService::requires2factor(LoginType::LDAP));
        $this->assertFalse(LoginHistoryService::requires2factor(LoginType::OAUTH));
        $this->assertFalse(LoginHistoryService::requires2factor(LoginType::SAML));

        // 2factor off: nobody is subject to it
        config(['exment.login_use_2factor' => false]);
        $this->assertFalse(LoginHistoryService::requires2factor(LoginType::PURE));
    }

    // ------------------------------------------------------------------ //
    //  Screen                                                             //
    // ------------------------------------------------------------------ //

    /**
     * Administrator sees the list. Values sent from the browser are escaped.
     */
    public function testIndexShowsHistories(): void
    {
        $login_user = $this->createLoginUser();
        $history = $this->createHistory($login_user, [
            'ip_address' => self::IP_US,
            'user_name' => '<b>lh_xss_name</b>',
            'country_code' => 'US',
            'country' => 'United States',
            'region' => 'California',
            'city' => '<i>lh_xss_city</i>',
            'is_new_ip' => true,
        ]);

        $this->loginAsAdmin();
        $response = $this->get(admin_url('login_history'));

        $response->assertStatus(200);
        $content = (string)$response->getContent();
        $this->assertStringContainsString(self::IP_US, $content);
        $this->assertStringContainsString($history->user_code, $content);
        $this->assertStringContainsString('United States (US)', $content);
        $this->assertStringContainsString(exmtrans('login_history.is_new_ip_options.1'), $content);
        $this->assertStringNotContainsString('<b>lh_xss_name</b>', $content, 'user name must be escaped');
        $this->assertStringNotContainsString('<i>lh_xss_city</i>', $content, 'city must be escaped');
        $this->assertStringContainsString('lh_xss_name', $content);
        $this->assertStringContainsString('lh_xss_city', $content);

        // Same look as the operation log: checkbox for batch delete, show / delete per row, no edit.
        // (The checkbox and the delete link carry data-id, which also makes the whole row clickable.)
        $this->assertStringContainsString('grid-row-checkbox', $content);
        $this->assertStringContainsString('data-id="' . $history->id . '"', $content);
        $this->assertStringContainsString(admin_urls('login_history', $history->id) . '"', $content, 'show link');
        $this->assertStringNotContainsString(admin_urls('login_history', $history->id, 'edit'), $content, 'no edit link');
    }

    /**
     * Filter: by user code, only warned / only normal histories, by country.
     */
    public function testIndexFilter(): void
    {
        $userA = $this->createLoginUser();
        $userB = $this->createLoginUser();
        $this->createHistory($userA, ['ip_address' => '198.51.100.11', 'is_new_ip' => false, 'country_code' => 'JP', 'country' => 'Japan']);
        $this->createHistory($userA, ['ip_address' => '198.51.100.22', 'is_new_ip' => true, 'country_code' => 'US', 'country' => 'United States']);
        $this->createHistory($userB, ['ip_address' => '198.51.100.33', 'is_new_ip' => false, 'country_code' => 'JP', 'country' => 'Japan']);

        $this->loginAsAdmin();

        // by user code
        $content = $this->indexContent(['user_code' => $userA->user_code]);
        $this->assertStringContainsString('198.51.100.11', $content);
        $this->assertStringContainsString('198.51.100.22', $content);
        $this->assertStringNotContainsString('198.51.100.33', $content);

        // only warned
        $content = $this->indexContent(['user_code' => $userA->user_code, 'is_new_ip' => 1]);
        $this->assertStringNotContainsString('198.51.100.11', $content);
        $this->assertStringContainsString('198.51.100.22', $content);

        // only normal ("0" must not be taken as "no filter")
        $content = $this->indexContent(['user_code' => $userA->user_code, 'is_new_ip' => 0]);
        $this->assertStringContainsString('198.51.100.11', $content);
        $this->assertStringNotContainsString('198.51.100.22', $content);

        // by country (the options of this filter come from getCountryOptions())
        $content = $this->indexContent(['country_code' => 'US']);
        $this->assertStringNotContainsString('198.51.100.11', $content);
        $this->assertStringContainsString('198.51.100.22', $content);
        $this->assertStringNotContainsString('198.51.100.33', $content);
    }

    /**
     * The country filter offers the countries found in the histories: 1 option per country code, named like the list,
     * sorted by name. Histories whose country is unknown (private IP, no GeoIP database) give no option.
     */
    public function testCountryOptionsForFilter(): void
    {
        $login_user = $this->createLoginUser();
        $this->createHistory($login_user, ['ip_address' => '198.51.100.11', 'country_code' => 'US', 'country' => 'United States']);
        $this->createHistory($login_user, ['ip_address' => '198.51.100.12', 'country_code' => 'US', 'country' => 'United States']);
        $this->createHistory($login_user, ['ip_address' => '198.51.100.13', 'country_code' => 'JP', 'country' => 'Japan']);
        $this->createHistory($login_user, ['ip_address' => '198.51.100.14', 'country_code' => 'ZZ']);
        $this->createHistory($login_user, ['ip_address' => self::IP_PRIVATE]);

        $options = LoginHistoryController::getCountryOptions();

        $this->assertSame('Japan (JP)', $options['JP']);
        $this->assertSame('United States (US)', $options['US']);
        $this->assertSame('ZZ', $options['ZZ'], 'country code without name');
        $this->assertSame(1, count(array_keys($options, 'United States (US)')), '1 option per country');
        $this->assertArrayNotHasKey('', $options, 'unknown country is not an option');

        $sorted = array_values($options);
        sort($sorted);
        $this->assertSame($sorted, array_values($options), 'sorted by name');
    }

    /**
     * Detail page shows the browser information, escaped.
     */
    public function testShowDetail(): void
    {
        $login_user = $this->createLoginUser();
        $history = $this->createHistory($login_user, [
            'ip_address' => self::IP_US,
            'user_agent' => 'lh_agent<script>alert(1)</script>',
        ]);

        $this->loginAsAdmin();
        $response = $this->get(admin_urls('login_history', $history->id));

        $response->assertStatus(200);
        $content = (string)$response->getContent();
        $this->assertStringContainsString(self::IP_US, $content);
        $this->assertStringContainsString('lh_agent', $content);
        $this->assertStringNotContainsString('lh_agent<script>', $content, 'user agent must be escaped');
    }

    /**
     * A user without permission cannot see the list. The administrator can.
     */
    public function testUserWithoutPermissionCannotAccess(): void
    {
        if (!System::permission_available()) {
            $this->markTestSkipped('permission is not available in this environment.');
        }

        // (The "permission denied" page calls exit(), so the permission is checked without sending a request.)
        $login_user = $this->createLoginUser();
        $this->loginAs($login_user);
        foreach (['login_history', 'login_history/1', 'login_history/setting'] as $endpoint) {
            $this->assertFalse($login_user->visible($endpoint), "{$endpoint} must be denied");
        }

        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertInstanceOf(LoginUser::class, $admin);
        $this->loginAs($admin);
        foreach (['login_history', 'login_history/1', 'login_history/setting'] as $endpoint) {
            $this->assertTrue($admin->visible($endpoint), "{$endpoint} must be allowed for administrator");
        }
    }

    /**
     * Same permission as the operation log: the "login user" permission of a role group (to give or reset passwords)
     * is not enough, because the list shows the IP address and browser of every user, and has delete and system-level
     * settings. Only the system administrator permission opens the login history.
     */
    public function testLoginUserPermissionIsNotEnough(): void
    {
        if (!System::permission_available()) {
            $this->markTestSkipped('permission is not available in this environment.');
        }

        $login_user = $this->createLoginUser();
        $this->grantSystemPermission($login_user, [Permission::LOGIN_USER]);
        $this->loginAs($login_user);

        $this->assertTrue($login_user->visible('loginuser'), 'precondition: the role group must give the login user permission');
        foreach (['login_history', 'login_history/1', 'login_history/setting'] as $endpoint) {
            $this->assertFalse($login_user->visible($endpoint), "{$endpoint} must be denied with the login user permission only");
        }
        $this->assertFalse($login_user->visible('auth/logs'), 'same as the operation log');

        // the system administrator permission of a role group opens it
        $admin_by_role = $this->createLoginUser();
        $this->grantSystemPermission($admin_by_role, [Permission::SYSTEM]);
        $this->loginAs($admin_by_role);
        foreach (['login_history', 'login_history/1', 'login_history/setting', 'auth/logs'] as $endpoint) {
            $this->assertTrue($admin_by_role->visible($endpoint), "{$endpoint} must be allowed with the system permission");
        }
    }

    /**
     * Same as the operation log: a history can be deleted from the row, and several at once by the checkboxes.
     * No route for editing.
     */
    public function testHistoryCanBeDeletedFromScreen(): void
    {
        $login_user = $this->createLoginUser();
        $histories = [
            $this->createHistory($login_user, ['ip_address' => self::IP_US]),
            $this->createHistory($login_user, ['ip_address' => self::IP_JP]),
            $this->createHistory($login_user, ['ip_address' => self::IP_PRIVATE]),
        ];
        $kept = $this->createHistory($login_user, ['ip_address' => '198.51.100.55']);

        $this->loginAsAdmin();

        // single
        $response = $this->withoutMiddleware(VerifyCsrfToken::class)->delete(admin_urls('login_history', $histories[0]->id));
        $response->assertOk()->assertJson(['status' => true]);
        $this->assertNull(LoginHistory::find($histories[0]->id));

        // batch: ids joined by ","
        $response = $this->withoutMiddleware(VerifyCsrfToken::class)->delete(admin_urls('login_history', "{$histories[1]->id},{$histories[2]->id}"));
        $response->assertOk()->assertJson(['status' => true]);
        $this->assertNull(LoginHistory::find($histories[1]->id));
        $this->assertNull(LoginHistory::find($histories[2]->id));
        $this->assertNotNull(LoginHistory::find($kept->id), 'other histories are kept');

        // nothing to delete
        $response = $this->withoutMiddleware(VerifyCsrfToken::class)->delete(admin_urls('login_history', 'abc'));
        $response->assertOk()->assertJson(['status' => false]);
        $this->assertNotNull(LoginHistory::find($kept->id));

        // no route for creating or editing
        $writeRoutes = collect(\Route::getRoutes()->getRoutes())->filter(function ($route) {
            return str_contains($route->uri(), 'login_history')
                && (str_ends_with($route->uri(), '/edit') || str_ends_with($route->uri(), '/create')
                    || count(array_intersect(['PUT', 'PATCH'], $route->methods())) > 0);
        });
        $this->assertCount(0, $writeRoutes, 'no create / edit / update route');
    }

    /**
     * Export: header 2 rows + data. A value starting with "=" is not exported as a formula.
     */
    public function testExportData(): void
    {
        $login_user = $this->createLoginUser();
        $this->createHistory($login_user, [
            'ip_address' => self::IP_US,
            'country_code' => 'US',
            'user_agent' => '=cmd|calc',
            'is_new_ip' => true,
        ]);

        $this->loginAsAdmin();
        $request = request();
        $request->query->set('user_code', $login_user->user_code);

        $controller = new LoginHistoryController();
        $method = new \ReflectionMethod($controller, 'grid');
        $method->setAccessible(true);
        $provider = new LoginHistoryProvider(['grid' => $method->invoke($controller)]);

        $outputs = $provider->data();
        $this->assertCount(3, $outputs, '2 header rows + 1 data row');
        $this->assertSame(count($outputs[0]), count($outputs[1]));
        $this->assertSame(count($outputs[0]), count($outputs[2]));

        $row = array_combine($outputs[0], $outputs[2]);
        $this->assertSame($login_user->user_code, $row['user_code']);
        $this->assertSame(self::IP_US, $row['ip_address']);
        $this->assertSame('US', $row['country_code']);
        $this->assertSame(1, $row['is_new_ip']);
        $this->assertNull($row['auth_2factor_verified']);
        $this->assertSame("'=cmd|calc", $row['user_agent']);
    }

    /**
     * The menu "login history" can be chosen in the menu setting, in both locales.
     */
    public function testMenuDefinition(): void
    {
        $this->assertSame('login_history', array_get(Define::MENU_SYSTEM_DEFINITION, 'login_history.uri'));

        foreach (['ja', 'en'] as $locale) {
            $key = 'exment::exment.menu.system_definitions.login_history';
            $this->assertNotSame($key, trans($key, [], $locale));
        }
    }

    /**
     * "Delete all transaction data" (exment:refresh-data) truncates the login histories too, like the operation log.
     * (Only the table list is checked: the truncate itself would wipe the database.)
     */
    public function testRefreshDataTruncatesLoginHistories(): void
    {
        $tables = RefreshDataService::getTruncateSystemTables();

        $this->assertContains(SystemTableName::LOGIN_HISTORY, $tables);
        $this->assertContains('admin_operation_log', $tables, 'the other tables are kept');
        $this->assertContains('notify_navbars', $tables, 'the other tables are kept');
    }

    // ------------------------------------------------------------------ //
    //  Setting                                                            //
    // ------------------------------------------------------------------ //

    public function testPostSettingSaves(): void
    {
        $this->loginAsAdmin();

        $response = $this->postSetting([
            'login_history_new_ip_count' => '5',
            'login_history_notify_new_ip' => '1',
            'login_history_notify_mail' => '1',
            'login_history_enable_automatic' => '1',
            'login_history_keep_days' => '30',
            'login_history_geoip_auto_update' => '1',
        ]);

        $response->assertRedirect(admin_url('login_history'));
        $this->assertSame(5, (int)System::login_history_new_ip_count());
        $this->assertTrue(boolval(System::login_history_notify_new_ip()));
        $this->assertTrue(boolval(System::login_history_notify_mail()));
        $this->assertTrue(boolval(System::login_history_enable_automatic()));
        $this->assertSame(30, (int)System::login_history_keep_days());
        $this->assertTrue(boolval(System::login_history_geoip_auto_update()));
    }

    /**
     * Number of recent logins to compare: 1 to 1000. Other values are not saved, redirect back with input.
     */
    public function testPostSettingInvalidNewIpCountIsNotSaved(): void
    {
        $this->loginAsAdmin();
        System::login_history_new_ip_count(10);
        System::login_history_notify_new_ip(true);

        foreach (['0', 'abc', '-1', '1001', '1.5'] as $count) {
            $response = $this->postSetting([
                'login_history_new_ip_count' => $count,
                'login_history_notify_new_ip' => '1',
            ]);

            $response->assertRedirect(admin_url('login_history'));
            $response->assertSessionHasInput('login_history_new_ip_count');
            $this->assertSame(10, (int)System::login_history_new_ip_count(), "count '{$count}' must not be saved");
        }

        // Empty keeps the saved value; the switches are saved.
        $response = $this->postSetting([
            'login_history_new_ip_count' => '',
        ]);
        $response->assertRedirect(admin_url('login_history'));
        $this->assertSame(10, (int)System::login_history_new_ip_count());
        $this->assertFalse(boolval(System::login_history_notify_new_ip()));

        $response = $this->postSetting([
            'login_history_new_ip_count' => '1000',
        ]);
        $response->assertRedirect(admin_url('login_history'));
        $this->assertSame(1000, (int)System::login_history_new_ip_count());
    }

    /**
     * Auto-delete enabled with keep days 0 / empty: not saved, redirect back with input.
     */
    public function testPostSettingInvalidKeepDaysIsNotSaved(): void
    {
        $this->loginAsAdmin();
        System::login_history_enable_automatic(false);
        System::login_history_keep_days(60);

        foreach (['0', '', 'abc', '-1'] as $keepDays) {
            $response = $this->postSetting([
                'login_history_enable_automatic' => '1',
                'login_history_keep_days' => $keepDays,
            ]);

            $response->assertRedirect(admin_url('login_history'));
            $response->assertSessionHasInput('login_history_enable_automatic');
            $this->assertFalse(boolval(System::login_history_enable_automatic()), "keep days '{$keepDays}' must not be saved");
            $this->assertSame(60, (int)System::login_history_keep_days());
        }
    }

    /**
     * Switches are not sent when they are off.
     */
    public function testPostSettingTurnsOff(): void
    {
        $this->loginAsAdmin();
        System::login_history_notify_new_ip(true);
        System::login_history_notify_mail(true);
        System::login_history_enable_automatic(true);
        System::login_history_geoip_auto_update(true);
        System::login_history_keep_days(60);

        $response = $this->postSetting([
            'login_history_keep_days' => '',
        ]);

        $response->assertRedirect(admin_url('login_history'));
        $this->assertFalse(boolval(System::login_history_notify_new_ip()));
        $this->assertFalse(boolval(System::login_history_notify_mail()));
        $this->assertFalse(boolval(System::login_history_enable_automatic()));
        $this->assertFalse(boolval(System::login_history_geoip_auto_update()));
        $this->assertSame(60, (int)System::login_history_keep_days(), 'empty keep days keeps the saved value');
    }

    // ------------------------------------------------------------------ //
    //  Auto-delete / GeoIP auto-update                                    //
    // ------------------------------------------------------------------ //

    public function testDeleteOlderThan(): void
    {
        $login_user = $this->createLoginUser();
        $old = $this->createHistory($login_user, ['ip_address' => self::IP_JP], Carbon::now()->subDays(40));
        $new = $this->createHistory($login_user, ['ip_address' => self::IP_JP], Carbon::now()->subDays(10));

        $deleted = LoginHistory::deleteOlderThan(Carbon::now()->subDays(30)->startOfDay());

        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertNull(LoginHistory::find($old->id));
        $this->assertNotNull(LoginHistory::find($new->id));
    }

    public function testIsLoginHistoryClearDue(): void
    {
        $now = Carbon::create(2026, 10, 2, 3, 0, 0);

        $this->assertTrue(ScheduleCommand::isLoginHistoryClearDue(true, 365, null, $now));
        $this->assertTrue(ScheduleCommand::isLoginHistoryClearDue(true, 365, $now->copy()->subDay(), $now));

        $this->assertFalse(ScheduleCommand::isLoginHistoryClearDue(false, 365, null, $now), 'disabled');
        $this->assertFalse(ScheduleCommand::isLoginHistoryClearDue(true, 0, null, $now), 'keep days 0');
        $this->assertFalse(ScheduleCommand::isLoginHistoryClearDue(true, null, null, $now), 'keep days empty');
        $this->assertFalse(ScheduleCommand::isLoginHistoryClearDue(true, 365, $now->copy()->subHours(2), $now), 'already executed today');
    }

    public function testIsGeoIpUpdateDue(): void
    {
        $now = Carbon::create(2026, 10, 5, 3, 0, 0, 'UTC');
        $thisMonth = Carbon::create(2026, 10, 1, 1, 38, 0, 'UTC');
        $lastMonth = Carbon::create(2026, 9, 1, 1, 38, 0, 'UTC');

        $this->assertTrue(ScheduleCommand::isGeoIpUpdateDue(true, null, null, $now), 'no database yet');
        $this->assertTrue(ScheduleCommand::isGeoIpUpdateDue(true, $lastMonth, null, $now), 'database of last month');
        $this->assertTrue(ScheduleCommand::isGeoIpUpdateDue(true, $lastMonth, $now->copy()->subDay(), $now), 'failed yesterday: retry');

        $this->assertFalse(ScheduleCommand::isGeoIpUpdateDue(false, null, null, $now), 'disabled');
        $this->assertFalse(ScheduleCommand::isGeoIpUpdateDue(true, $thisMonth, null, $now), 'already this month');
        $this->assertFalse(ScheduleCommand::isGeoIpUpdateDue(true, $lastMonth, $now->copy()->subHours(2), $now), 'already tried today');

        $firstDay = Carbon::create(2026, 10, 1, 0, 30, 0, 'UTC');
        $this->assertFalse(ScheduleCommand::isGeoIpUpdateDue(true, $lastMonth, null, $firstDay), "this month's file may not be published on the 1st day");
        $this->assertTrue(ScheduleCommand::isGeoIpUpdateDue(true, null, null, $firstDay), 'no database yet: download even on the 1st day');
    }

    /**
     * The step of "exment:schedule": histories older than the retention period are deleted when the auto-delete is on,
     * once a day. Nothing happens while it is off.
     */
    public function testScheduleClearsOldHistories(): void
    {
        $login_user = $this->createLoginUser();
        $old = $this->createHistory($login_user, ['ip_address' => self::IP_JP], Carbon::now()->subDays(40));
        $new = $this->createHistory($login_user, ['ip_address' => self::IP_JP], Carbon::now()->subDays(10));
        $yesterday = Carbon::now()->subDay();
        System::login_history_keep_days(30);
        System::login_history_automatic_executed($yesterday);

        // off
        System::login_history_enable_automatic(false);
        $this->runScheduleStep('clearLoginHistory');
        $this->assertNotNull(LoginHistory::find($old->id), 'off: nothing is deleted');
        $this->assertTrue(System::login_history_automatic_executed()->isSameDay($yesterday), 'off: not executed');

        // on: the old history is deleted, the execution is recorded
        System::login_history_enable_automatic(true);
        $this->runScheduleStep('clearLoginHistory');
        $this->assertNull(LoginHistory::find($old->id));
        $this->assertNotNull(LoginHistory::find($new->id), 'within the retention period: kept');
        $this->assertTrue(System::login_history_automatic_executed()->isToday());

        // already executed today: not again until tomorrow
        $old2 = $this->createHistory($login_user, ['ip_address' => self::IP_JP], Carbon::now()->subDays(40));
        $this->runScheduleStep('clearLoginHistory');
        $this->assertNotNull(LoginHistory::find($old2->id), 'once a day');
    }

    /**
     * The step of "exment:schedule": the GeoIP database is downloaded when the auto-update is on and there is no
     * database yet. The attempt is recorded before downloading, so a failed download is retried next day, not every
     * hour, and leaves no file behind. Nothing happens (the database file is not even opened) while it is off.
     */
    public function testScheduleUpdatesGeoIpDatabase(): void
    {
        // no database, and a download url nobody answers (connection refused at once)
        $path = storage_path('app/geoip/lhtest_' . short_uuid() . '.mmdb');
        config([
            'exment.geoip_db_path' => $path,
            'exment.geoip_download_url' => 'http://127.0.0.1:9/geoip-{year}-{month}.mmdb.gz',
        ]);
        $yesterday = Carbon::now()->subDay();
        System::login_history_geoip_update_executed($yesterday);

        // off
        System::login_history_geoip_auto_update(false);
        $this->runScheduleStep('updateGeoIp');
        $this->assertTrue(System::login_history_geoip_update_executed()->isSameDay($yesterday), 'off: not even tried');

        // on: tried and failed, recorded as tried today, nothing left behind
        System::login_history_geoip_auto_update(true);
        $this->runScheduleStep('updateGeoIp');
        $this->assertTrue(System::login_history_geoip_update_executed()->isToday());
        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path . '.download');
        $this->assertFileDoesNotExist($path . '.tmp');
        $this->assertFalse(GeoIpService::isAvailable());
    }

    /**
     * While the auto-update is on, "exment:schedule" runs every hour, but the database file is opened (to read its
     * build date) only once a day, not on every tick. Opening is observed through the warning a broken file logs.
     */
    public function testScheduleGeoIpUpdateOpensDatabaseOnceADay(): void
    {
        // a file that is not a GeoIP database: opening it logs a warning
        $path = storage_path('app/geoip/lhtest_' . short_uuid() . '.txt');
        \File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, 'not a GeoIP database');
        config([
            'exment.geoip_db_path' => $path,
            'exment.geoip_download_url' => 'http://127.0.0.1:9/geoip-{year}-{month}.mmdb.gz',
        ]);
        System::login_history_geoip_auto_update(true);

        try {
            $log = \Log::spy();

            // already tried today: the file is not opened
            System::login_history_geoip_update_executed(Carbon::now());
            $this->runScheduleStep('updateGeoIp');
            $log->shouldNotHaveReceived('warning');

            // not tried yet today: the file is opened (found broken, so the download is tried and fails: the file is kept)
            System::login_history_geoip_update_executed(Carbon::now()->subDay());
            $this->runScheduleStep('updateGeoIp');
            $log->shouldHaveReceived('warning');
            $this->assertTrue(System::login_history_geoip_update_executed()->isToday());
            $this->assertSame('not a GeoIP database', file_get_contents($path), 'the current file is kept when the download fails');
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------------ //
    //  GeoIP                                                              //
    // ------------------------------------------------------------------ //

    public function testGeoIpLookupReturnsEmptyForUnknownAddress(): void
    {
        $empty = ['country_code' => null, 'country' => null, 'region' => null, 'city' => null];

        $this->assertSame($empty, GeoIpService::lookup(null));
        $this->assertSame($empty, GeoIpService::lookup(''));
        $this->assertSame($empty, GeoIpService::lookup('not-an-ip'));
        $this->assertSame($empty, GeoIpService::lookup(self::IP_PRIVATE));
        $this->assertSame($empty, GeoIpService::lookup('127.0.0.1'));
    }

    public function testGeoIpDownloadUrls(): void
    {
        config(['exment.geoip_download_url' => 'https://example.com/geoip-{year}-{month}.mmdb.gz']);
        $this->assertSame(
            ['https://example.com/geoip-2026-01.mmdb.gz', 'https://example.com/geoip-2025-12.mmdb.gz'],
            GeoIpService::getDownloadUrls(Carbon::create(2026, 1, 31, 12, 0, 0))
        );

        // url without placeholder: 1 url
        config(['exment.geoip_download_url' => 'https://example.com/geoip.mmdb']);
        $this->assertSame(['https://example.com/geoip.mmdb'], GeoIpService::getDownloadUrls());

        config(['exment.geoip_download_url' => null]);
        $this->assertSame([], GeoIpService::getDownloadUrls());
    }

    // ------------------------------------------------------------------ //
    //  helpers                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Post the login form as a new browser.
     *
     * @param array<string, mixed> $data additional post data
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postLogin(LoginUser $login_user, string $ip, string $password = self::PASSWORD, string $userAgent = 'LoginHistoryTest', array $data = []): TestResponse
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->flushSession();

        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent])
            ->post(admin_url('auth/login'), array_merge([
                'username' => $login_user->user_code,
                'password' => $password,
            ], $data));
    }

    /**
     * Get the list page with the query (filter), as the administrator.
     *
     * @param array<string, mixed> $query
     */
    protected function indexContent(array $query = []): string
    {
        $response = $this->get(admin_url('login_history') . (count($query) > 0 ? '?' . http_build_query($query) : ''));
        $response->assertStatus(200);
        return (string)$response->getContent();
    }

    /**
     * @param array<string, mixed> $data
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postSetting(array $data): TestResponse
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->from(admin_url('login_history'))
            ->post(admin_urls('login_history', 'setting'), $data);
    }

    /**
     * Run one step of "exment:schedule" (Ex. clearLoginHistory) alone, without the other steps.
     */
    protected function runScheduleStep(string $step): void
    {
        $method = new \ReflectionMethod(ScheduleCommand::class, $step);
        $method->setAccessible(true);
        $method->invoke(app(ScheduleCommand::class));
    }

    protected function loginAs(LoginUser $login_user): void
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->be($login_user, Define::AUTHENTICATE_KEY_WEB);
    }

    protected function loginAsAdmin(): void
    {
        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertInstanceOf(LoginUser::class, $admin);
        $this->loginAs($admin);
    }

    protected function enable2factor(): void
    {
        config(['exment.login_use_2factor' => true]);
        System::login_use_2factor(true);
    }

    /**
     * Put the user into a new role group that has the system permissions. (Ex. "login_user", "system")
     *
     * @param array<string> $permissions
     */
    protected function grantSystemPermission(LoginUser $login_user, array $permissions): void
    {
        $name = 'lhtest_role_' . short_uuid();
        $role_group = RoleGroup::create([
            'role_group_name' => $name,
            'role_group_view_name' => $name,
            'role_group_order' => 0,
        ]);
        RoleGroupPermission::create([
            'role_group_id' => $role_group->id,
            'role_group_permission_type' => RoleType::SYSTEM,
            'role_group_target_id' => SystemRoleType::SYSTEM,
            'permissions' => $permissions,
        ]);
        RoleGroupUserOrganization::create([
            'role_group_id' => $role_group->id,
            'role_group_user_org_type' => SystemTableName::USER,
            'role_group_target_id' => $login_user->base_user_id,
        ]);
        // role groups and the user's permissions are cached
        System::clearCache();
    }

    /**
     * Create a user (custom value) and its login user. No role group is assigned.
     */
    protected function createLoginUser(string $login_type = LoginType::PURE, ?string $login_provider = null): LoginUser
    {
        $code = 'lhtest_' . short_uuid();

        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
        $user->setValue([
            'user_code' => $code,
            'user_name' => $code . '_name',
            'email' => $code . '@example.com',
        ]);
        $user->saved_notify(false);
        $user->save();

        $login_user = new LoginUser();
        $login_user->base_user_id = $user->id;
        $login_user->login_type = $login_type;
        $login_user->login_provider = $login_provider;
        $login_user->password = self::PASSWORD;
        $login_user->save();

        $login_user = LoginUser::find($login_user->id);
        $this->assertInstanceOf(LoginUser::class, $login_user);
        return $login_user;
    }

    /**
     * Record a login of the user from the IP address, without sending the login form. (The 2factor tests need no OTP flow.)
     */
    protected function recordFrom(LoginUser $login_user, string $ip): LoginHistory
    {
        request()->server->set('REMOTE_ADDR', $ip);
        $history = LoginHistoryService::record($login_user);
        $this->assertInstanceOf(LoginHistory::class, $history);
        return $history;
    }

    /**
     * Create a login history of the user directly.
     *
     * @param array<string, mixed> $attributes
     */
    protected function createHistory(LoginUser $login_user, array $attributes = [], ?Carbon $created_at = null): LoginHistory
    {
        $history = new LoginHistory();
        $history->saving_users = false;
        $history->login_user_id = $login_user->id;
        $history->base_user_id = $login_user->base_user_id;
        $history->user_code = $login_user->user_code;
        $history->user_name = $login_user->user_name;
        $history->login_type = LoginType::PURE;
        foreach ($attributes as $key => $value) {
            $history->{$key} = $value;
        }
        $history->save();

        if (!is_null($created_at)) {
            \DB::table(SystemTableName::LOGIN_HISTORY)->where('id', $history->id)->update(['created_at' => $created_at]);
        }

        $history = LoginHistory::find($history->id);
        $this->assertInstanceOf(LoginHistory::class, $history);
        return $history;
    }

    /**
     * Notifications (bell icon) triggered by the login of the user, in created order.
     *
     * @return \Illuminate\Support\Collection<int, NotifyNavbar>
     */
    protected function notifiesTriggeredBy(LoginUser $login_user): \Illuminate\Support\Collection
    {
        return NotifyNavbar::withoutGlobalScopes()
            ->where('trigger_user_id', $login_user->base_user_id)
            ->orderBy('id')
            ->get()
            ->values()
            ->toBase();
    }

    /**
     * Get the mail information of the (only) mail sent via Notification::fake().
     */
    protected function sentMailInfo(): MailInfo
    {
        $jobs = [];
        foreach (\Notification::sentNotifications() as $notifiables) {
            foreach ($notifiables as $notifications) {
                foreach (array_get($notifications, MailSendJob::class, []) as $sent) {
                    $jobs[] = $sent['notification'];
                }
            }
        }
        $this->assertCount(1, $jobs, 'exactly 1 mail must be sent');

        $property = new \ReflectionProperty(MailSendJob::class, 'mailInfo');
        $property->setAccessible(true);
        $mailInfo = $property->getValue($jobs[0]);
        $this->assertInstanceOf(MailInfo::class, $mailInfo);

        return $mailInfo;
    }

    /**
     * Histories of the user, in recorded order.
     *
     * @return \Illuminate\Support\Collection<int, LoginHistory>
     */
    protected function historiesOf(LoginUser $login_user): \Illuminate\Support\Collection
    {
        return LoginHistory::query()->where('base_user_id', $login_user->base_user_id)->orderBy('id')->get()->values()->toBase();
    }
}
