<?php

namespace Exceedone\Exment\Tests\Feature;

use Illuminate\Auth\Events\Login;
use Illuminate\Testing\TestResponse;
use Exceedone\Exment\Controllers\LoginHistoryController;
use Exceedone\Exment\DataItems\Show\UserLoginHistoryGrid;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemRoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginHistory;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Providers\RouteServiceProvider;
use Exceedone\Exment\Services\DataImportExport\Providers\Export\LoginHistoryProvider;
use Exceedone\Exment\Services\GeoIp\CountryList;
use Exceedone\Exment\Services\GeoIp\GeoIpService;
use Exceedone\Exment\Services\Login\LoginCountryRestrictionService;
use Exceedone\Exment\Services\Login\LoginHistoryService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Login restriction by country: login only from the allowed countries, judged by the IP address.
 * Blocked attempts are recorded in the login history.
 *
 * The country of an IP address is given by the test (see fakeCountries()), so the tests do not depend on the GeoIP database.
 * This test creates its own users / settings and rolls everything back. (no `exment:inittest` data needed)
 */
class LoginCountryRestrictionTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const PASSWORD = 'LoginCountry-2026!';
    protected const IP_JP = '133.242.0.1';
    protected const IP_US = '8.8.8.8';
    protected const IP_CN = '1.24.0.1';
    protected const IP_UNKNOWN = '5.5.5.5';
    protected const IP_PRIVATE = '192.168.1.10';

    /**
     * @var LoginCountryRestrictionService&object{lookups: int}
     */
    protected $service;

    /**
     * The last login history id before the test. (The blocked attempts of the test are after it.)
     *
     * @var int
     */
    protected $maxHistoryId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->maxHistoryId = (int)LoginHistory::max('id');

        config(['exment.login_use_2factor' => false]);
        System::login_history_notify_new_ip(true);
        System::login_history_notify_mail(false);

        $this->fakeCountries([
            self::IP_JP => 'JP',
            self::IP_US => 'US',
            self::IP_CN => 'CN',
        ]);

        // default: off
        System::login_country_restriction(false);
        System::login_country_allowed([]);
        System::login_country_unknown_allowed(true);
        System::login_country_allowed_ips('');
    }

    // ------------------------------------------------------------------ //
    //  Judgement                                                          //
    // ------------------------------------------------------------------ //

    /**
     * Default settings: off, unknown country allowed, nothing allowed yet.
     */
    public function testDefaultSettings(): void
    {
        foreach (array_keys(System::get_system_values([LoginCountryRestrictionService::SETTING_GROUP])) as $key) {
            \DB::table(SystemTableName::SYSTEM)->where('system_name', $key)->delete();
        }
        System::clearCache();

        $this->assertSame([
            'enabled' => false,
            'countries' => [],
            'unknown_allowed' => true,
            'ips' => [],
        ], app(LoginCountryRestrictionService::class)->getSettings());
    }

    /**
     * Judgement in order: off / always allowed IP / private IP / unknown country / allowed country.
     */
    public function testIsAllowedBy(): void
    {
        $settings = LoginCountryRestrictionService::makeSettings(true, ['JP'], false, "23.45.67.89\n2400:4000::/32\n8.8.4.0/24");

        // allowed country, other country
        $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($settings, self::IP_JP, 'JP'));
        $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($settings, self::IP_JP, 'jp'), 'country code is case-insensitive');
        $this->assertFalse(LoginCountryRestrictionService::isAllowedBy($settings, self::IP_US, 'US'));

        // unknown country: by the setting
        $this->assertFalse(LoginCountryRestrictionService::isAllowedBy($settings, self::IP_UNKNOWN, null));
        $this->assertFalse(LoginCountryRestrictionService::isAllowedBy($settings, self::IP_UNKNOWN, ''));
        $this->assertFalse(LoginCountryRestrictionService::isAllowedBy($settings, null, null), 'no IP address is unknown');
        $this->assertFalse(LoginCountryRestrictionService::isAllowedBy($settings, 'not-an-ip', null));
        $allowUnknown = array_merge($settings, ['unknown_allowed' => true]);
        $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($allowUnknown, self::IP_UNKNOWN, null));
        $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($allowUnknown, null, null));
        $this->assertFalse(LoginCountryRestrictionService::isAllowedBy($allowUnknown, self::IP_US, 'US'), 'a known country is not "unknown"');

        // internal network: always allowed, even if the country were judged as not allowed
        foreach (['10.1.2.3', '172.16.0.1', '172.31.255.255', self::IP_PRIVATE, '127.0.0.1', '100.64.0.1', '169.254.1.1', '::1', 'fc00::1', 'fd12:3456::1', 'fe80::1', '::ffff:192.168.1.10'] as $ip) {
            $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($settings, $ip, null), "private: {$ip}");
            $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($settings, $ip, 'US'), "private: {$ip}");
        }

        // tunnel addresses (6to4, Teredo) and the other special addresses are not internal: judged by the country / as unknown
        foreach (['2002:c000:204::1', '2001:0:4136:e378::1', '192.0.2.1', '2001:db8::1'] as $ip) {
            $this->assertFalse(LoginCountryRestrictionService::isAllowedBy($settings, $ip, 'US'), "not internal: {$ip}");
            $this->assertFalse(LoginCountryRestrictionService::isAllowedBy($settings, $ip, null), "not internal: {$ip}");
        }

        $this->assertFalse(LoginCountryRestrictionService::isAllowedBy($settings, '172.32.0.1', 'US'), 'next to 172.16.0.0/12');

        // IPv4-mapped IPv6 address of a global address is judged by the country
        $this->assertFalse(LoginCountryRestrictionService::isAllowedBy($settings, '::ffff:8.8.8.8', 'US'));
        $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($settings, '::ffff:133.242.0.1', 'JP'));

        // always allowed IP addresses: single, IPv4 CIDR, IPv6 CIDR. They win over the country and over "unknown".
        $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($settings, '23.45.67.89', 'US'));
        $this->assertFalse(LoginCountryRestrictionService::isAllowedBy($settings, '23.45.67.90', 'US'), 'only the address itself');
        $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($settings, '8.8.4.4', 'US'));
        $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($settings, '8.8.4.4', null));
        $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($settings, '2400:4000:1::1', 'CN'));
        $this->assertFalse(LoginCountryRestrictionService::isAllowedBy($settings, '2400:4001::1', 'CN'));
        $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($settings, '::ffff:23.45.67.89', 'US'), 'IPv4-mapped address of an allowed IP');

        // no allowed country: only the exceptions
        $none = LoginCountryRestrictionService::makeSettings(true, [], true, '');
        $this->assertFalse(LoginCountryRestrictionService::isAllowedBy($none, self::IP_JP, 'JP'));

        // off: everything allowed
        $off = array_merge($settings, ['enabled' => false]);
        $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($off, self::IP_US, 'US'));
        $this->assertTrue(LoginCountryRestrictionService::isAllowedBy($off, null, null));
    }

    /**
     * Settings are normalized: upper case, unique, no empty value. IP addresses: 1 per line, any line feed, blank lines ignored.
     */
    public function testMakeSettings(): void
    {
        $settings = LoginCountryRestrictionService::makeSettings(true, ['jp', ' US ', 'JP', '', null, 1], false, "  198.51.100.7 \r\n\r\n2001:db8::/32\n\n");

        $this->assertSame(['JP', 'US'], $settings['countries']);
        $this->assertSame(['198.51.100.7', '2001:db8::/32'], $settings['ips']);
        $this->assertTrue($settings['enabled']);
        $this->assertFalse($settings['unknown_allowed']);

        $this->assertSame([], LoginCountryRestrictionService::makeSettings(false, null, true, null)['countries']);
        $this->assertSame([], LoginCountryRestrictionService::makeSettings(false, 'JP', true, ['x'])['ips'], 'not a text');
        $this->assertSame(['JP'], LoginCountryRestrictionService::makeSettings(false, 'JP', true, '')['countries'], 'a single value');
    }

    /**
     * Lines of "IP addresses always allowed" that are not an IP address nor CIDR.
     */
    public function testInvalidIps(): void
    {
        $valid = "192.0.2.1\n192.0.2.0/24\n0.0.0.0/0\n10.0.0.0/32\n2001:db8::1\n2001:db8::/32\n::/0\n::ffff:192.0.2.1";
        $this->assertSame([], LoginCountryRestrictionService::getInvalidIps($valid));
        $this->assertSame([], LoginCountryRestrictionService::getInvalidIps(''));
        $this->assertSame([], LoginCountryRestrictionService::getInvalidIps(null));

        $this->assertSame(
            ['abc', '192.0.2.256', '192.0.2.0/33', '192.0.2.0/', '192.0.2.0/x', '192.0.2.0/-1', '2001:db8::/129', '192.0.2.1 192.0.2.2', 'example.com'],
            LoginCountryRestrictionService::getInvalidIps("abc\n192.0.2.1\n192.0.2.256\n192.0.2.0/33\n192.0.2.0/\n192.0.2.0/x\n192.0.2.0/-1\n2001:db8::/129\n192.0.2.1 192.0.2.2\nexample.com")
        );
    }

    /**
     * The GeoIP database is not looked up while the restriction is off.
     */
    public function testNoLookupWhileOff(): void
    {
        $result = $this->service->check(self::IP_US);

        $this->assertTrue($result['allowed']);
        $this->assertSame(0, $this->service->lookups);
        $this->assertNull($result['geo']['country_code']);

        $this->enable(['JP']);
        $result = $this->service->check(self::IP_US);
        $this->assertFalse($result['allowed']);
        $this->assertSame(1, $this->service->lookups);
        $this->assertSame('US', $result['geo']['country_code']);
    }

    /**
     * Without the fake, the country comes from the GeoIP database. (skipped if the database is not placed)
     */
    public function testRealGeoIpLookup(): void
    {
        $this->app->forgetInstance(LoginCountryRestrictionService::class);
        $service = app(LoginCountryRestrictionService::class);
        $this->assertSame(LoginCountryRestrictionService::class, get_class($service));

        $this->assertSame(GeoIpService::lookup(self::IP_PRIVATE), $service->lookup(self::IP_PRIVATE));
        if (!GeoIpService::isAvailable()) {
            $this->markTestSkipped('GeoIP database is not placed in this environment.');
        }

        $this->assertSame('US', $service->lookup(self::IP_US)['country_code']);
        $this->assertTrue(CountryList::exists($service->lookup(self::IP_US)['country_code']));
    }

    // ------------------------------------------------------------------ //
    //  Login form                                                         //
    // ------------------------------------------------------------------ //

    /**
     * Off (default): login from any country, nothing looked up.
     */
    public function testRestrictionOffAllowsAnyCountry(): void
    {
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user, self::IP_US)->assertRedirect();

        $this->assertLoggedIn();
        $this->assertSame(0, $this->service->lookups);
        $histories = $this->historiesOf($login_user);
        $this->assertCount(1, $histories);
        $this->assertNull($histories[0]->blocked_reason);
    }

    /**
     * From an allowed country: login as usual, recorded as a login. The restriction checks only once (not again on the login event).
     */
    public function testLoginFromAllowedCountry(): void
    {
        $this->enable(['JP', 'US']);
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user, self::IP_JP)->assertRedirect();
        $this->assertLoggedIn();
        $this->assertSame(1, $this->service->lookups);

        $this->postLogin($login_user, self::IP_US)->assertRedirect();
        $this->assertLoggedIn();

        $histories = $this->historiesOf($login_user);
        $this->assertCount(2, $histories);
        $this->assertNull($histories[0]->blocked_reason);
        $this->assertNull($histories[1]->blocked_reason);
        $this->assertFalse($histories[0]->isBlocked());
        $this->assertSame(0, $this->blockedHistories()->count());
    }

    /**
     * From a country not allowed: not logged in, back to the login page with the message and the entered ID (not the password),
     * and the attempt is recorded as blocked. Administrators are not notified.
     */
    public function testLoginFromNotAllowedCountryIsBlocked(): void
    {
        $this->enable(['JP']);
        $login_user = $this->createLoginUser();
        $this->postLogin($login_user, self::IP_JP);

        $response = $this->postLogin($login_user, self::IP_US, self::PASSWORD, 'Mozilla/5.0 LoginCountryTest');

        $response->assertRedirect(admin_url('auth/login'));
        $response->assertSessionHasErrors(['username' => exmtrans('login.message.country_restricted')]);
        $response->assertSessionHasInput('username', $login_user->user_code);
        $response->assertSessionMissing('_old_input.password');
        $this->assertFalse(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check());

        // the login history has only the first login. The blocked attempt is recorded without user (not authenticated).
        $this->assertCount(1, $this->historiesOf($login_user));
        $blocked = $this->blockedHistories()->get();
        $this->assertCount(1, $blocked);
        $history = $blocked[0];
        $this->assertTrue($history->isBlocked());
        $this->assertSame(LoginHistory::BLOCKED_REASON_COUNTRY, $history->blocked_reason);
        $this->assertSame($login_user->user_code, $history->user_code, 'the entered ID');
        $this->assertNull($history->user_name);
        $this->assertNull($history->login_user_id);
        $this->assertNull($history->base_user_id);
        $this->assertSame(LoginType::PURE, $history->login_type);
        $this->assertNull($history->login_provider);
        $this->assertSame(self::IP_US, $history->ip_address);
        $this->assertSame('US', $history->country_code);
        $this->assertSame('Country US', $history->country);
        $this->assertSame('Mozilla/5.0 LoginCountryTest', $history->user_agent);
        $this->assertFalse($history->is_new_ip);
        $this->assertFalse($history->via_remember);
        $this->assertNull($history->auth_2factor_verified);
        $this->assertNotNull($history->created_at);

        $this->assertSame(0, NotifyNavbar::withoutGlobalScopes()->where('parent_type', SystemTableName::LOGIN_HISTORY)->where('parent_id', $history->id)->count(), 'not notified');
        $this->assertNull(session(Define::SYSTEM_KEY_SESSION_LOGIN_HISTORY_ID), 'a blocked attempt is not the login of the session');
    }

    /**
     * Checked before the authentication: a wrong password gets the same message, so the password cannot be guessed from abroad.
     * The entered ID is recorded even if no such user. A long ID is truncated.
     */
    public function testBlockedBeforeAuthentication(): void
    {
        $this->enable(['JP']);
        $login_user = $this->createLoginUser();

        $response = $this->postLogin($login_user, self::IP_US, 'wrong-password');
        $response->assertSessionHasErrors(['username' => exmtrans('login.message.country_restricted')]);
        $this->assertNotSame(exmtrans('error.login_failed'), exmtrans('login.message.country_restricted'));

        $longId = str_repeat('x', 300);
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => self::IP_US])
            ->post(admin_url('auth/login'), ['username' => $longId, 'password' => 'x'])
            ->assertSessionHasErrors(['username' => exmtrans('login.message.country_restricted')]);

        $codes = $this->blockedHistories()->pluck('user_code')->all();
        $this->assertSame([$login_user->user_code, str_repeat('x', 255)], $codes);
    }

    /**
     * The country cannot be judged: by the setting "allow login when the country is unknown".
     */
    public function testUnknownCountry(): void
    {
        $this->enable(['JP'], true);
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user, self::IP_UNKNOWN);
        $this->assertLoggedIn();

        System::login_country_unknown_allowed(false);
        $this->postLogin($login_user, self::IP_UNKNOWN)
            ->assertSessionHasErrors(['username' => exmtrans('login.message.country_restricted')]);
        $this->assertFalse(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check());

        $blocked = $this->blockedHistories()->first();
        $this->assertInstanceOf(LoginHistory::class, $blocked);
        $this->assertSame(self::IP_UNKNOWN, $blocked->ip_address);
        $this->assertNull($blocked->country_code);
        $this->assertNull($blocked->country);
    }

    /**
     * No GeoIP database: every global IP address is "unknown". (The setting screen does not allow turning on without it.)
     */
    public function testWithoutGeoIpDatabase(): void
    {
        config(['exment.geoip_db_path' => storage_path('app/geoip/not_exists_' . short_uuid() . '.mmdb')]);
        $this->app->forgetInstance(LoginCountryRestrictionService::class);
        $this->enable(['US'], false);
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user, self::IP_US)
            ->assertSessionHasErrors(['username' => exmtrans('login.message.country_restricted')]);

        System::login_country_unknown_allowed(true);
        $this->postLogin($login_user, self::IP_US);
        $this->assertLoggedIn();
    }

    /**
     * Private IP address (intranet) is always allowed, even if the unknown country is not allowed.
     */
    public function testPrivateIpIsAlwaysAllowed(): void
    {
        $this->enable(['JP'], false);
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user, self::IP_PRIVATE);

        $this->assertLoggedIn();
        $this->assertSame(0, $this->blockedHistories()->count());
    }

    /**
     * "IP addresses always allowed" can log in from any country.
     */
    public function testAllowedIpsCanLogInFromAnyCountry(): void
    {
        $this->enable(['JP'], false);
        System::login_country_allowed_ips("198.51.100.7\r\n8.8.8.0/24");
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user, self::IP_US);
        $this->assertLoggedIn();

        $this->postLogin($login_user, self::IP_CN)
            ->assertSessionHasErrors(['username' => exmtrans('login.message.country_restricted')]);
    }

    /**
     * LDAP login (the login form with the LDAP provider) is recorded as LDAP. Blocked before connecting to the LDAP server.
     */
    public function testLdapLoginIsBlocked(): void
    {
        $this->enable(['JP']);
        $this->createLoginSetting(LoginType::LDAP, ['ldap_name' => 'lcldap']);
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user, self::IP_US, self::PASSWORD, 'LoginCountryTest', ['login_setting_lcldap' => 1])
            ->assertSessionHasErrors(['username' => exmtrans('login.message.country_restricted')]);

        $blocked = $this->blockedHistories()->first();
        $this->assertInstanceOf(LoginHistory::class, $blocked);
        $this->assertSame(LoginType::LDAP, $blocked->login_type);
        $this->assertSame('lcldap', $blocked->login_provider);
        $this->assertSame($login_user->user_code, $blocked->user_code);
    }

    /**
     * Only the login form and the SSO routes are checked: the login page itself, and the password reset are not.
     */
    public function testOnlyLoginRoutesAreRestricted(): void
    {
        $this->enable(['JP'], false);

        $this->assertSame(['admin.login-country:' . LoginType::PURE], $this->countryMiddlewaresOf('POST', 'auth/login'));
        $this->assertSame([], $this->countryMiddlewaresOf('GET', 'auth/login'));
        $this->assertSame([], $this->countryMiddlewaresOf('POST', 'auth/forget'));
        $this->assertSame([], $this->countryMiddlewaresOf('POST', 'auth/reset/{token}'));

        // the login page is shown from any country
        app('auth')->forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => self::IP_US])->get(admin_url('auth/login'))->assertStatus(200);
        $this->assertSame(0, $this->blockedHistories()->count());
    }

    // ------------------------------------------------------------------ //
    //  SSO                                                                //
    // ------------------------------------------------------------------ //

    /**
     * OAuth / SAML: the redirect to the provider and the callback are checked. The message is shown under the SSO buttons.
     */
    public function testSsoLoginIsBlocked(): void
    {
        $this->enable(['JP']);
        $this->createLoginSetting(LoginType::OAUTH, ['oauth_provider_type' => 'google']);
        $this->createLoginSetting(LoginType::SAML, ['saml_name' => 'lcsaml']);
        $this->remapLoginRoutes();

        $this->assertSame(['admin.login-country:' . LoginType::OAUTH], $this->countryMiddlewaresOf('GET', 'auth/login/{provider}'));
        $this->assertSame(['admin.login-country:' . LoginType::OAUTH], $this->countryMiddlewaresOf('GET', 'auth/login/{provider}/callback'));
        $this->assertSame(['admin.login-country:' . LoginType::SAML], $this->countryMiddlewaresOf('GET', 'saml/login/{provider}'));
        $this->assertSame(['admin.login-country:' . LoginType::SAML], $this->countryMiddlewaresOf('POST', 'saml/login/{provider}/acs'));
        $this->assertSame([], $this->countryMiddlewaresOf('GET', 'saml/login/{provider}/metadata'));
        $this->assertSame([], $this->countryMiddlewaresOf('GET', 'saml/logout'));

        $requests = [
            [LoginType::OAUTH, 'google', 'GET', 'auth/login/google'],
            [LoginType::OAUTH, 'google', 'GET', 'auth/login/google/callback'],
            [LoginType::SAML, 'lcsaml', 'GET', 'saml/login/lcsaml'],
            [LoginType::SAML, 'lcsaml', 'POST', 'saml/login/lcsaml/acs'],
        ];
        foreach ($requests as [$login_type, $provider, $method, $uri]) {
            $this->flushSession();
            $response = $this->withoutMiddleware(VerifyCsrfToken::class)
                ->withServerVariables(['REMOTE_ADDR' => self::IP_US])
                ->call($method, admin_url($uri));

            $response->assertRedirect(admin_url('auth/login'));
            $response->assertSessionHasErrors(['sso_error' => exmtrans('login.message.country_restricted')]);

            $blocked = $this->blockedHistories()->reorder('id', 'desc')->first();
            $this->assertInstanceOf(LoginHistory::class, $blocked);
            $this->assertSame($login_type, $blocked->login_type, $uri);
            $this->assertSame($provider, $blocked->login_provider, $uri);
            $this->assertNull($blocked->user_code, "{$uri}: no ID is entered");
        }
        $this->assertSame(count($requests), $this->blockedHistories()->count());

        // allowed country: goes on to the provider (the result depends on the provider, but it is not blocked)
        $this->flushSession();
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::IP_JP])->get(admin_url('auth/login/google'));
        $this->assertNotSame([exmtrans('login.message.country_restricted')], session('errors') ? session('errors')->get('sso_error') : null);
        $this->assertSame(count($requests), $this->blockedHistories()->count());
    }

    // ------------------------------------------------------------------ //
    //  "remember me" cookie                                               //
    // ------------------------------------------------------------------ //

    /**
     * Login by the "remember me" cookie from a country not allowed: logged out, the cookie is removed,
     * recorded as a blocked attempt of the user (not as a login), and the message is shown on the login page.
     */
    public function testRememberMeLoginFromNotAllowedCountryIsBlocked(): void
    {
        $this->enable(['JP']);
        $login_user = $this->createLoginUser();
        [$recallerName, $cookie] = $this->loginWithRemember($login_user);

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::IP_US])
            ->withCookie($recallerName, $cookie)
            ->get(admin_url('auth/setting'));

        $response->assertRedirect(admin_url('auth/login'));
        $response->assertCookieExpired($recallerName);
        $response->assertSessionHasErrors(['username' => exmtrans('login.message.country_restricted')]);
        $this->assertFalse(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check());

        $histories = $this->historiesOf($login_user);
        $this->assertCount(2, $histories, 'the first login and the blocked attempt');
        $this->assertNull($histories[0]->blocked_reason);
        $blocked = $histories[1];
        $this->assertSame(LoginHistory::BLOCKED_REASON_COUNTRY, $blocked->blocked_reason);
        $this->assertTrue($blocked->via_remember);
        $this->assertEquals($login_user->id, $blocked->login_user_id);
        $this->assertSame($login_user->user_code, $blocked->user_code);
        $this->assertSame($login_user->user_name, $blocked->user_name);
        $this->assertSame(LoginType::PURE, $blocked->login_type);
        $this->assertSame(self::IP_US, $blocked->ip_address);
        $this->assertSame('US', $blocked->country_code);
        $this->assertFalse($blocked->is_new_ip, 'blocked: not warned as a new IP');
        $this->assertSame(0, NotifyNavbar::withoutGlobalScopes()->where('parent_type', SystemTableName::LOGIN_HISTORY)->where('parent_id', $blocked->id)->count());

        // the remember token itself is kept: the user can still use "remember me" on the other devices
        $stored = \DB::table(SystemTableName::LOGIN_USER)->where('id', $login_user->id)->first();
        $this->assertNotNull($stored);
        $this->assertSame(explode('|', $cookie)[1], $stored->remember_token);
    }

    /**
     * Login by the "remember me" cookie from an allowed country: logged in, recorded as an auto login.
     */
    public function testRememberMeLoginFromAllowedCountry(): void
    {
        $this->enable(['JP']);
        $login_user = $this->createLoginUser();
        [$recallerName, $cookie] = $this->loginWithRemember($login_user);

        $this->withServerVariables(['REMOTE_ADDR' => self::IP_JP])
            ->withCookie($recallerName, $cookie)
            ->get(admin_url('auth/setting'))
            ->assertStatus(200);

        $histories = $this->historiesOf($login_user);
        $this->assertCount(2, $histories);
        $this->assertTrue($histories[1]->via_remember);
        $this->assertNull($histories[1]->blocked_reason);
    }

    /**
     * Login of other guards, without session, or not by the "remember me" cookie: the listener does nothing.
     * (The login form and SSO are checked before the authentication.)
     */
    public function testListenerChecksOnlyRememberMeLogin(): void
    {
        $this->enable(['JP']);
        $login_user = $this->createLoginUser();
        request()->server->set('REMOTE_ADDR', self::IP_US);

        $this->assertNull($this->service->handleLogin(new Login(Define::AUTHENTICATE_KEY_API, $login_user, true)), 'api guard');
        $this->assertFalse(request()->hasSession(), 'precondition: no session');
        $this->assertNull($this->service->handleLogin(new Login(Define::AUTHENTICATE_KEY_WEB, $login_user, true)), 'no session');

        request()->setLaravelSession(app('session')->driver());
        $this->assertNull($this->service->handleLogin(new Login(Define::AUTHENTICATE_KEY_WEB, $login_user, true)), 'not via the remember me cookie');
        $this->assertSame(0, $this->service->lookups);
        $this->assertSame(0, $this->blockedHistories()->count());
    }

    /**
     * The restriction listener is registered before the login history listener, so that a blocked login is not recorded as a login.
     */
    public function testListenerOrder(): void
    {
        $listeners = \Event::getRawListeners()[Login::class] ?? [];
        $classes = array_map(function ($listener) {
            return is_array($listener) ? $listener[0] : null;
        }, $listeners);

        $restriction = array_search(LoginCountryRestrictionService::class, $classes, true);
        $history = array_search(LoginHistoryService::class, $classes, true);
        $this->assertIsInt($restriction);
        $this->assertIsInt($history);
        $this->assertLessThan($history, $restriction);
    }

    // ------------------------------------------------------------------ //
    //  Login history                                                      //
    // ------------------------------------------------------------------ //

    /**
     * A blocked attempt is not a "used IP address": the next login from that IP address is still warned.
     */
    public function testBlockedAttemptIsNotUsedForNewIpWarning(): void
    {
        $login_user = $this->createLoginUser();
        $this->createHistory($login_user, ['ip_address' => self::IP_JP]);
        $this->createHistory($login_user, ['ip_address' => self::IP_US, 'blocked_reason' => LoginHistory::BLOCKED_REASON_COUNTRY, 'via_remember' => true]);

        $this->assertTrue(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_US));
        $this->assertFalse(LoginHistoryService::isNewIp($login_user->base_user_id, self::IP_JP));

        // only blocked attempts: nothing to compare with
        $other = $this->createLoginUser();
        $this->createHistory($other, ['ip_address' => self::IP_US, 'blocked_reason' => LoginHistory::BLOCKED_REASON_COUNTRY]);
        $this->assertFalse(LoginHistoryService::isNewIp($other->base_user_id, self::IP_JP));
    }

    /**
     * List: the "blocked" column and filter. Detail: the reason.
     */
    public function testLoginHistoryScreenShowsBlocked(): void
    {
        $login_user = $this->createLoginUser();
        $login = $this->createHistory($login_user, ['ip_address' => '198.51.100.11']);
        $blocked = $this->createHistory($login_user, ['ip_address' => '198.51.100.22', 'blocked_reason' => LoginHistory::BLOCKED_REASON_COUNTRY]);
        $label = exmtrans('login_history.blocked_reason_options.country');

        $this->loginAsAdmin();

        $content = $this->indexContent(['user_code' => $login_user->user_code]);
        $this->assertStringContainsString(exmtrans('login_history.blocked_reason'), $content);
        $this->assertStringContainsString($label, $content);
        $this->assertStringContainsString('198.51.100.11', $content);
        $this->assertStringContainsString('198.51.100.22', $content);

        $content = $this->indexContent(['user_code' => $login_user->user_code, 'blocked' => 1]);
        $this->assertStringNotContainsString('198.51.100.11', $content);
        $this->assertStringContainsString('198.51.100.22', $content);

        // "0" must not be taken as "no filter"
        $content = $this->indexContent(['user_code' => $login_user->user_code, 'blocked' => 0]);
        $this->assertStringContainsString('198.51.100.11', $content);
        $this->assertStringNotContainsString('198.51.100.22', $content);

        $content = (string)$this->get(admin_urls('login_history', $blocked->id))->assertStatus(200)->getContent();
        $this->assertStringContainsString($label, $content);
        $content = (string)$this->get(admin_urls('login_history', $login->id))->assertStatus(200)->getContent();
        $this->assertStringNotContainsString($label, $content);
    }

    /**
     * The login histories in the user detail page show the blocked attempts of the user (by the "remember me" cookie).
     */
    public function testUserDetailShowsBlocked(): void
    {
        $login_user = $this->createLoginUser();
        $this->createHistory($login_user, ['ip_address' => '198.51.100.22', 'blocked_reason' => LoginHistory::BLOCKED_REASON_COUNTRY]);

        $this->loginAsAdmin();
        $content = (string)$this->get(admin_urls('data', SystemTableName::USER, $login_user->base_user_id))->assertStatus(200)->getContent();

        $this->assertStringContainsString('198.51.100.22', $content);
        $this->assertStringContainsString(exmtrans('login_history.blocked_reason_options.country'), $content);
    }

    /**
     * The "warning" column shows a blocked login, or a login from a new IP address. No extra column: the list is already wide.
     */
    public function testWarningLabel(): void
    {
        $login_user = $this->createLoginUser();
        $blocked = $this->createHistory($login_user, ['blocked_reason' => LoginHistory::BLOCKED_REASON_COUNTRY]);
        $newIp = $this->createHistory($login_user, ['is_new_ip' => true]);
        $normal = $this->createHistory($login_user);
        $unknownReason = $this->createHistory($login_user, ['blocked_reason' => 'lc_<b>other</b>']);

        $this->assertSame('<span class="label label-warning">' . esc_html(exmtrans('login_history.blocked_reason_options.country')) . '</span>', UserLoginHistoryGrid::getWarningLabel($blocked));
        $this->assertSame(UserLoginHistoryGrid::getNewIpLabel(true), UserLoginHistoryGrid::getWarningLabel($newIp));
        $this->assertSame('', UserLoginHistoryGrid::getWarningLabel($normal));
        $this->assertSame('<span class="label label-warning">lc_&lt;b&gt;other&lt;/b&gt;</span>', UserLoginHistoryGrid::getWarningLabel($unknownReason), 'a reason without the text is shown as it is, escaped');

        $this->assertSame(exmtrans('login_history.blocked_reason_options.country'), UserLoginHistoryGrid::getBlockedText(LoginHistory::BLOCKED_REASON_COUNTRY));
        $this->assertNull(UserLoginHistoryGrid::getBlockedText(null));
        $this->assertNull(UserLoginHistoryGrid::getBlockedText(''));
    }

    /**
     * Export: the reason is the last column, so the other columns keep their position.
     */
    public function testExportIncludesBlockedReason(): void
    {
        $login_user = $this->createLoginUser();
        $this->createHistory($login_user, ['ip_address' => '198.51.100.22', 'blocked_reason' => LoginHistory::BLOCKED_REASON_COUNTRY]);
        $this->createHistory($login_user, ['ip_address' => '198.51.100.11']);

        $this->loginAsAdmin();
        request()->query->set('user_code', $login_user->user_code);
        $controller = new LoginHistoryController();
        $method = new \ReflectionMethod($controller, 'grid');
        $outputs = (new LoginHistoryProvider(['grid' => $method->invoke($controller)]))->data();

        $this->assertCount(4, $outputs);
        $this->assertSame('blocked_reason', end($outputs[0]));
        $this->assertSame(exmtrans('login_history.blocked_reason'), end($outputs[1]));
        $this->assertSame('user_agent', $outputs[0][count($outputs[0]) - 2]);

        $rows = [];
        foreach ([$outputs[2], $outputs[3]] as $output) {
            $row = array_combine($outputs[0], $output);
            $rows[$row['ip_address']] = $row['blocked_reason'];
        }
        $this->assertSame(['198.51.100.11' => null, '198.51.100.22' => LoginHistory::BLOCKED_REASON_COUNTRY], $rows);
    }

    // ------------------------------------------------------------------ //
    //  Setting screen                                                     //
    // ------------------------------------------------------------------ //

    /**
     * The setting box on the login setting page, with the IP address and the country of the current access.
     */
    public function testSettingFormIsShown(): void
    {
        if (!GeoIpService::isAvailable()) {
            $this->markTestSkipped('GeoIP database is not placed in this environment.');
        }
        System::login_country_allowed(['JP', 'US']);
        System::login_country_allowed_ips('198.51.100.7');

        $this->loginAsAdmin();
        $content = (string)$this->withServerVariables(['REMOTE_ADDR' => self::IP_JP])
            ->get(admin_url('login_setting'))->assertStatus(200)->getContent();

        $this->assertStringContainsString(exmtrans('login.country_restriction'), $content);
        $this->assertStringContainsString(route('exment.post_login_country'), $content);
        foreach (['login_country_restriction', 'login_country_unknown_allowed', 'login_country_allowed_ips'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $content, $name);
        }
        $this->assertStringContainsString('name="login_country_allowed[]"', $content);
        $this->assertStringContainsString(esc_html(CountryList::getOptions()['JP']), $content);
        $this->assertStringContainsString('198.51.100.7', $content);
        $this->assertStringContainsString(esc_html(exmtrans('login.help.country_current_ip', self::IP_JP, CountryList::getName('JP') . ' (JP)')), $content);
        // the fields are shown only when the switch is on
        $this->assertStringContainsString('login_country_restriction&quot;,&quot;value&quot;:&quot;1&quot;', $content);
    }

    /**
     * No GeoIP database: the setting box tells it.
     */
    public function testSettingFormWithoutGeoIpDatabase(): void
    {
        config(['exment.geoip_db_path' => storage_path('app/geoip/not_exists_' . short_uuid() . '.mmdb')]);

        $this->loginAsAdmin();
        $content = (string)$this->get(admin_url('login_setting'))->assertStatus(200)->getContent();

        $this->assertStringContainsString(esc_html(exmtrans('login.help.country_geoip_not_available')), $content);
    }

    /**
     * Save: turned on with the countries and the IP addresses.
     */
    public function testPostSettingSaves(): void
    {
        $this->requireGeoIp();
        $this->loginAsAdmin();

        $this->postCountrySetting([
            'login_country_restriction' => '1',
            'login_country_allowed' => ['', 'JP', 'us'],
            'login_country_unknown_allowed' => '0',
            'login_country_allowed_ips' => "198.51.100.7\n\n203.0.113.0/24\n",
        ])->assertRedirect(admin_url('login_setting'));

        System::clearCache();
        $this->assertTrue(System::login_country_restriction());
        $this->assertSame(['JP', 'US'], System::login_country_allowed());
        $this->assertFalse(System::login_country_unknown_allowed());
        $this->assertSame("198.51.100.7\r\n203.0.113.0/24", System::login_country_allowed_ips());

        // turned off: saved even without countries
        $this->postCountrySetting([
            'login_country_restriction' => '0',
            'login_country_unknown_allowed' => '1',
            'login_country_allowed_ips' => '',
        ])->assertRedirect(admin_url('login_setting'));

        System::clearCache();
        $this->assertFalse(System::login_country_restriction());
        $this->assertSame([], System::login_country_allowed());
        $this->assertTrue(System::login_country_unknown_allowed());
        $this->assertSame('', System::login_country_allowed_ips());
    }

    /**
     * Not saved: no country while on, an unknown country code, a wrong IP address.
     */
    public function testPostSettingValidation(): void
    {
        $this->requireGeoIp();
        $this->loginAsAdmin();

        $cases = [
            'no country' => [
                ['login_country_restriction' => '1', 'login_country_allowed' => ['']],
                ['login_country_allowed' => exmtrans('login.message.country_allowed_required')],
            ],
            'unknown country code' => [
                ['login_country_restriction' => '1', 'login_country_allowed' => ['JP', 'ZZ']],
                ['login_country_allowed' => exmtrans('login.message.country_invalid')],
            ],
            'wrong IP address (also when off)' => [
                ['login_country_restriction' => '0', 'login_country_allowed_ips' => "198.51.100.7\nabc\n10.0.0.0/40"],
                ['login_country_allowed_ips' => exmtrans('login.message.country_ips_invalid', 'abc, 10.0.0.0/40')],
            ],
        ];

        foreach ($cases as $name => [$data, $errors]) {
            $this->flushSession();
            $response = $this->postCountrySetting(array_merge(['login_country_unknown_allowed' => '1'], $data));
            $response->assertRedirect(admin_url('login_setting'));
            $response->assertSessionHasErrors($errors);

            System::clearCache();
            $this->assertFalse(System::login_country_restriction(), $name);
            $this->assertSame([], System::login_country_allowed(), $name);
            $this->assertSame('', System::login_country_allowed_ips(), $name);
        }

        // the entered values are kept on the form
        $content = (string)$this->get(admin_url('login_setting'))->assertStatus(200)->getContent();
        $this->assertStringContainsString('10.0.0.0/40', $content);
    }

    /**
     * Not saved if the administrator could not log in from the current IP address any more. Saved when it is allowed by the IP address.
     */
    public function testPostSettingDoesNotLockOutYourself(): void
    {
        $this->requireGeoIp();
        $this->loginAsAdmin();

        $data = [
            'login_country_restriction' => '1',
            'login_country_allowed' => ['JP'],
            'login_country_unknown_allowed' => '1',
        ];
        $this->postCountrySetting($data, self::IP_US)
            ->assertSessionHasErrors(['login_country_restriction' => exmtrans('login.message.country_self_blocked', self::IP_US, 'US')]);
        System::clearCache();
        $this->assertFalse(System::login_country_restriction());

        // unknown country, not allowed
        $this->postCountrySetting(array_merge($data, ['login_country_unknown_allowed' => '0']), self::IP_UNKNOWN)
            ->assertSessionHasErrors(['login_country_restriction' => exmtrans('login.message.country_self_blocked', self::IP_UNKNOWN, exmtrans('login.country_unknown'))]);
        System::clearCache();
        $this->assertFalse(System::login_country_restriction());

        // allowed by the IP address
        $this->postCountrySetting(array_merge($data, ['login_country_allowed_ips' => self::IP_US]), self::IP_US)
            ->assertSessionHasNoErrors();
        System::clearCache();
        $this->assertTrue(System::login_country_restriction());

        // from the allowed country
        $this->postCountrySetting($data, self::IP_JP)->assertSessionHasNoErrors();
    }

    /**
     * Cannot turn on without the GeoIP database (every address would be "unknown"). Turning off is always possible.
     */
    public function testPostSettingNeedsGeoIpDatabase(): void
    {
        config(['exment.geoip_db_path' => storage_path('app/geoip/not_exists_' . short_uuid() . '.mmdb')]);
        $this->loginAsAdmin();

        $this->postCountrySetting([
            'login_country_restriction' => '1',
            'login_country_allowed' => ['JP'],
            'login_country_unknown_allowed' => '1',
        ])->assertSessionHasErrors(['login_country_restriction' => exmtrans('login.help.country_geoip_not_available')]);
        System::clearCache();
        $this->assertFalse(System::login_country_restriction());

        System::login_country_restriction(true);
        $this->postCountrySetting(['login_country_restriction' => '0'])->assertSessionHasNoErrors();
        System::clearCache();
        $this->assertFalse(System::login_country_restriction());
    }

    /**
     * Only the users with the system permission can save the setting, same as the other login settings.
     * (Checked by visible(): the "no permission" page of Exment exits, which stops phpunit.)
     */
    public function testPostSettingNeedsSystemPermission(): void
    {
        $login_user = $this->createLoginUser();

        $this->loginAs($login_user);
        $this->assertFalse(\Exment::user()->visible('login_setting/country'), 'no permission');

        $this->grantSystemPermission($login_user, ['login_user']);
        $this->loginAs($login_user);
        $this->assertFalse(\Exment::user()->visible('login_setting/country'), 'login user permission is not enough');

        $this->grantSystemPermission($login_user, ['system']);
        $this->loginAs($login_user);
        $this->assertTrue(\Exment::user()->visible('login_setting/country'), 'system permission');
    }

    // ------------------------------------------------------------------ //
    //  Country list                                                       //
    // ------------------------------------------------------------------ //

    /**
     * Countries of ISO 3166-1 alpha-2 (+ Kosovo), named in Japanese and English.
     */
    public function testCountryList(): void
    {
        $this->assertCount(250, CountryList::COUNTRIES);
        foreach (CountryList::COUNTRIES as $code => $names) {
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $code);
            $this->assertNotEmpty($names['en'], $code);
            $this->assertNotEmpty($names['ja'], $code);
        }

        $this->assertTrue(CountryList::exists('JP'));
        $this->assertFalse(CountryList::exists('jp'));
        $this->assertFalse(CountryList::exists('ZZ'));
        $this->assertFalse(CountryList::exists(null));

        $this->assertSame('日本', CountryList::getName('JP', 'ja'));
        $this->assertSame('Japan', CountryList::getName('JP', 'en'));
        $this->assertSame('Japan', CountryList::getName('JP', 'fr'), 'other locales: English');
        $this->assertNull(CountryList::getName('ZZ'));

        $options = CountryList::getOptions('en');
        $this->assertCount(250, $options);
        $this->assertSame('Japan (JP)', $options['JP']);
        $sorted = array_values($options);
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, array_values($options), 'ordered by the name');
        $this->assertSame('日本 (JP)', CountryList::getOptions('ja')['JP']);
    }

    // ------------------------------------------------------------------ //
    //  helpers                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Replace the GeoIP lookup of the restriction with the given countries. Other IP addresses: unknown country.
     *
     * @param array<string, string> $countries key: IP address, value: country code
     */
    protected function fakeCountries(array $countries): void
    {
        $this->service = new class ($countries) extends LoginCountryRestrictionService {
            /** @var int */
            public $lookups = 0;

            /** @var array<string, string> */
            protected $countries;

            /**
             * @param array<string, string> $countries
             */
            public function __construct(array $countries)
            {
                $this->countries = $countries;
            }

            public function lookup(?string $ip): array
            {
                $this->lookups++;
                $code = is_null($ip) ? null : ($this->countries[$ip] ?? null);
                return [
                    'country_code' => $code,
                    'country' => is_null($code) ? null : "Country {$code}",
                    'region' => null,
                    'city' => null,
                ];
            }
        };
        $this->app->instance(LoginCountryRestrictionService::class, $this->service);
    }

    /**
     * @param array<string> $countries
     */
    protected function enable(array $countries, bool $unknown_allowed = true): void
    {
        System::login_country_restriction(true);
        System::login_country_allowed($countries);
        System::login_country_unknown_allowed($unknown_allowed);
    }

    protected function requireGeoIp(): void
    {
        if (!GeoIpService::isAvailable()) {
            $this->markTestSkipped('GeoIP database is not placed in this environment.');
        }
    }

    /**
     * Post the login form as a new browser.
     *
     * @param array<string, mixed> $data additional post data
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postLogin(LoginUser $login_user, string $ip, string $password = self::PASSWORD, string $userAgent = 'LoginCountryTest', array $data = []): TestResponse
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
     * Log in with "remember me" from the allowed country, then forget the session: only the cookie remains.
     *
     * @return array{0: string, 1: string} cookie name and value
     */
    protected function loginWithRemember(LoginUser $login_user): array
    {
        $this->postLogin($login_user, self::IP_JP, self::PASSWORD, 'LoginCountryTest', ['remember' => 1]);
        $this->assertLoggedIn();

        $stored = \DB::table(SystemTableName::LOGIN_USER)->where('id', $login_user->id)->first();
        $this->assertNotNull($stored);
        $this->assertNotEmpty($stored->remember_token, 'precondition: remember token must be issued');
        $recallerName = \Auth::guard(Define::AUTHENTICATE_KEY_WEB)->getRecallerName();

        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->flushSession();

        return [$recallerName, $login_user->id . '|' . $stored->remember_token . '|' . $stored->password];
    }

    /**
     * @param array<string, mixed> $data
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postCountrySetting(array $data, string $ip = '127.0.0.1'): TestResponse
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from(admin_url('login_setting'))
            ->post(route('exment.post_login_country'), $data);
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function indexContent(array $query = []): string
    {
        $response = $this->get(admin_url('login_history') . (count($query) > 0 ? '?' . http_build_query($query) : ''));
        $response->assertStatus(200);
        return (string)$response->getContent();
    }

    protected function assertLoggedIn(): void
    {
        $this->assertTrue(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check(), 'login must succeed');
    }

    /**
     * The middlewares of the login restriction by country on the route.
     *
     * @return array<string>
     */
    protected function countryMiddlewaresOf(string $method, string $uri): array
    {
        $routes = \Route::getRoutes();
        $routes->refreshNameLookups();
        foreach ($routes->getRoutes() as $route) {
            if ($route->uri() === url_join(config('admin.route.prefix'), $uri) && in_array($method, $route->methods(), true)) {
                return array_values(array_filter($route->middleware(), function ($middleware) {
                    return strpos($middleware, 'admin.login-country') === 0;
                }));
            }
        }
        $this->fail("route not found: {$method} {$uri}");
    }

    /**
     * The SSO routes are registered only when an SSO login setting exists. Register them again after creating the settings.
     */
    protected function remapLoginRoutes(): void
    {
        $provider = new RouteServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'mapExmentAnonymousWebRotes');
        $method->invoke($provider);
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function createLoginSetting(string $login_type, array $options): LoginSetting
    {
        $login_setting = new LoginSetting();
        $login_setting->login_type = $login_type;
        $login_setting->login_view_name = 'lctest_' . $login_type;
        $login_setting->active_flg = true;
        $login_setting->options = $options;
        $login_setting->save();
        System::clearCache();

        return $login_setting;
    }

    protected function loginAs(LoginUser $login_user): void
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->be($login_user, Define::AUTHENTICATE_KEY_WEB);
    }

    /**
     * Put the user into a new role group that has the system permissions.
     *
     * @param array<string> $permissions
     */
    protected function grantSystemPermission(LoginUser $login_user, array $permissions): void
    {
        $name = 'lctest_role_' . short_uuid();
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

    protected function loginAsAdmin(): void
    {
        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertInstanceOf(LoginUser::class, $admin);
        $this->loginAs($admin);
    }

    /**
     * Create a user (custom value) and its login user. No role group is assigned.
     */
    protected function createLoginUser(): LoginUser
    {
        $code = 'lctest_' . short_uuid();

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

    /**
     * @param array<string, mixed> $attributes
     */
    protected function createHistory(LoginUser $login_user, array $attributes = []): LoginHistory
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

        $history = LoginHistory::find($history->id);
        $this->assertInstanceOf(LoginHistory::class, $history);
        return $history;
    }

    /**
     * Histories of the user, in created order.
     *
     * @return \Illuminate\Support\Collection<int, LoginHistory>
     */
    protected function historiesOf(LoginUser $login_user): \Illuminate\Support\Collection
    {
        return LoginHistory::where('base_user_id', $login_user->base_user_id)->orderBy('id')->get()->values()->toBase();
    }

    /**
     * Blocked attempts recorded in this test.
     *
     * @return \Illuminate\Database\Eloquent\Builder<LoginHistory>
     */
    protected function blockedHistories(): \Illuminate\Database\Eloquent\Builder
    {
        return LoginHistory::query()->whereNotNull('blocked_reason')->where('id', '>', $this->maxHistoryId)->orderBy('id');
    }
}
