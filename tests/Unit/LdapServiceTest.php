<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\LoginSettingController;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SsoLoginErrorType;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Services\Login\Ldap\LdapEntry;
use Exceedone\Exment\Services\Login\Ldap\LdapService;
use Illuminate\Http\Request;
use LdapRecord\Testing\LdapFake;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * LDAP login through LdapRecord (UP-03).
 *
 * The LDAP server is faked (LdapRecord\Testing\LdapFake): the tests check the requests sent to
 * it — the search filters are the ones adldap2 sent up to v6 — and how the answers are handled.
 * Skipped when directorytree/ldaprecord is not installed (it is optional, like adldap2 was).
 */
class LdapServiceTest extends UnitTestBase
{
    protected const BASE_DN = 'dc=example,dc=com';
    protected const USER_DN = 'uid=einstein,dc=example,dc=com';

    protected function setUp(): void
    {
        parent::setUp();

        if (!LdapService::isLibraryInstalled()) {
            $this->markTestSkipped('directorytree/ldaprecord is not installed');
        }
        FakeConnectionLdapService::$fake = null;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function setting(array $options = []): LoginSetting
    {
        $setting = new LoginSetting();
        $options += [
            'ldap_name' => 'testldap',
            'ldap_schema' => 'OpenLDAP',
            'ldap_hosts' => 'ldap.example.com',
            'ldap_port' => 389,
            'ldap_base_dn' => static::BASE_DN,
            'ldap_search_key' => 'uid',
            'ldap_filter' => '(objectClass=inetOrgPerson)',
            'mapping_user_column' => 'user_code',
            'mapping_column_user_code' => '${uid}',
            'mapping_column_user_name' => '${cn}',
            'mapping_column_email' => '${mail}',
        ];
        foreach ($options as $key => $value) {
            $setting->setOption($key, $value);
        }
        $setting->login_type = LoginType::LDAP;

        return $setting;
    }

    /**
     * A search answer in the raw format of ldap_get_entries().
     *
     * @return array<mixed>
     */
    protected function searchResult(string $uid = 'ldaptest_einstein'): array
    {
        return [
            'count' => 1,
            0 => [
                'objectclass' => ['count' => 2, 0 => 'inetOrgPerson', 1 => 'person'],
                'uid' => ['count' => 1, 0 => $uid],
                'cn' => ['count' => 1, 0 => 'Albert Einstein'],
                'mail' => ['count' => 1, 0 => $uid . '@ldap.example.com'],
                'count' => 4,
                'dn' => static::USER_DN,
            ],
        ];
    }

    /**
     * A connection to the fake server.
     *
     * @return \LdapRecord\Connection
     */
    protected function fakeConnection(LoginSetting $setting, LdapFake $fake)
    {
        // the query connects (anonymous bind) on first use
        $fake->shouldAllowAnyBind();
        $connection = LdapService::newConnection($setting);
        $connection->setLdapConnection($fake);

        return $connection;
    }

    /**
     * "use SSL" / "use TLS" of the screen keep their adldap2 meaning: ldaps:// and STARTTLS.
     */
    public function testConfig(): void
    {
        $config = LdapService::getLdapConfig($this->setting(['ldap_hosts' => 'ldap1.example.com,ldap2.example.com', 'ldap_use_ssl' => '1']));
        $this->assertSame(['ldap1.example.com', 'ldap2.example.com'], $config['hosts']);
        $this->assertSame(389, $config['port']);
        $this->assertSame(static::BASE_DN, $config['base_dn']);
        $this->assertTrue($config['use_tls'], 'use SSL = ldaps://');
        $this->assertFalse($config['use_starttls']);
        $this->assertNull($config['username'], 'anonymous bind until the user binds');

        $config = LdapService::getLdapConfig($this->setting(['ldap_use_tls' => '1', 'ldap_port' => null]));
        $this->assertFalse($config['use_tls']);
        $this->assertTrue($config['use_starttls'], 'use TLS = STARTTLS');
        $this->assertSame(389, $config['port'], 'default port');

        $this->assertSame(636, LdapService::getLdapConfig($this->setting(['ldap_port' => '636']))['port']);
    }

    public function testSchema(): void
    {
        $this->assertSame(LdapService::SCHEMA_ACTIVE_DIRECTORY, LdapService::getSchema($this->setting(['ldap_schema' => null])));
        $this->assertSame(LdapService::SCHEMA_ACTIVE_DIRECTORY, LdapService::getSchema($this->setting(['ldap_schema' => 'ActiveDirectory'])));
        $this->assertSame(LdapService::SCHEMA_OPEN_LDAP, LdapService::getSchema($this->setting(['ldap_schema' => 'OpenLDAP'])));
    }

    /**
     * The DN lookup sends the filter of the setting and the search key, escaped (same string as adldap2).
     */
    public function testDnLookupFilter(): void
    {
        $setting = $this->setting();
        $fake = (new LdapFake())->expect([
            LdapFake::operation('search')->once()
                ->with(static::BASE_DN, '(&(objectClass=inetOrgPerson)(uid=\65\69\6e\2a\73\74\65\69\6e))', PHPUnit::anything())
                ->andReturn($this->searchResult()),
            LdapFake::operation('search')->once()->andReturn(['count' => 0]),
        ]);
        $connection = $this->fakeConnection($setting, $fake);

        $this->assertSame(static::USER_DN, LdapService::getLdapUserDN($connection, 'ein*stein', $setting));
        $this->assertNull(LdapService::getLdapUserDN($connection, 'nobody', $setting), 'not found');
    }

    /**
     * The user search filters per schema (same strings as adldap2 search()->users()).
     */
    public function testSyncSearchFilters(): void
    {
        $cases = [
            'OpenLDAP with filter' => [['ldap_schema' => 'OpenLDAP'],
                '(&(objectClass=inetOrgPerson)(uid=\74\65\73\6c\61))'],
            'OpenLDAP without filter' => [['ldap_schema' => 'OpenLDAP', 'ldap_filter' => null],
                '(&(objectclass=\69\6e\65\74\6f\72\67\70\65\72\73\6f\6e)(objectclass=\70\65\72\73\6f\6e)(uid=\74\65\73\6c\61))'],
            'ActiveDirectory (filter not used)' => [['ldap_schema' => 'ActiveDirectory', 'ldap_search_key' => 'samaccountname'],
                '(&(objectclass=\75\73\65\72)(objectcategory=\70\65\72\73\6f\6e)(!(objectclass=\63\6f\6e\74\61\63\74))(samaccountname=\74\65\73\6c\61))'],
        ];
        foreach ($cases as $name => [$options, $filter]) {
            $setting = $this->setting($options);
            $fake = (new LdapFake())->expect([
                LdapFake::operation('search')->once()->with(static::BASE_DN, $filter, PHPUnit::anything())->andReturn($this->searchResult()),
            ]);

            $entry = LdapService::syncLdapUser($this->fakeConnection($setting, $fake), $setting, 'tesla');

            $this->assertInstanceOf(LdapEntry::class, $entry, $name);
            $this->assertSame(static::USER_DN, $entry->getDn(), $name);
        }
    }

    /**
     * OpenLDAP login: anonymous bind, DN lookup, bind as the user, user search, mapping.
     */
    public function testOpenLdapLoginSucceeds(): void
    {
        FakeConnectionLdapService::$fake = (new LdapFake())->expect([
            LdapFake::operation('bind')->once()->andReturnResponse(),
            LdapFake::operation('search')->once()->andReturn($this->searchResult()),
            LdapFake::operation('bind')->once()->with(static::USER_DN, 'secret')->andReturnResponse(),
            LdapFake::operation('search')->once()->andReturn($this->searchResult()),
        ]);

        [$result, , $adminMessage, $user] = $this->login('ldaptest_einstein', 'secret');

        $this->assertTrue($result, json_encode($adminMessage) ?: '');
        $this->assertSame('ldaptest_einstein', $user->login_id);
        $this->assertSame([
            'user_code' => 'ldaptest_einstein',
            'user_name' => 'Albert Einstein',
            'email' => 'ldaptest_einstein@ldap.example.com',
        ], array_intersect_key($user->mapping_values, array_flip(['user_code', 'user_name', 'email'])));
        $this->assertSame('testldap', $user->provider_name);
    }

    /**
     * Active Directory login binds with the user name itself (prefix / suffix added), no DN lookup.
     */
    public function testActiveDirectoryLoginBindsWithAccountName(): void
    {
        FakeConnectionLdapService::$fake = (new LdapFake())->expect([
            LdapFake::operation('bind')->once()->andReturnResponse(),
            LdapFake::operation('bind')->once()->with('ldaptest_einstein@corp.example.com', 'secret')->andReturnResponse(),
            LdapFake::operation('search')->once()->andReturn($this->searchResult()),
        ]);

        [$result, , $adminMessage] = $this->login('ldaptest_einstein', 'secret', [
            'ldap_schema' => 'ActiveDirectory', 'ldap_account_suffix' => '@corp.example.com', 'ldap_search_key' => 'userprincipalname',
        ]);

        $this->assertTrue($result, json_encode($adminMessage) ?: '');
    }

    /**
     * Wrong password: "user not found" (the login screen shows the usual error).
     */
    public function testWrongPassword(): void
    {
        FakeConnectionLdapService::$fake = (new LdapFake())->expect([
            LdapFake::operation('bind')->once()->andReturnResponse(),
            LdapFake::operation('search')->once()->andReturn($this->searchResult()),
            LdapFake::operation('bind')->once()->with(static::USER_DN, 'wrong')->andReturnResponse(49, null, 'Invalid credentials'),
        ]);

        [$result] = $this->login('ldaptest_einstein', 'wrong');

        $this->assertSame(SsoLoginErrorType::NOT_EXISTS_PROVIDER_USER, $result);
    }

    /**
     * Unknown user (OpenLDAP): "user not found", no exception.
     */
    public function testUnknownUser(): void
    {
        FakeConnectionLdapService::$fake = (new LdapFake())->expect([
            LdapFake::operation('bind')->once()->andReturnResponse(),
            LdapFake::operation('search')->once()->andReturn(['count' => 0]),
        ]);

        [$result] = $this->login('nobody', 'secret');

        $this->assertSame(SsoLoginErrorType::NOT_EXISTS_PROVIDER_USER, $result);
    }

    /**
     * Server not reachable: provider error (as adldap2's BindException was).
     */
    public function testServerNotReachable(): void
    {
        FakeConnectionLdapService::$fake = (new LdapFake())->expect([
            LdapFake::operation('bind')->andReturnResponse(-1, null, "Can't contact LDAP server"),
        ]);

        [$result] = $this->login('ldaptest_einstein', 'secret');

        $this->assertSame(SsoLoginErrorType::PROVIDER_ERROR, $result);
    }

    /**
     * The login setting screen reports the LDAP library as installed.
     */
    public function testLoginSettingScreenSeesTheLibrary(): void
    {
        /** @var \Illuminate\Support\Collection<string, string> $errors */
        $errors = (new \ReflectionMethod(LoginSettingController::class, 'checkLibraries'))->invoke(new LoginSettingController());

        $this->assertArrayNotHasKey(LoginType::LDAP, $errors->toArray());
    }

    /**
     * Run the login of LdapService with the fake server.
     *
     * @param array<string, mixed> $options
     * @return array<mixed> [$result, $message, $adminMessage, $custom_login_user]
     */
    protected function login(string $username, string $password, array $options = []): array
    {
        $request = Request::create('/admin/auth/login', 'POST', ['username' => $username, 'password' => $password]);

        return (new \ReflectionMethod(FakeConnectionLdapService::class, 'loginCallback'))->invoke(null, $request, $this->setting($options));
    }
}

/**
 * LdapService whose connections talk to the fake server.
 */
class FakeConnectionLdapService extends LdapService
{
    /** @var LdapFake|null */
    public static $fake;

    public static function newConnection(LoginSetting $login_setting)
    {
        $connection = parent::newConnection($login_setting);
        if (static::$fake) {
            $connection->setLdapConnection(static::$fake);
        }

        return $connection;
    }
}
