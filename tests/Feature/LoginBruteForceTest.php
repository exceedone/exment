<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Testing\TestResponse;
use Exceedone\Exment\Console\ScheduleCommand;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemRoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginFailure;
use Exceedone\Exment\Model\LoginHistory;
use Exceedone\Exment\Model\LoginIpBlock;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Notifications\Mail\MailInfo;
use Exceedone\Exment\Services\Auth2factor\Auth2factorLockService;
use Exceedone\Exment\Services\Login\LoginCaptchaService;
use Exceedone\Exment\Services\Login\LoginFailureService;
use Exceedone\Exment\Services\Login\LoginIpBlockService;
use Exceedone\Exment\Services\RefreshDataService;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * Protection against brute-force attacks on the login form:
 * - IP block: 10 failed logins from an IP address in 5 minutes -> the IP address cannot log in for 5 minutes (settings),
 *   longer when blocked again (5 -> 30 minutes -> 24 hours), IP addresses never blocked, list of blocked IP addresses and release,
 *   notification to the system administrators.
 * - CAPTCHA after failed logins of the IP address or the entered ID.
 * - warning of an account tried from many IP addresses.
 *
 * This test creates its own users / settings and rolls everything back. (no `exment:inittest` data needed)
 */
class LoginBruteForceTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const PASSWORD = 'LoginBruteForce-2026!';
    protected const IP = '133.242.10.1';
    protected const IP_OTHER = '133.242.10.2';
    protected const IP_OFFICE = '210.140.20.5';
    protected const IPV6 = '2400:4050:1:2::10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        \Notification::fake();

        config(['exment.login_use_2factor' => false]);
        // the old throttle (per ID and IP address, by the cache) is not the subject here, and its cache outlives the test
        config(['exment.throttle' => false]);
        System::login_country_restriction(false);
        System::login_history_notify_new_ip(false);

        // the default settings, but the IP block and CAPTCHA are on unless a test turns them off
        System::login_failure_minutes(5);
        System::login_ip_block(true);
        System::login_ip_block_failures(10);
        System::login_ip_block_minutes(5);
        System::login_ip_block_progressive(true);
        System::login_ip_block_minutes_2(30);
        System::login_ip_block_minutes_3(1440);
        System::login_ip_block_allowed_ips('');
        System::login_ip_block_notify(true);
        System::login_captcha(false);
        System::login_captcha_failures(3);
        System::login_multi_ip_warning(true);
        System::login_multi_ip_count(5);
        System::login_multi_ip_minutes(60);
        System::login_2factor_lock(true);
        System::login_2factor_lock_failures(5);
        System::login_2factor_lock_minutes(15);
        System::login_brute_force_notify_mail(false);
        System::system_admin_users([]);

        // independent of the records of the local database (ex. a demo). "delete", not "truncate": rolled back after the test
        LoginFailure::query()->delete();
        LoginIpBlock::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ //
    //  Settings and helpers                                               //
    // ------------------------------------------------------------------ //

    /**
     * Default settings: the IP block and CAPTCHA are off (no change of the login until the administrator turns them on),
     * the warning of many IP addresses, the notification of a block and the limit of 2factor codes (as before) are on. Values as the customer asked: 10 failures in 5 minutes -> 5 minutes.
     */
    public function testDefaultSettings(): void
    {
        foreach (array_keys(System::get_system_values([LoginIpBlockService::SETTING_GROUP])) as $key) {
            \DB::table(SystemTableName::SYSTEM)->where('system_name', $key)->delete();
        }
        System::clearCache();

        $this->assertSame([
            'login_failure_minutes' => 5,
            'login_ip_block' => false,
            'login_ip_block_failures' => 10,
            'login_ip_block_minutes' => 5,
            'login_ip_block_progressive' => true,
            'login_ip_block_minutes_2' => 30,
            'login_ip_block_minutes_3' => 1440,
            'login_ip_block_allowed_ips' => '',
            'login_ip_block_notify' => true,
            'login_captcha' => false,
            'login_captcha_failures' => 3,
            'login_multi_ip_warning' => true,
            'login_multi_ip_count' => 5,
            'login_multi_ip_minutes' => 60,
            'login_2factor_lock' => true,
            'login_2factor_lock_failures' => 5,
            'login_2factor_lock_minutes' => 15,
            'login_brute_force_notify_mail' => false,
        ], System::get_system_values([LoginIpBlockService::SETTING_GROUP]));

        $service = app(LoginIpBlockService::class);
        $this->assertFalse($service->isEnabled());
        $this->assertFalse(app(LoginCaptchaService::class)->isEnabled());
        $this->assertTrue(app(LoginFailureService::class)->isMultiIpWarningEnabled());
        $this->assertSame([1 => 5, 2 => 30, 3 => 1440], [1 => $service->getBlockMinutes(1), 2 => $service->getBlockMinutes(2), 3 => $service->getBlockMinutes(3)]);
    }

    /**
     * Key of an IP address: IPv4 as it is, IPv4-mapped IPv6 as IPv4, IPv6 by the network of the first 64 bits.
     */
    public function testIpKey(): void
    {
        $this->assertSame('133.242.10.1', LoginIpBlockService::getIpKey('133.242.10.1'));
        $this->assertSame('133.242.10.1', LoginIpBlockService::getIpKey('::ffff:133.242.10.1'));
        $this->assertSame('2400:4050:1:2::/64', LoginIpBlockService::getIpKey('2400:4050:1:2::10'));
        $this->assertSame('2400:4050:1:2::/64', LoginIpBlockService::getIpKey('2400:4050:0001:0002:ffff:eeee:dddd:cccc'));
        $this->assertSame('2400:4050:1:3::/64', LoginIpBlockService::getIpKey('2400:4050:1:3::10'));
        $this->assertNull(LoginIpBlockService::getIpKey(null));
        $this->assertNull(LoginIpBlockService::getIpKey(''));
        $this->assertNull(LoginIpBlockService::getIpKey('example.com'));
    }

    /**
     * A saved value out of the range (Ex. edited in the database) is not used: the default.
     */
    public function testSettingOutOfRangeUsesDefault(): void
    {
        $service = app(LoginIpBlockService::class);

        System::login_ip_block_failures(0);
        $this->assertSame(10, $service->getMaxFailures());
        System::login_ip_block_failures(LoginIpBlockService::FAILURES_MAX + 1);
        $this->assertSame(10, app(LoginIpBlockService::class)->getMaxFailures());
        System::login_ip_block_failures(1);
        $this->assertSame(1, $service->getMaxFailures());
        System::login_ip_block_failures(LoginIpBlockService::FAILURES_MAX);
        $this->assertSame(LoginIpBlockService::FAILURES_MAX, $service->getMaxFailures());

        System::login_failure_minutes(-1);
        $this->assertSame(5, LoginIpBlockService::getFailureMinutes());

        $this->assertSame(7, LoginIpBlockService::toRange('7', 1, 10, 3));
        $this->assertSame(3, LoginIpBlockService::toRange('abc', 1, 10, 3));
        $this->assertSame(3, LoginIpBlockService::toRange(null, 1, 10, 3));
    }

    /**
     * Block time by the level. "longer block" off: always the first block time. A shorter time saved for a later block is not used.
     */
    public function testBlockMinutesByLevel(): void
    {
        $service = app(LoginIpBlockService::class);
        $this->assertSame(5, $service->getBlockMinutes(1));
        $this->assertSame(30, $service->getBlockMinutes(2));
        $this->assertSame(1440, $service->getBlockMinutes(3));
        $this->assertSame(1440, $service->getBlockMinutes(4));

        System::login_ip_block_progressive(false);
        $this->assertSame(5, $service->getBlockMinutes(2));
        $this->assertSame(5, $service->getBlockMinutes(3));

        System::login_ip_block_progressive(true);
        System::login_ip_block_minutes(60);
        $this->assertSame(60, $service->getBlockMinutes(2), 'never shorter than the first block');
    }

    // ------------------------------------------------------------------ //
    //  IP block                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Off: never blocked. The failures are still recorded (not as login history), and the right password logs in.
     */
    public function testOffNeverBlocks(): void
    {
        System::login_ip_block(false);
        $login_user = $this->createLoginUser();
        $maxHistoryId = (int)LoginHistory::max('id');

        for ($i = 0; $i < 12; $i++) {
            $this->postLogin($login_user->user_code, 'wrong-password', self::IP)
                ->assertSessionHasErrors(['username' => exmtrans('error.login_failed')]);
        }

        $this->assertSame(0, LoginIpBlock::query()->count());
        $this->assertSame(12, $this->failures(self::IP)->count());
        $this->assertSame(0, LoginHistory::query()->where('id', '>', $maxHistoryId)->count(), 'a wrong password is not a login');

        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP);
        $this->assertLoggedIn();
    }

    /**
     * 9 failures: not blocked. The 10th failure blocks the IP address for 5 minutes: even the right password is refused
     * before the authentication, with the remaining minutes.
     */
    public function testBlockedAtMaxFailures(): void
    {
        $login_user = $this->createLoginUser();

        $this->failLogin(9, self::IP, $login_user->user_code);
        $this->assertNull($this->blockOf(self::IP), '9 failures: not blocked');

        $response = $this->postLogin($login_user->user_code, 'wrong-password', self::IP);
        $response->assertRedirect(admin_url('auth/login'));
        $response->assertSessionHasErrors(['username' => exmtrans('login.message.ip_blocked', 5)]);

        $block = $this->blockOf(self::IP);
        $this->assertNotNull($block);
        $this->assertTrue($block->isBlocked());
        $this->assertSame(1, intval($block->block_level));
        $this->assertSame(10, intval($block->failed_count));
        $this->assertSame($login_user->user_code, $block->last_username);
        $this->assertSame(self::IP, $block->ip_address);
        $this->assertEqualsWithDelta(Carbon::now()->addMinutes(5)->getTimestamp(), $block->blocked_until?->getTimestamp(), 2);

        // the right password: refused, not logged in, not recorded as a login nor a failure
        $failures = $this->failures(self::IP)->count();
        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP)
            ->assertSessionHasErrors(['username' => exmtrans('login.message.ip_blocked', 5)]);
        $this->assertNotLoggedIn();
        $this->assertSame(0, LoginHistory::query()->where('base_user_id', $login_user->base_user_id)->count());
        $this->assertSame($failures, $this->failures(self::IP)->count());
        $this->assertSame(1, intval($this->blockOf(self::IP)?->blocked_attempts), 'the refused attempt is counted on the block');

        // the login page tells it before posting
        $this->get(admin_url('auth/login'))->assertStatus(200)->assertSee(exmtrans('login.message.ip_blocked', 5));
    }

    /**
     * The customer's case: different IDs (existing or not) from the same IP address are counted together.
     */
    public function testDifferentIdsFromSameIpAreCounted(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postLogin('zz_bf_' . $i, 'wrong-password', self::IP);
        }
        $this->assertNotNull($this->blockOf(self::IP));
    }

    /**
     * Another IP address is not blocked. A user already logged in from the blocked IP address keeps using the pages.
     */
    public function testOnlyTheLoginFormOfTheIpIsBlocked(): void
    {
        $login_user = $this->createLoginUser();
        $logged_in = $this->createLoginUser();

        $this->postLogin($logged_in->user_code, self::PASSWORD, self::IP);
        $this->assertLoggedIn();

        $this->withServerVariables(['REMOTE_ADDR' => self::IP]);
        $this->failLogin(10, self::IP, $login_user->user_code, false);
        $this->assertNotNull($this->blockOf(self::IP));

        // the logged in user (same session) still uses the pages from the blocked IP address
        $this->assertTrue(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check());
        $this->withServerVariables(['REMOTE_ADDR' => self::IP])->get(admin_url('auth/setting'))->assertStatus(200);

        // another IP address logs in
        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP_OTHER);
        $this->assertLoggedIn();
    }

    /**
     * Only the login form is blocked: the middleware is on "POST auth/login" only.
     */
    public function testMiddlewareIsOnTheLoginFormOnly(): void
    {
        $routes = \Route::getRoutes();
        $routes->refreshNameLookups();
        $found = [];
        foreach ($routes->getRoutes() as $route) {
            if (in_array('admin.login-ip-block', $route->middleware(), true)) {
                $found[] = implode('|', $route->methods()) . ' ' . $route->uri();
            }
        }
        $this->assertSame(['POST ' . url_join(config('admin.route.prefix'), 'auth/login')], $found);

        // checked before the login restriction by country
        foreach ($routes->getRoutes() as $route) {
            if ($route->uri() === url_join(config('admin.route.prefix'), 'auth/login') && in_array('POST', $route->methods(), true)) {
                $middleware = array_values(array_filter($route->middleware(), function ($m) {
                    return strpos($m, 'admin.login-') === 0;
                }));
                $this->assertSame(['admin.login-ip-block', 'admin.login-country:pure'], $middleware);
            }
        }
    }

    /**
     * The block ends after the block time. The failures before the block are not counted again.
     */
    public function testBlockEndsAndOldFailuresAreNotCountedAgain(): void
    {
        $login_user = $this->createLoginUser();
        $this->failLogin(10, self::IP, $login_user->user_code);
        $this->assertNotNull($this->blockOf(self::IP));

        $this->travelSeconds(4 * 60 + 50);
        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP)->assertSessionHasErrors(['username' => exmtrans('login.message.ip_blocked', 1)]);

        $this->travelSeconds(20);
        $this->failLogin(1, self::IP, $login_user->user_code);
        $this->assertFalse($this->blockOf(self::IP)?->isBlocked(), '1 failure after the block: not blocked again');

        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP);
        $this->assertLoggedIn();
    }

    /**
     * Failures older than the counting period are not counted.
     */
    public function testFailuresOutOfThePeriodAreNotCounted(): void
    {
        $this->failLogin(9, self::IP);
        $this->travelSeconds(5 * 60 + 1);
        $this->failLogin(1, self::IP);
        $this->assertNull($this->blockOf(self::IP));

        // the period can be changed
        System::login_failure_minutes(10);
        $this->failLogin(1, self::IP);
        $this->assertNotNull($this->blockOf(self::IP), '9 + 2 failures in 10 minutes');
    }

    /**
     * The number of failures and the block time can be changed.
     */
    public function testChangedSettingsAreUsed(): void
    {
        System::login_ip_block_failures(3);
        System::login_ip_block_minutes(15);

        $this->failLogin(2, self::IP);
        $this->assertNull($this->blockOf(self::IP));
        $this->postLogin('zz_bf_user', 'wrong-password', self::IP)
            ->assertSessionHasErrors(['username' => exmtrans('login.message.ip_blocked', 15)]);
        $this->assertEqualsWithDelta(Carbon::now()->addMinutes(15)->getTimestamp(), $this->blockOf(self::IP)?->blocked_until?->getTimestamp(), 2);
    }

    /**
     * Blocked again within 24 hours after the block ended: 5 minutes -> 30 minutes -> 24 hours -> 24 hours.
     * More than 24 hours later: from 5 minutes again.
     */
    public function testBlockGetsLongerWhenBlockedAgain(): void
    {
        foreach ([[1, 5], [2, 30], [3, 1440], [3, 1440]] as [$level, $minutes]) {
            $this->failLogin(10, self::IP);
            $block = $this->blockOf(self::IP);
            $this->assertNotNull($block);
            $this->assertSame($level, intval($block->block_level));
            $this->assertEqualsWithDelta(Carbon::now()->addMinutes($minutes)->getTimestamp(), $block->blocked_until?->getTimestamp(), 2, "level {$level}");
            $this->travelSeconds($minutes * 60 + 1);
        }

        $this->travelSeconds(24 * 60 * 60 + 1);
        $this->failLogin(10, self::IP);
        $this->assertSame(1, intval($this->blockOf(self::IP)?->block_level));
        $this->assertEqualsWithDelta(Carbon::now()->addMinutes(5)->getTimestamp(), $this->blockOf(self::IP)?->blocked_until?->getTimestamp(), 2);
        $this->assertSame(1, LoginIpBlock::query()->count(), '1 record per IP address');
    }

    /**
     * "longer block" off: blocked again for the same time.
     */
    public function testBlockTimeIsSameWhenProgressiveIsOff(): void
    {
        System::login_ip_block_progressive(false);

        $this->failLogin(10, self::IP);
        $this->travelSeconds(5 * 60 + 1);
        $this->failLogin(10, self::IP);

        $block = $this->blockOf(self::IP);
        $this->assertSame(2, intval($block?->block_level), 'counted');
        $this->assertEqualsWithDelta(Carbon::now()->addMinutes(5)->getTimestamp(), $block?->blocked_until?->getTimestamp(), 2);
    }

    /**
     * "IP addresses never blocked" (also CIDR and IPv6) are never blocked.
     */
    public function testAllowedIpsAreNeverBlocked(): void
    {
        System::login_ip_block_allowed_ips("210.140.20.0/24\r\n2400:4050:1::/48");

        $this->failLogin(11, self::IP_OFFICE);
        $this->failLogin(11, self::IPV6);
        $this->assertSame(0, LoginIpBlock::query()->count());

        $login_user = $this->createLoginUser();
        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP_OFFICE);
        $this->assertLoggedIn();
    }

    /**
     * IPv6: blocked per network of 64 bits. Changing the last 64 bits does not escape. Another network is not blocked.
     */
    public function testIpv6IsBlockedByNetwork(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->postLogin('zz_bf_user', 'wrong-password', '2400:4050:1:2::' . dechex($i));
        }
        $block = $this->blockOf('2400:4050:1:2::ffff');
        $this->assertNotNull($block);
        $this->assertSame('2400:4050:1:2::/64', $block->ip_key);

        $login_user = $this->createLoginUser();
        $this->postLogin($login_user->user_code, self::PASSWORD, '2400:4050:1:2:aaaa::1')->assertSessionHasErrors('username');
        $this->assertNotLoggedIn();

        $this->postLogin($login_user->user_code, self::PASSWORD, '2400:4050:1:3::1');
        $this->assertLoggedIn();
    }

    /**
     * A wrong password is checked before the country: a blocked IP address gets the message of the block.
     */
    public function testBlockIsCheckedBeforeTheCountry(): void
    {
        $this->failLogin(10, self::IP);
        $maxHistoryId = (int)LoginHistory::max('id');

        System::login_country_restriction(true);
        System::login_country_allowed(['VN']);
        System::login_country_unknown_allowed(false);

        $this->postLogin('zz_bf_user', 'wrong-password', self::IP)
            ->assertSessionHasErrors(['username' => exmtrans('login.message.ip_blocked', 5)]);
        $this->assertSame(0, LoginHistory::query()->where('id', '>', $maxHistoryId)->count(), 'not recorded as blocked by country');
    }

    /**
     * With the old throttle on (its default: 5 attempts per ID and IP address). The same ID: the throttle refuses the 6th attempt
     * before the password, and the refused attempts are not failures. Different IDs from the same IP address (the customer's case):
     * the IP address is blocked at the 10th failure, and the block is checked before the throttle.
     */
    public function testOldThrottleWithIpBlock(): void
    {
        config(['exment.throttle' => true]);
        $prefix = 'zz_bf_' . short_uuid() . '_';
        $usernames = [];

        try {
            $usernames[] = $prefix . 'a';
            for ($i = 0; $i < 5; $i++) {
                $this->postLogin($prefix . 'a', 'wrong-password', self::IP)
                    ->assertSessionHasErrors(['username' => exmtrans('error.login_failed')]);
            }
            for ($i = 0; $i < 2; $i++) {
                $this->postLogin($prefix . 'a', 'wrong-password', self::IP)->assertSessionHasErrors('username')
                    ->assertSessionDoesntHaveErrors(['username' => exmtrans('error.login_failed')]);
            }
            $this->assertSame(5, $this->failures(self::IP)->count(), 'refused by the throttle: not a failure');
            $this->assertNull($this->blockOf(self::IP));

            for ($i = 0; $i < 4; $i++) {
                $usernames[] = $prefix . $i;
                $this->postLogin($prefix . $i, 'wrong-password', self::IP);
            }
            $this->assertNull($this->blockOf(self::IP), '9 failures');
            $usernames[] = $prefix . 'b';
            $this->postLogin($prefix . 'b', 'wrong-password', self::IP)
                ->assertSessionHasErrors(['username' => exmtrans('login.message.ip_blocked', 5)]);
            $this->assertNotNull($this->blockOf(self::IP));

            // the ID refused by the throttle: the message of the block
            $this->postLogin($prefix . 'a', 'wrong-password', self::IP)
                ->assertSessionHasErrors(['username' => exmtrans('login.message.ip_blocked', 5)]);

            // another IP address: neither blocked nor throttled
            $login_user = $this->createLoginUser();
            $usernames[] = $login_user->user_code;
            $this->postLogin($login_user->user_code, self::PASSWORD, self::IP_OTHER);
            $this->assertLoggedIn();
        } finally {
            // the cache of the throttle outlives the test
            foreach ($usernames as $username) {
                foreach ([self::IP, self::IP_OTHER] as $ip) {
                    app(\Illuminate\Cache\RateLimiter::class)->clear(strtolower($username) . '|' . $ip);
                }
            }
        }
    }

    /**
     * Blocked by another request at the same time: not blocked twice, not notified twice.
     */
    public function testAlreadyBlockedIsNotBlockedAgain(): void
    {
        $admin = $this->createAdmin();
        $this->failLogin(10, self::IP);
        $this->assertCount(1, $this->notifiesOf($admin));

        $failure = app(LoginFailureService::class)->record(self::IP, LoginFailure::REASON_PASSWORD, LoginType::PURE);
        $this->assertNotNull($failure);
        $this->assertNull(app(LoginIpBlockService::class)->blockIfNeeded($failure));
        $this->assertCount(1, $this->notifiesOf($admin));
    }

    /**
     * 2 requests block the same IP address at the same time: the other request saves its block first (unique "ip_key").
     * Not an error, 1 block, and notified only by the other request.
     */
    public function testBlockedByAnotherRequestAtTheSameTime(): void
    {
        $admin = $this->createAdmin();
        $this->failLogin(9, self::IP);
        $this->assertNull($this->blockOf(self::IP));

        // the other request saves the block just before this request does (only once)
        LoginIpBlock::creating(function (LoginIpBlock $block) {
            if (\DB::table(SystemTableName::LOGIN_IP_BLOCK)->where('ip_key', $block->ip_key)->exists()) {
                return;
            }
            \DB::table(SystemTableName::LOGIN_IP_BLOCK)->insert([
                'ip_key' => $block->ip_key,
                'ip_address' => $block->ip_address,
                'block_level' => 1,
                'failed_count' => 10,
                'blocked_attempts' => 0,
                'blocked_at' => $block->blocked_at,
                'blocked_until' => $block->blocked_until,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });

        $failure = app(LoginFailureService::class)->record(self::IP, LoginFailure::REASON_PASSWORD, LoginType::PURE);
        $this->assertNotNull($failure);
        $this->assertNull(app(LoginIpBlockService::class)->blockIfNeeded($failure));

        $this->assertSame(1, LoginIpBlock::query()->where('ip_key', self::IP)->count());
        $this->assertCount(0, $this->notifiesOf($admin), 'notified by the other request, not by this one');
        $this->postLogin('zz_bf_user', self::PASSWORD, self::IP)
            ->assertSessionHasErrors(['username' => exmtrans('login.message.ip_blocked', 5)]);
    }

    /**
     * Failures of 2factor (wrong one-time password) are not counted for the IP address.
     */
    public function testWrongOneTimePasswordIsNotCountedForTheIp(): void
    {
        $service = app(LoginFailureService::class);
        for ($i = 0; $i < 12; $i++) {
            $failure = $service->record(self::IP, LoginFailure::REASON_2FACTOR, LoginType::PURE);
            $this->assertNotNull($failure);
            $this->assertNull(app(LoginIpBlockService::class)->blockIfNeeded($failure));
        }
        $this->assertNull($this->blockOf(self::IP));
    }

    /**
     * LDAP login posts the same form: counted and blocked the same way, with the provider.
     */
    public function testLdapFormIsCounted(): void
    {
        // (no LDAP server in the test: the failure of the LDAP form is recorded through the same service as the controller does)
        $request = \Illuminate\Http\Request::create(admin_url('auth/login'), 'POST', ['username' => 'zz_bf_ldap'], [], [], ['REMOTE_ADDR' => self::IP]);
        for ($i = 0; $i < 10; $i++) {
            $block = app(LoginFailureService::class)->handleLoginFailure($request, LoginFailure::REASON_PASSWORD, LoginType::LDAP, null, 'bfldap');
        }
        $this->assertNotNull($block);

        $failure = $this->failures(self::IP)->first();
        $this->assertNotNull($failure);
        $this->assertSame(LoginType::LDAP, $failure->login_type);
        $this->assertSame('bfldap', $failure->login_provider);
        $this->assertSame('zz_bf_ldap', $failure->username);
    }

    /**
     * An error of the login (Ex. LDAP server down) is not a wrong password: not counted.
     */
    public function testLoginErrorIsNotCounted(): void
    {
        $this->createLdapSetting();


        $this->postLogin('zz_bf_ldap', 'wrong-password', self::IP, ['login_setting_bfldap' => '1'])
            ->assertSessionHasErrors('username');
        $this->assertSame(0, $this->failures(self::IP)->count());
    }

    /**
     * The button of LDAP posts the same form: CAPTCHA is checked before LDAP, and a wrong CAPTCHA is counted with the provider.
     */
    public function testCaptchaIsCheckedBeforeLdap(): void
    {
        System::login_captcha(true);
        $this->createLdapSetting();
        $this->failLogin(3, self::IP);

        // wrong CAPTCHA: refused before LDAP
        $this->captchaCode(self::IP);
        $this->postLogin('zz_bf_ldap', 'wrong-password', self::IP, ['login_setting_bfldap' => '1', 'captcha' => 'XXXXX'], false)
            ->assertSessionHasErrors(['captcha' => exmtrans('login.message.captcha_failed')])
            ->assertSessionDoesntHaveErrors('username');
        $failure = $this->failures(self::IP)->where('reason', LoginFailure::REASON_CAPTCHA)->first();
        $this->assertNotNull($failure);
        $this->assertSame(LoginType::LDAP, $failure->login_type);
        $this->assertSame('bfldap', $failure->login_provider);

        // the right CAPTCHA: goes to LDAP (no LDAP server in the test: an error of LDAP, not of CAPTCHA)
        $code = $this->captchaCode(self::IP);
        $this->postLogin('zz_bf_ldap', 'wrong-password', self::IP, ['login_setting_bfldap' => '1', 'captcha' => $code], false)
            ->assertSessionHasErrors('username')
            ->assertSessionDoesntHaveErrors('captcha');
    }

    // ------------------------------------------------------------------ //
    //  Notification                                                       //
    // ------------------------------------------------------------------ //

    /**
     * The block is notified to the system administrators on the bell (as a warning, linked to the blocked IP address).
     * Mail only when the setting is on.
     */
    public function testBlockIsNotified(): void
    {
        $admin = $this->createAdmin();

        $this->failLogin(10, self::IP);
        $block = $this->blockOf(self::IP);
        $this->assertNotNull($block);

        $notifies = $this->notifiesOf($admin);
        $this->assertCount(1, $notifies);
        $notify = $notifies[0];
        $this->assertSame(SystemTableName::LOGIN_IP_BLOCK, $notify->parent_type);
        $this->assertEquals($block->id, $notify->parent_id);
        $this->assertSame(exmtrans('login_ip_block.notify.subject', self::IP), $notify->notify_subject);
        $this->assertStringContainsString(admin_urls('login_ip_block', $block->id), (string)$notify->notify_body);
        $this->assertStringContainsString('zz_bf_user', (string)$notify->notify_body);
        \Notification::assertNothingSent();

        // the bell: a warning, and the link opens the blocked IP address
        $this->loginAs($admin);
        $items = $this->get(admin_url('webapi/notifyPage'))->assertStatus(200)->json('items');
        $this->assertIsArray($items);
        $this->assertSame('fa-exclamation-triangle', $items[0]['icon']);
        $this->assertSame(exmtrans('login_ip_block.header'), $items[0]['table_view_name']);
        $this->get(admin_url("notify_navbar/rowdetail/{$notify->id}"))->assertRedirect(admin_urls('login_ip_block', $block->id));
        $this->get(admin_urls('notify_navbar', $notify->id))->assertStatus(200)
            ->assertSee(exmtrans('login_ip_block.header'))
            ->assertSee(admin_url("notify_navbar/rowdetail/{$notify->id}"));

        // mail
        System::login_brute_force_notify_mail(true);
        $this->failLogin(10, self::IP_OTHER);
        $this->assertCount(2, $this->notifiesOf($admin));
        \Notification::assertSentTimes(MailSendJob::class, 1);
        $mailInfo = $this->sentMailInfo();
        $this->assertSame([$admin->email], array_values($mailInfo->getTo()));
        $this->assertStringContainsString(self::IP_OTHER, (string)$mailInfo->getSubject());
    }

    /**
     * Notification off: blocked, but not notified.
     */
    public function testBlockNotificationOff(): void
    {
        $admin = $this->createAdmin();
        System::login_ip_block_notify(false);

        $this->failLogin(10, self::IP);
        $this->assertNotNull($this->blockOf(self::IP));
        $this->assertCount(0, $this->notifiesOf($admin));
    }

    // ------------------------------------------------------------------ //
    //  List of blocked IP addresses                                       //
    // ------------------------------------------------------------------ //

    /**
     * The list shows the IP addresses blocked now, with checkbox, show and delete (= release). Not the blocks already ended.
     */
    public function testListShowsBlockedIpAddresses(): void
    {
        $blocked = $this->createBlock('133.242.10.11', 5);
        $ended = $this->createBlock('133.242.10.12', -1);

        $this->loginAsAdmin();
        $content = (string)$this->get(admin_url('login_ip_block'))->assertStatus(200)->getContent();
        $this->assertStringContainsString(exmtrans('login_ip_block.header'), $content);
        $this->assertStringContainsString('133.242.10.11', $content);
        $this->assertStringNotContainsString('133.242.10.12', $content);
        // same as the other lists: checkbox and the links of the row
        $this->assertStringContainsString('grid-row-checkbox', $content);
        $this->assertStringContainsString('data-id="' . $blocked->id . '"', $content);
        $this->assertStringContainsString(admin_urls('login_ip_block', $blocked->id), $content);
        $this->assertStringContainsString(admin_url('login_setting'), $content);

        // the detail shows also the ended block (from the notification)
        $this->get(admin_urls('login_ip_block', $ended->id))->assertStatus(200)
            ->assertSee('133.242.10.12')
            ->assertSee(exmtrans('login_ip_block.status_options.expired'));
    }

    /**
     * The detail shows the status and the recent failed logins of the IP address. Deleted record: to the list with a message.
     */
    public function testDetail(): void
    {
        $this->failLogin(10, self::IP, 'zz_bf_<detail>');
        $block = $this->blockOf(self::IP);
        $this->assertNotNull($block);

        $this->loginAsAdmin();
        $this->get(admin_urls('login_ip_block', $block->id))->assertStatus(200)
            ->assertSee(exmtrans('login_ip_block.status_options.blocked'))
            ->assertSee(exmtrans('login_ip_block.block_level_text', 1))
            ->assertSee(exmtrans('login_ip_block.recent_failures'))
            ->assertSee(exmtrans('login_ip_block.reason_options.password'))
            ->assertSee('zz_bf_&lt;detail&gt;', false)
            ->assertDontSee('zz_bf_<detail>', false);

        $this->get(admin_urls('login_ip_block', 999999999))->assertRedirect(admin_url('login_ip_block'));
    }

    /**
     * The notification of a block deleted later by the schedule: no button to the data, and the link goes to the list
     * with the "data not found" message. (not an error page)
     */
    public function testNotificationOfDeletedBlock(): void
    {
        $admin = $this->createAdmin();
        $this->failLogin(10, self::IP);
        $block = $this->blockOf(self::IP);
        $this->assertNotNull($block);
        $notify = $this->notifiesOf($admin)->first();
        $this->assertNotNull($notify);

        // deleted by the schedule: more than 24 hours after the block ended
        $this->travelSeconds((24 * 60 + 6) * 60);
        $this->assertSame(1, LoginIpBlockService::deleteExpired());
        $this->assertNull(LoginIpBlock::find($block->id));

        $this->loginAs($admin);
        $this->get(admin_urls('notify_navbar', $notify->id))->assertStatus(200)
            ->assertSee(exmtrans('login_ip_block.header'))
            ->assertDontSee(admin_url("notify_navbar/rowdetail/{$notify->id}"));

        $this->get(admin_url("notify_navbar/rowdetail/{$notify->id}"))->assertRedirect(admin_urls('login_ip_block', $block->id));
        $this->get(admin_urls('login_ip_block', $block->id))->assertRedirect(admin_url('login_ip_block'));
        $toastr = session('toastr');
        $this->assertInstanceOf(\Illuminate\Support\MessageBag::class, $toastr);
        $this->assertSame(exmtrans('common.message.notfound'), $toastr->first('message'));
        $this->assertSame('error', $toastr->first('type'));
        $this->get(admin_url('login_ip_block'))->assertStatus(200);
    }

    /**
     * Delete releases the block: the IP address logs in at once, the next block starts from the first block time,
     * and the failures before the release are not counted again.
     */
    public function testReleaseByDelete(): void
    {
        $login_user = $this->createLoginUser();
        $this->failLogin(10, self::IP);
        $this->travelSeconds(5 * 60 + 1);
        $this->failLogin(10, self::IP);
        $block = $this->blockOf(self::IP);
        $this->assertSame(2, intval($block?->block_level));

        $this->loginAsAdmin();
        $this->delete(admin_urls('login_ip_block', $block?->id))->assertStatus(200)->assertJson(['status' => true, 'message' => exmtrans('login_ip_block.message.released')]);

        $released = $this->blockOf(self::IP);
        $this->assertNotNull($released);
        $this->assertFalse($released->isBlocked());
        $this->assertSame(LoginIpBlock::STATUS_RELEASED, $released->getStatus());
        $this->assertSame(0, intval($released->block_level));
        $this->assertNotNull($released->released_at);

        $this->failLogin(1, self::IP);
        $this->assertFalse($this->blockOf(self::IP)?->isBlocked(), 'the failures before the release are not counted');

        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP);
        $this->assertLoggedIn();

        $this->failLogin(9, self::IP);
        $this->assertSame(1, intval($this->blockOf(self::IP)?->block_level), 'from the first block time again');
    }

    /**
     * Batch release: "id,id". A block already ended is ignored. Nothing released: failed.
     */
    public function testBatchRelease(): void
    {
        $a = $this->createBlock('133.242.10.21', 5);
        $b = $this->createBlock('133.242.10.22', 5);
        $ended = $this->createBlock('133.242.10.23', -1);

        $this->loginAsAdmin();
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->delete(admin_urls('login_ip_block', "{$a->id},{$b->id},{$ended->id},abc"))->assertJson(['status' => true]);
        $this->assertSame(0, LoginIpBlock::query()->blocked()->count());
        $this->assertNull(LoginIpBlock::query()->where('id', $ended->id)->first()?->released_at, 'the ended block is not marked as released');

        $this->delete(admin_urls('login_ip_block', $ended->id))->assertJson(['status' => false, 'message' => exmtrans('login_ip_block.message.release_failed')]);
    }

    /**
     * Only the system administrator permission opens the list. (Same as the login history)
     */
    public function testListNeedsSystemPermission(): void
    {
        if (!System::permission_available()) {
            $this->markTestSkipped('permission is not available in this environment.');
        }

        $login_user = $this->createLoginUser();
        $this->grantSystemPermission($login_user, [Permission::LOGIN_USER]);
        $this->loginAs($login_user);
        foreach (['login_ip_block', 'login_ip_block/1'] as $endpoint) {
            $this->assertFalse($login_user->visible($endpoint), "{$endpoint} must be denied");
        }

        $admin_by_role = $this->createLoginUser();
        $this->grantSystemPermission($admin_by_role, [Permission::SYSTEM]);
        $this->loginAs($admin_by_role);
        foreach (['login_ip_block', 'login_ip_block/1'] as $endpoint) {
            $this->assertTrue($admin_by_role->visible($endpoint), "{$endpoint} must be allowed");
        }
    }

    /**
     * Without the system administrator permission, the requests themselves are refused (403):
     * releasing a block, and saving the settings. Nothing is changed. With the permission: done.
     */
    public function testReleaseAndSaveNeedSystemPermission(): void
    {
        if (!System::permission_available()) {
            $this->markTestSkipped('permission is not available in this environment.');
        }
        $block = $this->createBlock(self::IP, 5);
        $before = System::get_system_values([LoginIpBlockService::SETTING_GROUP]);

        $login_user = $this->createLoginUser();
        $this->grantSystemPermission($login_user, [Permission::LOGIN_USER]);
        $this->loginAs($login_user);

        // (as the grid and the form post them by ajax. A page request gets the page of "no permission", which ends the process)
        $this->withoutMiddleware(VerifyCsrfToken::class)->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->delete(admin_urls('login_ip_block', $block->id))->assertStatus(403);
        $this->assertTrue($this->blockOf(self::IP)?->isBlocked(), 'not released');

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postSetting(['login_ip_block' => '0', 'login_failure_minutes' => '30', 'login_2factor_lock' => '0'])
            ->assertStatus(403);
        System::clearCache();
        $this->assertSame($before, System::get_system_values([LoginIpBlockService::SETTING_GROUP]), 'not saved');

        // the system administrator by the role group
        $admin_by_role = $this->createLoginUser();
        $this->grantSystemPermission($admin_by_role, [Permission::SYSTEM]);
        $this->loginAs($admin_by_role);
        $this->withoutMiddleware(VerifyCsrfToken::class)->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->delete(admin_urls('login_ip_block', $block->id))->assertStatus(200)->assertJson(['status' => true]);
        $this->assertFalse($this->blockOf(self::IP)?->isBlocked());
    }

    // ------------------------------------------------------------------ //
    //  Setting form                                                       //
    // ------------------------------------------------------------------ //

    /**
     * The box on the login setting page, with the current IP address (to check a reverse proxy).
     */
    public function testSettingFormIsShown(): void
    {
        $this->loginAsAdmin();
        $content = (string)$this->withServerVariables(['REMOTE_ADDR' => self::IP_OFFICE])->get(admin_url('login_setting'))->assertStatus(200)->getContent();

        $this->assertStringContainsString(exmtrans('login.brute_force'), $content);
        $this->assertStringContainsString(route('exment.post_login_brute_force'), $content);
        foreach (array_keys(System::get_system_values([LoginIpBlockService::SETTING_GROUP])) as $key) {
            $this->assertStringContainsString('name="' . $key . '"', $content, (string)$key);
        }
        $this->assertStringContainsString(esc_html(exmtrans('login.help.brute_force_current_ip', self::IP_OFFICE)), $content);
        $this->assertStringContainsString(admin_url('login_ip_block'), $content);
        // the fields of the IP block are shown only while it is on
        $this->assertStringContainsString('login_ip_block&quot;,&quot;value&quot;:&quot;1&quot;', $content);
        // the limit of 2factor codes: the fields are shown only while it is on
        $this->assertStringContainsString(exmtrans('login.login_2factor_lock_header'), $content);
        $this->assertStringContainsString('login_2factor_lock&quot;,&quot;value&quot;:&quot;1&quot;', $content);
    }

    /**
     * Save: the values are saved. A setting not posted (hidden while its switch is off) keeps the saved value.
     */
    public function testSaveSettings(): void
    {
        $this->loginAsAdmin();
        $this->postSetting([
            'login_failure_minutes' => '10',
            'login_ip_block' => '1',
            'login_ip_block_failures' => '20',
            'login_ip_block_minutes' => '15',
            'login_ip_block_progressive' => '1',
            'login_ip_block_minutes_2' => '60',
            'login_ip_block_minutes_3' => '10080',
            'login_ip_block_allowed_ips' => " 210.140.20.0/24 \r\n\r\n2400:4050::/32\r\n",
            'login_ip_block_notify' => '0',
            'login_captcha' => '1',
            'login_captcha_failures' => '1',
            'login_multi_ip_warning' => '0',
            'login_multi_ip_count' => '2',
            'login_multi_ip_minutes' => '1440',
            'login_2factor_lock' => '1',
            'login_2factor_lock_failures' => '3',
            'login_2factor_lock_minutes' => '30',
            'login_brute_force_notify_mail' => '1',
        ])->assertRedirect(admin_url('login_setting'));
        System::clearCache();

        $this->assertSame([
            'login_failure_minutes' => 10,
            'login_ip_block' => true,
            'login_ip_block_failures' => 20,
            'login_ip_block_minutes' => 15,
            'login_ip_block_progressive' => true,
            'login_ip_block_minutes_2' => 60,
            'login_ip_block_minutes_3' => 10080,
            'login_ip_block_allowed_ips' => "210.140.20.0/24\r\n2400:4050::/32",
            'login_ip_block_notify' => false,
            'login_captcha' => true,
            'login_captcha_failures' => 1,
            'login_multi_ip_warning' => false,
            'login_multi_ip_count' => 2,
            'login_multi_ip_minutes' => 1440,
            'login_2factor_lock' => true,
            'login_2factor_lock_failures' => 3,
            'login_2factor_lock_minutes' => 30,
            'login_brute_force_notify_mail' => true,
        ], System::get_system_values([LoginIpBlockService::SETTING_GROUP]));

        // switches off: the hidden fields are not posted, and keep the saved values
        $this->postSetting([
            'login_failure_minutes' => '10',
            'login_ip_block' => '0',
            'login_captcha' => '0',
            'login_multi_ip_warning' => '0',
            'login_2factor_lock' => '0',
            'login_brute_force_notify_mail' => '0',
        ])->assertRedirect(admin_url('login_setting'));
        System::clearCache();
        $this->assertFalse(System::login_ip_block());
        $this->assertSame(20, System::login_ip_block_failures());
        $this->assertSame(15, System::login_ip_block_minutes());
        $this->assertTrue(System::login_ip_block_progressive());
        $this->assertSame("210.140.20.0/24\r\n2400:4050::/32", System::login_ip_block_allowed_ips());
        $this->assertFalse(System::login_ip_block_notify());
        $this->assertSame(1, System::login_captcha_failures());
        $this->assertFalse(System::login_2factor_lock());
        $this->assertFalse(Auth2factorLockService::isEnabled());
        $this->assertSame(3, System::login_2factor_lock_failures());
        $this->assertSame(30, System::login_2factor_lock_minutes());
    }

    /**
     * Not saved: out of the range, not an integer, a later block shorter than the earlier one, a wrong IP address.
     * Nothing is saved, and the entered values are kept on the form.
     *
     * @param array<string, string> $data
     */
    #[DataProvider('invalidSettingProvider')]
    public function testInvalidSettingsAreNotSaved(array $data, string $errorKey): void
    {
        $before = System::get_system_values([LoginIpBlockService::SETTING_GROUP]);

        $this->loginAsAdmin();
        $this->postSetting(array_merge(['login_ip_block' => '1', 'login_ip_block_progressive' => '1'], $data))
            ->assertRedirect(admin_url('login_setting'))
            ->assertSessionHasErrors($errorKey);
        System::clearCache();
        $this->assertSame($before, System::get_system_values([LoginIpBlockService::SETTING_GROUP]));

        $this->get(admin_url('login_setting'))->assertStatus(200)->assertSee($data[$errorKey], false);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidSettingProvider(): array
    {
        return [
            'period 0' => [['login_failure_minutes' => '0'], 'login_failure_minutes'],
            'period over max' => [['login_failure_minutes' => '1441'], 'login_failure_minutes'],
            'failures 0' => [['login_ip_block_failures' => '0'], 'login_ip_block_failures'],
            'failures over max' => [['login_ip_block_failures' => '1001'], 'login_ip_block_failures'],
            'failures not integer' => [['login_ip_block_failures' => '1.5'], 'login_ip_block_failures'],
            'failures negative' => [['login_ip_block_failures' => '-3'], 'login_ip_block_failures'],
            'block time over max' => [['login_ip_block_minutes' => '10081'], 'login_ip_block_minutes'],
            'block time text' => [['login_ip_block_minutes' => 'abc'], 'login_ip_block_minutes'],
            '2nd shorter' => [['login_ip_block_minutes' => '30', 'login_ip_block_minutes_2' => '20', 'login_ip_block_minutes_3' => '40'], 'login_ip_block_minutes_2'],
            '3rd shorter' => [['login_ip_block_minutes' => '5', 'login_ip_block_minutes_2' => '30', 'login_ip_block_minutes_3' => '29'], 'login_ip_block_minutes_3'],
            'wrong IP address' => [['login_ip_block_allowed_ips' => "210.140.20.0/24\r\n999.1.1.1"], 'login_ip_block_allowed_ips'],
            'captcha failures 0' => [['login_captcha_failures' => '0'], 'login_captcha_failures'],
            'multi IP count 1' => [['login_multi_ip_count' => '1'], 'login_multi_ip_count'],
            'multi IP minutes over max' => [['login_multi_ip_minutes' => '10081'], 'login_multi_ip_minutes'],
            '2factor failures 0' => [['login_2factor_lock_failures' => '0'], 'login_2factor_lock_failures'],
            '2factor failures over max' => [['login_2factor_lock_failures' => '101'], 'login_2factor_lock_failures'],
            '2factor failures not integer' => [['login_2factor_lock_failures' => '2.5'], 'login_2factor_lock_failures'],
            '2factor minutes 0' => [['login_2factor_lock_minutes' => '0'], 'login_2factor_lock_minutes'],
            '2factor minutes over max' => [['login_2factor_lock_minutes' => '1441'], 'login_2factor_lock_minutes'],
            '2factor minutes negative' => [['login_2factor_lock_minutes' => '-15'], 'login_2factor_lock_minutes'],
        ];
    }

    /**
     * The boundaries are saved: minimum and maximum. The same time for all the blocks is allowed.
     */
    public function testBoundarySettingsAreSaved(): void
    {
        $this->loginAsAdmin();
        $this->postSetting([
            'login_failure_minutes' => '1440',
            'login_ip_block' => '1',
            'login_ip_block_failures' => '1000',
            'login_ip_block_minutes' => '10080',
            'login_ip_block_progressive' => '1',
            'login_ip_block_minutes_2' => '10080',
            'login_ip_block_minutes_3' => '10080',
            'login_captcha_failures' => '1000',
            'login_multi_ip_count' => '1000',
            'login_multi_ip_minutes' => '10080',
            'login_2factor_lock_failures' => '100',
            'login_2factor_lock_minutes' => '1440',
        ])->assertSessionHasNoErrors()->assertRedirect(admin_url('login_setting'));
        System::clearCache();
        $this->assertSame(100, System::login_2factor_lock_failures());
        $this->assertSame(1440, System::login_2factor_lock_minutes());

        $this->postSetting([
            'login_failure_minutes' => '1',
            'login_ip_block' => '1',
            'login_ip_block_failures' => '1',
            'login_ip_block_minutes' => '1',
            'login_ip_block_progressive' => '1',
            'login_ip_block_minutes_2' => '1',
            'login_ip_block_minutes_3' => '1',
            'login_captcha_failures' => '1',
            'login_multi_ip_count' => '2',
            'login_multi_ip_minutes' => '1',
            'login_2factor_lock_failures' => '1',
            'login_2factor_lock_minutes' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(admin_url('login_setting'));
        System::clearCache();
        $this->assertSame(1, System::login_ip_block_failures());
        $this->assertSame(2, System::login_multi_ip_count());
        $this->assertSame(1, System::login_2factor_lock_failures());
        $this->assertSame(1, System::login_2factor_lock_minutes());
    }

    // ------------------------------------------------------------------ //
    //  CAPTCHA                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Off (default): never shown, never required.
     */
    public function testCaptchaOff(): void
    {
        $login_user = $this->createLoginUser();
        $this->failLogin(5, self::IP, $login_user->user_code);

        $this->withServerVariables(['REMOTE_ADDR' => self::IP])->get(admin_url('auth/login'))->assertStatus(200)->assertDontSee('name="captcha"', false);
        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP);
        $this->assertLoggedIn();
    }

    /**
     * On: shown after 3 failures of the IP address (2: not shown). Without the right characters the password is not checked,
     * and the wrong CAPTCHA is a failure of the IP address. With them, the login succeeds and CAPTCHA is not shown any more.
     */
    public function testCaptchaAfterFailuresOfTheIp(): void
    {
        System::login_captcha(true);
        $login_user = $this->createLoginUser();

        $this->failLogin(2, self::IP, $login_user->user_code);
        $this->loginPage(self::IP)->assertDontSee('name="captcha"', false);

        $this->failLogin(1, self::IP, $login_user->user_code);
        $this->loginPage(self::IP)
            ->assertSee('name="captcha"', false)
            ->assertSee(admin_url('auth/captcha'))
            ->assertSee(exmtrans('login.captcha_description'));

        // the right password without CAPTCHA: refused
        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP, [], false)
            ->assertSessionHasErrors(['captcha' => exmtrans('login.message.captcha_failed')]);
        $this->assertNotLoggedIn();
        $this->assertSame(1, $this->failures(self::IP)->where('reason', LoginFailure::REASON_CAPTCHA)->count());
        $this->assertNull(session()->getOldInput('password'), 'the password is never kept');

        // wrong characters: refused
        $this->captchaCode(self::IP);
        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP, ['captcha' => 'XXXXX'], false)
            ->assertSessionHasErrors('captcha');
        $this->assertNotLoggedIn();

        // the right characters (case and spaces ignored) and the right password
        $code = $this->captchaCode(self::IP);
        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP, ['captcha' => ' ' . strtolower($code) . ' '], false);
        $this->assertLoggedIn();
        $this->assertNull(session(Define::SYSTEM_KEY_SESSION_LOGIN_CAPTCHA_REQUIRED), 'logged in: not required any more in this session');
    }

    /**
     * The right characters and a wrong password: a failure of the password. A code is used only once, and expires in 10 minutes.
     */
    public function testCaptchaCodeIsOneTime(): void
    {
        System::login_captcha(true);
        $login_user = $this->createLoginUser();
        $this->failLogin(3, self::IP, $login_user->user_code);

        $code = $this->captchaCode(self::IP);
        $this->postLogin($login_user->user_code, 'wrong-password', self::IP, ['captcha' => $code], false)
            ->assertSessionHasErrors(['username' => exmtrans('error.login_failed')]);
        $this->assertSame(4, $this->failures(self::IP)->where('reason', LoginFailure::REASON_PASSWORD)->count());

        // the same code again
        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP, ['captcha' => $code], false)->assertSessionHasErrors('captcha');
        $this->assertNotLoggedIn();

        // expired
        $code = $this->captchaCode(self::IP);
        $this->travelSeconds(10 * 60 + 1);
        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP, ['captcha' => $code], false)->assertSessionHasErrors('captcha');
        $this->assertNotLoggedIn();
    }

    /**
     * A distributed attack: wrong passwords of the same ID from many IP addresses. A new IP address (new browser) is required CAPTCHA
     * when it posts the ID. Also the user is required after wrong passwords with another ID of the user (e-mail).
     */
    public function testCaptchaAfterFailuresOfTheId(): void
    {
        System::login_captcha(true);
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user->user_code, 'wrong-password', '133.242.11.1');
        $this->postLogin($login_user->user_code, 'wrong-password', '133.242.11.2');
        $this->postLogin($login_user->user_code, 'wrong-password', '133.242.11.3');

        // the login page of a new IP address does not know the ID yet
        $this->loginPage('133.242.11.4')->assertDontSee('name="captcha"', false);
        // posting the ID: required
        $this->postLogin($login_user->user_code, self::PASSWORD, '133.242.11.4')->assertSessionHasErrors('captcha');
        $this->assertNotLoggedIn();
        $this->loginPage('133.242.11.4', false)->assertSee('name="captcha"', false);

        // another ID of the user: required in the session after its wrong password
        $other = $this->createLoginUser();
        $this->postLogin($other->email, 'wrong-password', '133.242.12.1');
        $this->postLogin($other->user_code, 'wrong-password', '133.242.12.2');
        $this->postLogin($other->email, 'wrong-password', '133.242.12.3', [], true);
        $this->loginPage('133.242.12.3', false)->assertSee('name="captcha"', false);
    }

    /**
     * "IP addresses never blocked" are never required CAPTCHA.
     */
    public function testCaptchaIsNotShownToAllowedIps(): void
    {
        System::login_captcha(true);
        System::login_ip_block_allowed_ips(self::IP_OFFICE);
        $login_user = $this->createLoginUser();

        $this->failLogin(5, self::IP_OFFICE, $login_user->user_code);
        $this->loginPage(self::IP_OFFICE, false)->assertDontSee('name="captcha"', false);
        $this->postLogin($login_user->user_code, self::PASSWORD, self::IP_OFFICE, [], false);
        $this->assertLoggedIn();
    }

    /**
     * Wrong CAPTCHA is counted for the IP block: a bot that cannot read the image is blocked.
     */
    public function testWrongCaptchaBlocksTheIp(): void
    {
        System::login_captcha(true);
        $this->failLogin(3, self::IP);
        for ($i = 0; $i < 6; $i++) {
            $this->postLogin('zz_bf_bot', 'wrong-password', self::IP)->assertSessionHasErrors('captcha');
        }
        $this->assertNull($this->blockOf(self::IP));
        $this->postLogin('zz_bf_bot', 'wrong-password', self::IP)
            ->assertSessionHasErrors(['username' => exmtrans('login.message.ip_blocked', 5)]);
        $this->assertNotNull($this->blockOf(self::IP));
    }

    /**
     * The image: PNG of 160x50, a new code each time, not cached.
     */
    public function testCaptchaImage(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::IP])->get(admin_url('auth/captcha'));
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('no-store', (string)$response->headers->get('Cache-Control'));

        $size = getimagesizefromstring((string)$response->getContent());
        $this->assertIsArray($size);
        $this->assertSame([160, 50], [$size[0], $size[1]]);

        $first = session(Define::SYSTEM_KEY_SESSION_LOGIN_CAPTCHA);
        $this->assertIsArray($first);
        $this->assertMatchesRegularExpression('/^[' . LoginCaptchaService::CODE_CHARS . ']{5}$/', $first['code']);

        $codes = [];
        for ($i = 0; $i < 5; $i++) {
            $this->get(admin_url('auth/captcha'))->assertStatus(200);
            $codes[] = session(Define::SYSTEM_KEY_SESSION_LOGIN_CAPTCHA)['code'];
        }
        $this->assertGreaterThan(1, count(array_unique($codes)), 'a new code each time');
    }

    /**
     * Not saved: CAPTCHA on without GD is checked. (GD is installed here: the check is the availability itself)
     */
    public function testCaptchaIsAvailableWithGd(): void
    {
        $this->assertSame(function_exists('imagecreatetruecolor'), LoginCaptchaService::isAvailable());
    }

    // ------------------------------------------------------------------ //
    //  Warning of many IP addresses                                       //
    // ------------------------------------------------------------------ //

    /**
     * Wrong passwords of a user from 5 IP addresses in 60 minutes: warned once (linked to the user). 4 IP addresses: not warned.
     * The user is counted whichever ID is entered.
     */
    public function testWarningOfManyIpAddresses(): void
    {
        $admin = $this->createAdmin();
        $login_user = $this->createLoginUser();

        foreach (['133.242.13.1', '133.242.13.2', '133.242.13.3'] as $ip) {
            $this->postLogin($login_user->user_code, 'wrong-password', $ip);
        }
        $this->postLogin($login_user->email, 'wrong-password', '133.242.13.4');
        $this->postLogin($login_user->user_code, 'wrong-password', '133.242.13.4');
        $this->assertCount(0, $this->notifiesOf($admin), '4 IP addresses');

        $this->postLogin($login_user->email, 'wrong-password', '133.242.13.5');
        $notifies = $this->notifiesOf($admin);
        $this->assertCount(1, $notifies);
        $this->assertSame(SystemTableName::USER, $notifies[0]->parent_type);
        $this->assertEquals($login_user->base_user_id, $notifies[0]->parent_id);
        $this->assertSame(exmtrans('login_failure.notify.subject', $login_user->user_name), $notifies[0]->notify_subject);
        $this->assertStringContainsString('133.242.13.5', (string)$notifies[0]->notify_body);
        $this->assertStringContainsString('133.242.13.1', (string)$notifies[0]->notify_body);
        $this->assertSame(1, LoginFailure::query()->where('base_user_id', $login_user->base_user_id)->where('multi_ip_warned', true)->count());

        // once in the period
        $this->postLogin($login_user->user_code, 'wrong-password', '133.242.13.6');
        $this->assertCount(1, $this->notifiesOf($admin));

        // after the period: warned again
        $this->travelSeconds(61 * 60);
        foreach (['133.242.14.1', '133.242.14.2', '133.242.14.3', '133.242.14.4', '133.242.14.5'] as $ip) {
            $this->postLogin($login_user->user_code, 'wrong-password', $ip);
        }
        $this->assertCount(2, $this->notifiesOf($admin));
    }

    /**
     * Not warned: off, an ID of nobody, the same IP address many times, failures of CAPTCHA.
     */
    public function testNoWarningOfManyIpAddresses(): void
    {
        $admin = $this->createAdmin();
        $login_user = $this->createLoginUser();

        System::login_multi_ip_warning(false);
        foreach (['133.242.15.1', '133.242.15.2', '133.242.15.3', '133.242.15.4', '133.242.15.5'] as $ip) {
            $this->postLogin($login_user->user_code, 'wrong-password', $ip);
        }
        $this->assertCount(0, $this->notifiesOf($admin));

        System::login_multi_ip_warning(true);
        foreach (['133.242.16.1', '133.242.16.2', '133.242.16.3', '133.242.16.4', '133.242.16.5'] as $ip) {
            $this->postLogin('zz_bf_nobody', 'wrong-password', $ip);
        }
        $this->assertCount(0, $this->notifiesOf($admin), 'nobody');

        $other = $this->createLoginUser();
        $this->failLogin(9, self::IP, $other->user_code);
        $this->assertCount(0, $this->notifiesOf($admin), '1 IP address');
    }

    // ------------------------------------------------------------------ //
    //  Before "exment:update"                                             //
    // ------------------------------------------------------------------ //

    /**
     * The new version is installed, but the tables are not created yet (before "exment:update"): even with the settings on,
     * the login works as before. Wrong passwords are refused as before, nothing is blocked, CAPTCHA is never required,
     * and the schedule and the setting page have no error.
     */
    public function testBeforeMigration(): void
    {
        System::login_captcha(true);
        System::login_ip_block_failures(3);
        System::login_captcha_failures(1);
        $admin = $this->createAdmin();
        $login_user = $this->createLoginUser();
        $this->hideBruteForceTables();

        $this->assertFalse(app(LoginIpBlockService::class)->isEnabled());
        $this->assertFalse(app(LoginCaptchaService::class)->isEnabled());
        $this->assertFalse(Auth2factorLockService::isEnabled());

        for ($i = 0; $i < 5; $i++) {
            $this->postLogin($login_user->user_code, 'wrong-password', '133.242.18.' . ($i + 1))
                ->assertSessionHasErrors(['username' => exmtrans('error.login_failed')])
                ->assertSessionDoesntHaveErrors('captcha');
        }
        $this->loginPage('133.242.18.1')->assertDontSee('name="captcha"', false);
        $this->assertCount(0, $this->notifiesOf($admin), 'no warning of many IP addresses');

        $this->postLogin($login_user->user_code, self::PASSWORD, '133.242.18.1');
        $this->assertLoggedIn();

        $this->assertSame(0, LoginIpBlockService::deleteExpired());
        $this->assertSame(0, LoginFailureService::deleteOld());

        $this->loginAs($admin);
        $this->hideBruteForceTables();
        $this->get(admin_url('login_setting'))->assertStatus(200)->assertSee(exmtrans('login.brute_force'));

        // (nothing was written to the tables, which exist in this test database)
        $this->assertSame(0, LoginFailure::query()->count());
        $this->assertSame(0, LoginIpBlock::query()->count());
    }

    // ------------------------------------------------------------------ //
    //  Cleanup                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Schedule: failures older than 7 days, and blocks ended more than 24 hours ago are deleted.
     */
    public function testOldRecordsAreDeleted(): void
    {
        $this->travelSeconds(-8 * 24 * 60 * 60);
        $this->failLogin(1, '133.242.17.1');
        Carbon::setTestNow();
        $this->failLogin(1, '133.242.17.2');

        $old = $this->createBlock('133.242.17.3', -(25 * 60));
        $recent = $this->createBlock('133.242.17.4', -(23 * 60));
        $active = $this->createBlock('133.242.17.5', 5);

        $method = new \ReflectionMethod(ScheduleCommand::class, 'clearLoginFailures');
        $method->setAccessible(true);
        $method->invoke(app(ScheduleCommand::class));

        $this->assertSame(0, $this->failures('133.242.17.1')->count());
        $this->assertSame(1, $this->failures('133.242.17.2')->count());
        $this->assertNull(LoginIpBlock::find($old->id));
        $this->assertNotNull(LoginIpBlock::find($recent->id), 'kept to make the next block longer');
        $this->assertNotNull(LoginIpBlock::find($active->id));
    }

    /**
     * "refresh data" deletes the failed logins and the blocks too.
     */
    public function testRefreshDataTruncatesTheTables(): void
    {
        $tables = RefreshDataService::getTruncateSystemTables();
        $this->assertContains(SystemTableName::LOGIN_FAILURE, $tables);
        $this->assertContains(SystemTableName::LOGIN_IP_BLOCK, $tables);
        $this->assertContains(SystemTableName::LOGIN_HISTORY, $tables);
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Post the login form.
     *
     * @param array<string, mixed> $data additional post data
     * @param bool $newBrowser true: forget the session (a new browser). false: same session (Ex. after getting CAPTCHA)
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postLogin(string $username, string $password, string $ip, array $data = [], bool $newBrowser = true): TestResponse
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->keepHiddenTables();
        if ($newBrowser) {
            $this->flushSession();
        }

        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from(admin_url('auth/login'))
            ->post(admin_url('auth/login'), array_merge([
                'username' => $username,
                'password' => $password,
            ], $data));
    }

    /**
     * Wrong passwords.
     */
    protected function failLogin(int $count, string $ip, string $username = 'zz_bf_user', bool $newBrowser = true): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->postLogin($username, 'wrong-password', $ip, [], $newBrowser);
        }
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function loginPage(string $ip, bool $newBrowser = true): TestResponse
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->keepHiddenTables();
        if ($newBrowser) {
            $this->flushSession();
        }
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->get(admin_url('auth/login'))->assertStatus(200);
    }

    /**
     * Get a CAPTCHA image in this session, and return its code.
     */
    protected function captchaCode(string $ip): string
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->get(admin_url('auth/captcha'))->assertStatus(200);
        $stored = session(Define::SYSTEM_KEY_SESSION_LOGIN_CAPTCHA);
        $this->assertIsArray($stored);
        $this->assertIsString($stored['code']);
        return $stored['code'];
    }

    /**
     * @param array<string, mixed> $data
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postSetting(array $data): TestResponse
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->from(admin_url('login_setting'))
            ->post(route('exment.post_login_brute_force'), $data);
    }

    protected function travelSeconds(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
    }

    /**
     * Table names of the database without the tables of this feature. (null: as it is)
     *
     * @var array<string>|null
     */
    protected ?array $tableNamesBeforeMigration = null;

    /**
     * Make hasTable() answer that the tables of this feature do not exist. (as before "exment:update")
     * Kept after System::clearRequestSession() of the helpers of this test.
     */
    protected function hideBruteForceTables(): void
    {
        System::clearRequestSession(Define::SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES);
        hasTable(SystemTableName::LOGIN_FAILURE);
        $tables = System::requestSession(Define::SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES);
        $this->assertIsArray($tables);
        $this->assertContains(SystemTableName::LOGIN_FAILURE, $tables);

        $this->tableNamesBeforeMigration = array_values(array_diff($tables, [SystemTableName::LOGIN_FAILURE, SystemTableName::LOGIN_IP_BLOCK]));
        $this->keepHiddenTables();
        $this->assertFalse(hasTable(SystemTableName::LOGIN_FAILURE));
        $this->assertFalse(hasTable(SystemTableName::LOGIN_IP_BLOCK));
    }

    protected function keepHiddenTables(): void
    {
        if (!is_null($this->tableNamesBeforeMigration)) {
            System::setRequestSession(Define::SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES, $this->tableNamesBeforeMigration);
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<LoginFailure>
     */
    protected function failures(string $ip): \Illuminate\Database\Eloquent\Builder
    {
        return LoginFailure::query()->where('ip_key', LoginIpBlockService::getIpKey($ip));
    }

    /**
     * @phpstan-impure
     */
    protected function blockOf(string $ip): ?LoginIpBlock
    {
        return LoginIpBlock::query()->where('ip_key', LoginIpBlockService::getIpKey($ip))->first();
    }

    /**
     * @param int $minutes minutes until the block ends. negative: ended.
     */
    protected function createBlock(string $ip, int $minutes): LoginIpBlock
    {
        $block = new LoginIpBlock();
        $block->ip_key = LoginIpBlockService::getIpKey($ip);
        $block->ip_address = $ip;
        $block->block_level = 1;
        $block->failed_count = 10;
        $block->blocked_at = Carbon::now()->addMinutes($minutes)->subMinutes(5);
        $block->blocked_until = Carbon::now()->addMinutes($minutes);
        $block->save();
        return $block;
    }

    protected function assertLoggedIn(): void
    {
        $this->assertTrue(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check(), 'login must succeed');
    }

    protected function assertNotLoggedIn(): void
    {
        $this->assertFalse(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check(), 'must not be logged in');
    }

    /**
     * LDAP login setting "bfldap". The server does not exist: LDAP always ends with an error.
     */
    protected function createLdapSetting(): void
    {
        $login_setting = new \Exceedone\Exment\Model\LoginSetting();
        $login_setting->login_type = LoginType::LDAP;
        $login_setting->login_view_name = 'bf_ldap';
        $login_setting->active_flg = true;
        $login_setting->options = ['ldap_name' => 'bfldap', 'ldap_hosts' => '127.0.0.1', 'ldap_port' => 1, 'ldap_base_dn' => 'dc=example,dc=com'];
        $login_setting->save();
        System::clearCache();
    }

    /**
     * A system administrator (to be notified) with a mail address.
     */
    protected function createAdmin(): LoginUser
    {
        $admin = $this->createLoginUser();
        System::system_admin_users([$admin->base_user_id]);
        return $admin;
    }

    /**
     * Notifications to the user, in created order.
     *
     * @return \Illuminate\Support\Collection<int, NotifyNavbar>
     */
    protected function notifiesOf(LoginUser $login_user): \Illuminate\Support\Collection
    {
        return NotifyNavbar::withoutGlobalScopes()
            ->where('target_user_id', $login_user->base_user_id)
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

    protected function loginAs(LoginUser $login_user): void
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->be($login_user, Define::AUTHENTICATE_KEY_WEB);
    }

    /**
     * Log in as a new system administrator. (setUp clears the system administrators, so that only the test's are notified)
     */
    protected function loginAsAdmin(): LoginUser
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        return $admin;
    }

    /**
     * Put the user into a new role group that has the system permissions.
     *
     * @param array<string> $permissions
     */
    protected function grantSystemPermission(LoginUser $login_user, array $permissions): void
    {
        $name = 'bftest_role_' . short_uuid();
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
        System::clearCache();
    }

    /**
     * Create a user (custom value) and its login user. No role group is assigned.
     */
    protected function createLoginUser(): LoginUser
    {
        $code = 'bftest_' . short_uuid();

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
        $login_user->login_type = LoginType::PURE;
        $login_user->password = self::PASSWORD;
        $login_user->save();

        $login_user = LoginUser::find($login_user->id);
        $this->assertInstanceOf(LoginUser::class, $login_user);
        return $login_user;
    }
}
