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
            $options['client_cert_fingerprint'] ?? null
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
        return $this->withServerVariables($server)
            ->withHeaders(['Authorization' => "Bearer $plain"] + $headers)
            ->get(admin_urls('api', ...$path));
    }

    /**
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function assertRefused(\Illuminate\Testing\TestResponse $response, string $why): void
    {
        $response->assertStatus(401);
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
