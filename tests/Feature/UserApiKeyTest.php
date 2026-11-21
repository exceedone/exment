<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\UserApiKey;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\UserApiKeys;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The API keys of the users (ISS00437): a key has the permissions of its user and no more,
 * and it is refused when it is off, stopped, past its date, from another IP, without its
 * client certificate, for another table, or when its user may not use keys any more.
 */
class UserApiKeyTest extends ApiTestBase
{
    use DatabaseTransactions;

    protected const CERT_HEADER = 'X-Test-Client-Cert';

    protected function setUp(): void
    {
        parent::setUp();
        Features::reset();
        config([
            'exment.features_locked' => '',
            'exment.api_client_cert_header' => null,
            'exment.api_key_require_client_cert' => false,
            'exment.trust_proxy_ips' => null,
        ]);
        // on, for the role groups user1 is in and user2 is not
        $this->setFeatures([Features::API_KEYS => ['enabled' => true, 'role_groups' => [$this->groupOfUser1Only()]]]);
    }

    protected function tearDown(): void
    {
        Features::reset();
        // the trusted proxies of a request stay for the next ones (Symfony keeps them)
        \Illuminate\Http\Request::setTrustedProxies([], \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function setFeatures(array $settings): void
    {
        System::features($settings);
        System::clearRequestSession();
        Features::reset();
    }

    protected function groupOfUser1Only(): int
    {
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $only1 = array_values(array_diff(Features::roleGroupIds($user1), Features::roleGroupIds($user2)));
        $this->assertNotEmpty($only1, 'user1 has a role group user2 does not have');
        return $only1[0];
    }

    /**
     * A key of a login user.
     *
     * @param array<string, mixed> $options
     * @return array{0: UserApiKey, 1: string}
     */
    protected function makeKey(string $loginId = TestDefine::TESTDATA_USER_LOGINID_USER1, array $options = []): array
    {
        $login_user = LoginUser::findOrFail($loginId);
        return UserApiKeys::create(
            (int)$login_user->base_user_id,
            $options['name'] ?? 'test key',
            $options['scopes'] ?? UserApiKeys::readOnlyScopes(),
            $options['tables'] ?? null,
            $options['allowed_ips'] ?? null,
            $options['expires_at'] ?? null,
            $options['client_cert_fingerprint'] ?? null,
            null,
            $options['login_user_id'] ?? null
        );
    }

    /**
     * @param array<int, string> $path
     * @param array<string, string> $headers
     * @param array<string, string> $server
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function getWithKey(string $plain, array $path, array $headers = [], array $server = []): \Illuminate\Testing\TestResponse
    {
        // each request signs in again, as on a real server (the test app keeps the guards)
        \Auth::forgetGuards();
        return $this->withServerVariables($server)
            ->withHeaders(['Authorization' => "Bearer $plain"] + $headers)
            ->get(admin_urls('api', ...$path));
    }

    /**
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function assertRefused(\Illuminate\Testing\TestResponse $response, string $why): void
    {
        // a good key where it is not for: 403; else 401
        $response->assertStatus(UserApiKeys::isForbiddenReason($why) ? 403 : 401);
        $this->assertSame($why, UserApiKeys::$lastRefusal);
        // the client is not told why
        $this->assertStringNotContainsString($why, (string)$response->getContent());
    }

    public function testKeyReadsWhatTheUserMay(): void
    {
        [$key, $plain] = $this->makeKey();

        $this->getWithKey($plain, ['data', 'custom_value_edit'])
            ->assertStatus(200)
            ->assertJsonCount(20, 'data');
        $this->getWithKey($plain, ['me'])->assertStatus(200)->assertJsonFragment(['id' => 2]);

        $key->refresh();
        $this->assertSame(1 + 1, $key->use_count);
        $this->assertNotNull($key->last_used_at);
        $this->assertSame('127.0.0.1', $key->last_used_ip);
    }

    public function testTheKeyIsKeptOnlyAsAHash(): void
    {
        [$key, $plain] = $this->makeKey();

        $this->assertStringStartsWith(UserApiKeys::PREFIX, $plain);
        $this->assertGreaterThanOrEqual(52, strlen($plain));
        $row = (array)\DB::table('user_api_keys')->where('id', $key->id)->first();
        $this->assertSame(hash('sha256', $plain), $row['key_hash']);
        foreach ($row as $column => $value) {
            $this->assertNotSame($plain, $value, $column);
        }
        $this->assertArrayNotHasKey('key_hash', $key->toArray());
        $this->assertSame(substr($plain, 0, 12), $key->key_prefix);
        // 90 days by default
        $this->assertEqualsWithDelta(Carbon::now()->addDays(90)->getTimestamp(), $key->expires_at->getTimestamp(), 60);
    }

    public function testScopesAreEnforced(): void
    {
        [, $plain] = $this->makeKey();

        $this->withHeaders(['Authorization' => "Bearer $plain"])
            ->post(admin_urls('api', 'data', 'custom_value_edit'), ['value' => ['text' => 'x']])
            ->assertStatus(403);

        [, $plain] = $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, ['scopes' => [ApiScope::ME]]);
        $this->getWithKey($plain, ['me'])->assertStatus(200);
        $this->getWithKey($plain, ['data', 'custom_value_edit'])->assertStatus(403);
    }

    public function testRefusedWhenTheFeatureIsOff(): void
    {
        [, $plain] = $this->makeKey();
        $this->setFeatures([Features::API_KEYS => ['enabled' => false]]);
        $this->assertRefused($this->getWithKey($plain, ['me']), 'feature');

        // the server keeps it off
        $this->setFeatures([Features::API_KEYS => ['enabled' => true]]);
        config(['exment.features_locked' => Features::API_KEYS]);
        Features::reset();
        $this->assertRefused($this->getWithKey($plain, ['me']), 'feature');
    }

    public function testRefusedForAUserNotInTheRoleGroups(): void
    {
        [, $plain] = $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertRefused($this->getWithKey($plain, ['me']), 'not_allowed');
    }

    public function testRefusedUnknownStoppedOrPastItsDate(): void
    {
        $this->assertRefused($this->getWithKey(UserApiKeys::PREFIX . str_repeat('0', 48), ['me']), 'unknown');

        [$key, $plain] = $this->makeKey();
        UserApiKeys::revoke($key, 1);
        $this->assertRefused($this->getWithKey($plain, ['me']), 'revoked');

        [$key, $plain] = $this->makeKey();
        \DB::table('user_api_keys')->where('id', $key->id)->update(['expires_at' => Carbon::now()->subMinute()]);
        $this->assertRefused($this->getWithKey($plain, ['me']), 'expired');

        // all the keys of a user at once
        [, $plain1] = $this->makeKey();
        [, $plain2] = $this->makeKey();
        // the two, and the one past its date
        $this->assertSame(3, UserApiKeys::revokeAllOfUser((int)LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1)->base_user_id));
        $this->assertRefused($this->getWithKey($plain1, ['me']), 'revoked');
        $this->assertRefused($this->getWithKey($plain2, ['me']), 'revoked');
    }

    public function testRefusedWhenTheLoginOfTheUserIsRemoved(): void
    {
        [, $plain] = $this->makeKey();
        $this->getWithKey($plain, ['me'])->assertStatus(200);

        LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1)->delete();
        $this->assertRefused($this->getWithKey($plain, ['me']), 'user');
    }

    public function testIpAllowList(): void
    {
        [, $plain] = $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, ['allowed_ips' => "10.0.0.0/8\n192.168.1.5"]);
        $this->assertRefused($this->getWithKey($plain, ['me']), 'ip');

        [, $plain] = $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, ['allowed_ips' => '10.0.0.0/8, 127.0.0.1']);
        $this->getWithKey($plain, ['me'])->assertStatus(200);

        // X-Forwarded-For is not taken from a client that is not a trusted proxy
        [, $plain] = $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, ['allowed_ips' => '10.1.2.3']);
        $this->assertRefused($this->getWithKey($plain, ['me'], ['X-Forwarded-For' => '10.1.2.3']), 'ip');
    }

    public function testTablesOfTheKey(): void
    {
        [, $plain] = $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, ['tables' => ['custom_value_edit']]);

        $this->getWithKey($plain, ['data', 'custom_value_edit'])->assertStatus(200);
        $this->getWithKey($plain, ['me'])->assertStatus(200);
        $this->assertRefused($this->getWithKey($plain, ['data', 'custom_value_edit_all']), 'table');
        // the list of all the tables, and the files without a table, are not for it
        $this->assertRefused($this->getWithKey($plain, ['table']), 'table');
    }

    /**
     * What reads other tables than the one of the URL is not for a key limited to some tables.
     */
    public function testALimitedKeyDoesNotReachOtherTables(): void
    {
        [, $plain] = $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, ['tables' => ['custom_value_edit']]);
        $id = \DB::table(getDBTableName('custom_value_edit'))->whereNull('deleted_at')->value('id');

        $this->assertRefused($this->getWithKey($plain, ['data', 'custom_value_edit', $id . '?children=1']), 'table');
        $this->assertRefused($this->getWithKey($plain, ['data', 'custom_value_edit?parents=1']), 'table');
        $this->assertRefused($this->getWithKey($plain, ['data', 'custom_value_edit', 'relatedLinkage']), 'table');
        $this->assertRefused($this->getWithKey($plain, ['data', 'custom_value_edit', 'select']), 'table');
        $this->assertRefused($this->getWithKey($plain, ['data', 'custom_value_edit', 'column', 'text']), 'table');
        $this->assertRefused($this->getWithKey($plain, ['viewdata', 'custom_value_edit', '1']), 'table');
        // without the limit, the same requests are not refused by the key
        [, $all] = $this->makeKey();
        $this->assertNotSame(403, $this->getWithKey($all, ['data', 'custom_value_edit', $id . '?children=1'])->getStatusCode());
    }

    /**
     * A file is checked by the table it belongs to, not by the table in the URL.
     */
    public function testFilesOfAnotherTable(): void
    {
        $file = function (string $table): string {
            $uuid = make_uuid();
            \DB::table('files')->insert(['uuid' => $uuid, 'local_dirname' => $table, 'local_filename' => "$uuid.txt", 'filename' => 'a.txt',
                'parent_type' => $table, 'parent_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
            return $uuid;
        };
        $other = $file('custom_value_edit_all');
        $mine = $file('custom_value_edit');
        [, $plain] = $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, [
            'tables' => ['custom_value_edit'], 'scopes' => [ApiScope::VALUE_READ, ApiScope::VALUE_WRITE],
        ]);

        // the URL names the table of the key; the file is of another table
        $this->assertRefused($this->getWithKey($plain, ['files', 'custom_value_edit', $other]), 'table');
        $this->assertRefused($this->getWithKey($plain, ['files', $other]), 'table');
        \Auth::forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer $plain"])->delete(admin_urls('api', 'files', 'custom_value_edit', $other))->assertStatus(403);
        $this->assertSame(1, \DB::table('files')->where('uuid', $other)->count());
        // a file of its own table passes the key (what the file API answers then is its own matter)
        $this->getWithKey($plain, ['files', 'custom_value_edit', $mine]);
        $this->assertNull(UserApiKeys::$lastRefusal);
    }

    /**
     * A key is for the API only: not for the OAuth pages under the same middleware, where it could
     * make a token that outlives it.
     */
    public function testNotForTheOAuthRoutes(): void
    {
        [, $plain] = $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, ['scopes' => UserApiKeys::availableScopes()]);
        foreach ([['get', 'tokens'], ['get', 'clients'], ['get', 'personal-access-tokens'], ['post', 'personal-access-tokens'], ['get', 'scopes']] as [$method, $path]) {
            \Auth::forgetGuards();
            $response = $this->withHeaders(['Authorization' => "Bearer $plain", 'Accept' => 'application/json'])
                ->$method(admin_urls('oauth', $path), $method === 'post' ? ['name' => 'x', 'scopes' => []] : []);
            $this->assertContains($response->getStatusCode(), [401, 403], "$method oauth/$path");
            $this->assertNotSame(200, $response->getStatusCode());
        }
        $this->assertSame(0, \DB::table('oauth_access_tokens')->where('name', 'x')->count());
    }

    /**
     * The rate limiter of the API runs before the login: it must not touch the OAuth guard for a
     * key (Passport empties the Authorization header of a bearer that is not its token).
     */
    public function testTheRateLimiterLeavesTheKey(): void
    {
        $this->withMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [, $plain] = $this->makeKey();
        $this->getWithKey($plain, ['me'])->assertStatus(200);
        $this->getWithKey($plain, ['data', 'custom_value_edit'])->assertStatus(200);
    }

    /**
     * A user who signs in only by SSO: the key signs in as the login that made it.
     */
    public function testAUserOfSso(): void
    {
        $login = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        \DB::table('login_users')->where('id', $login->id)->update(['login_provider' => 'test_sso']);

        // without its login: no password login to find
        [, $plain] = $this->makeKey();
        $this->assertRefused($this->getWithKey($plain, ['me']), 'user');

        [$key, $plain] = $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, ['login_user_id' => (int)$login->id]);
        $this->getWithKey($plain, ['me'])->assertStatus(200);
        $this->assertSame((int)$login->id, $key->login_user_id);

        // the login of another user is not taken
        $this->expectException(\InvalidArgumentException::class);
        $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, ['login_user_id' => (int)TestDefine::TESTDATA_USER_LOGINID_USER2]);
    }

    public function testTheRefusalIsKeptOnTheKey(): void
    {
        [$key, $plain] = $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, ['allowed_ips' => '10.0.0.1']);
        $this->assertRefused($this->getWithKey($plain, ['me']), 'ip');
        $key->refresh();
        $this->assertSame('ip', $key->last_refused_reason);
        $this->assertNotNull($key->last_refused_at);
        $this->assertSame(0, $key->use_count);
    }

    /**
     * With ADMIN_TRUST_PROXY_IPS=* every request counts as from the proxy: the header of the
     * certificate is taken from any client (the front server must always overwrite it).
     */
    public function testEveryProxyTrusted(): void
    {
        $fingerprint = hash('sha256', 'a certificate');
        [, $plain] = $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, ['client_cert_fingerprint' => $fingerprint]);
        config(['exment.api_client_cert_header' => static::CERT_HEADER, 'exment.trust_proxy_ips' => '*']);
        $this->getWithKey($plain, ['me'], [static::CERT_HEADER => $fingerprint])->assertStatus(200);
        $this->assertRefused($this->getWithKey($plain, ['me'], [static::CERT_HEADER => '']), 'cert_missing');
    }

    public function testLimitsOfTheKeys(): void
    {
        config(['exment.api_key_max_per_user' => 2, 'exment.api_key_max_days' => 30]);
        $this->makeKey();
        [$second] = $this->makeKey();
        try {
            $this->makeKey();
            $this->fail('a third key was made');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('too_many', $e->getMessage());
        }
        // a stopped key does not count
        UserApiKeys::revoke($second);
        $this->makeKey();

        UserApiKeys::revokeAllOfUser((int)LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1)->base_user_id);
        $this->expectExceptionMessage('expires_at');
        $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, ['expires_at' => Carbon::now()->addDays(31)]);
    }

    public function testClientCertificate(): void
    {
        $fingerprint = hash('sha256', 'a certificate');
        [, $plain] = $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, ['client_cert_fingerprint' => strtoupper(implode(':', str_split($fingerprint, 2)))]);

        // no certificate
        $this->assertRefused($this->getWithKey($plain, ['me']), 'cert_missing');

        // the header is not taken when the front server is not a trusted proxy
        config(['exment.api_client_cert_header' => static::CERT_HEADER]);
        $this->assertRefused($this->getWithKey($plain, ['me'], [static::CERT_HEADER => $fingerprint]), 'cert_missing');

        // from a trusted proxy: the right certificate, and another one
        config(['exment.trust_proxy_ips' => '127.0.0.1']);
        $this->getWithKey($plain, ['me'], [static::CERT_HEADER => $fingerprint])->assertStatus(200);
        $this->assertRefused($this->getWithKey($plain, ['me'], [static::CERT_HEADER => hash('sha256', 'another')]), 'cert_mismatch');

        // the web server itself checked it
        config(['exment.api_client_cert_header' => null]);
        $this->assertRefused($this->getWithKey($plain, ['me'], [], ['SSL_CLIENT_VERIFY' => 'FAILED', 'SSL_CLIENT_CERT' => $fingerprint]), 'cert_missing');
        $this->getWithKey($plain, ['me'], [], ['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_CERT' => $fingerprint])->assertStatus(200);
    }

    public function testTheServerMayAskACertificateOfEveryKey(): void
    {
        [, $plain] = $this->makeKey();
        config(['exment.api_key_require_client_cert' => true]);
        $this->assertRefused($this->getWithKey($plain, ['me']), 'cert_missing');
        $this->getWithKey($plain, ['me'], [], ['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_CERT' => hash('sha256', 'any')])->assertStatus(200);
    }

    public function testWrongValuesAreNotTaken(): void
    {
        $cases = [
            'scopes' => ['scopes' => ['not_a_scope']],
            'no scopes' => ['scopes' => []],
            'tables' => ['tables' => ['not_a_table']],
            'allowed_ips' => ['allowed_ips' => '10.0.0.0/99'],
            'allowed_ips text' => ['allowed_ips' => 'example.com'],
            'expires_at past' => ['expires_at' => Carbon::now()->subDay()],
            'expires_at too far' => ['expires_at' => Carbon::now()->addDays(UserApiKeys::MAX_DAYS + 1)],
            'client_cert_fingerprint' => ['client_cert_fingerprint' => 'abc'],
            'name' => ['name' => '  '],
        ];
        foreach ($cases as $label => $options) {
            try {
                $this->makeKey(TestDefine::TESTDATA_USER_LOGINID_USER1, $options);
                $this->fail("$label was taken");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage(), $label);
            }
        }
    }

    public function testOAuthTokensStillWork(): void
    {
        $token = $this->getUser1AccessToken([ApiScope::VALUE_READ]);
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->get(admin_urls('api', 'data', 'custom_value_edit'))
            ->assertStatus(200);
        // and a token that only looks like a key is not one
        $this->withHeaders(['Authorization' => 'Bearer ' . UserApiKeys::PREFIX])
            ->get(admin_urls('api', 'me'))
            ->assertStatus(401);
    }
}
