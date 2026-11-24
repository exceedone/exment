<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Controllers\UserApiKeyController;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Exceedone\Exment\Model\UserApiKey;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\UserApiKeys;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The pages of the API keys (ISS00437): a user the feature allows makes, sees and stops
 * their own keys; a new key is shown once; the system administrators see all the keys and
 * stop them; nobody else does.
 */
class UserApiKeyPageTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Features::reset();
        config(['exment.features_locked' => '']);
        $this->setFeatures([Features::API_KEYS => ['enabled' => true, 'role_groups' => [$this->groupOfUser1Only()]]]);
    }

    protected function tearDown(): void
    {
        Features::reset();
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

    protected function login(string $loginId): LoginUser
    {
        System::clearRequestSession();
        $user = LoginUser::findOrFail($loginId);
        $this->be($user);
        return $user;
    }

    protected function groupOfUser1Only(): int
    {
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $only1 = array_values(array_diff(Features::roleGroupIds($user1), Features::roleGroupIds($user2)));
        $this->assertNotEmpty($only1);
        return $only1[0];
    }

    protected function baseUserId(string $loginId): int
    {
        return (int)LoginUser::findOrFail($loginId)->base_user_id;
    }

    public function testAUserMakesAKeyShownOnce(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('auth/api_keys'))->assertOk()->assertSee(exmtrans('api_key.my_keys'));

        $page = $this->post(admin_url('auth/api_keys'), ['name' => 'my tool', 'scope_type' => 'read_only', 'table_type' => 'all', 'days' => '30', 'current_password' => 'user1user1']);
        $page->assertOk()->assertSee(exmtrans('api_key.new_key'));
        $this->assertSame(1, preg_match('/exk_[0-9a-f]{48}/', (string)$page->getContent(), $m));
        $plain = strval($m[0] ?? '');

        /** @var UserApiKey $key */
        $key = UserApiKey::where('user_id', $this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1))->firstOrFail();
        $this->assertSame(UserApiKeys::hash($plain), $key->key_hash);
        $this->assertSame(UserApiKeys::readOnlyScopes(), $key->scopes);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $key->expires_at->getTimestamp(), 60);
        // kept nowhere: not in the session, not on the page again
        $this->assertStringNotContainsString($plain, json_encode(session()->all()) ?: '');
        $again = $this->get(admin_url('auth/api_keys'));
        $again->assertOk()->assertSee('my tool')->assertSee($key->key_prefix);
        $again->assertDontSee($plain);
        // it signs in as the login that made it
        $this->assertSame((int)TestDefine::TESTDATA_USER_LOGINID_USER1, $key->login_user_id);
    }

    public function testThePasswordIsAsked(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('auth/api_keys'))->assertSee('name="current_password"', false);
        foreach ([[], ['current_password' => 'wrong'], ['current_password' => ['user1user1']]] as $password) {
            $this->post(admin_url('auth/api_keys'), ['name' => 'x'] + $password)
                ->assertOk()->assertSee(exmtrans('validation.current_password'));
        }
        $this->assertSame(0, UserApiKey::count());
    }

    /**
     * Fields that are not text (name[]=x) are an error on the page, not a 500.
     */
    public function testFieldsThatAreNotText(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->post(admin_url('auth/api_keys'), ['name' => ['x'], 'allowed_ips' => ['a'], 'days' => ['1'], 'scope_type' => ['custom'],
            'client_cert_fingerprint' => ['x'], 'current_password' => 'user1user1'])
            ->assertOk()->assertSee(exmtrans('api_key.errors.name'));
        $this->post(admin_url('auth/api_keys'), ['name' => 'x', 'scope_type' => 'custom', 'scopes' => [['me']], 'current_password' => 'user1user1'])
            ->assertOk()->assertSee(exmtrans('api_key.errors.scopes'));
        $this->assertSame(0, UserApiKey::count());
    }

    public function testTheRefusalIsShown(): void
    {
        [$key] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'refused key', UserApiKeys::readOnlyScopes());
        \Illuminate\Support\Facades\DB::table('user_api_keys')->where('id', $key->id)->update(['last_refused_at' => now(), 'last_refused_reason' => 'ip']);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('auth/api_keys'))->assertOk()->assertSee(exmtrans('api_key.refusals.ip'));
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('api_keys'))->assertOk()->assertSee(exmtrans('api_key.refusals.ip'));
    }

    public function testTheAdminListShowsTheActiveKeysFirst(): void
    {
        [$active] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'still active', UserApiKeys::readOnlyScopes());
        [$stopped] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'already stopped', UserApiKeys::readOnlyScopes());
        UserApiKeys::revoke($stopped);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('api_keys'))->assertOk()->assertSee('still active')->assertDontSee('already stopped');
        $this->get(admin_url('api_keys') . '?status=inactive')->assertOk()->assertSee('already stopped')->assertDontSee('still active');
        $this->get(admin_url('api_keys') . '?status=all')->assertOk()->assertSee('still active')->assertSee('already stopped');
        // a status that is not one: the active keys
        $this->get(admin_url('api_keys') . '?status[]=all')->assertOk()->assertDontSee('already stopped');
    }

    public function testWrongValuesAreRefusedOnThePage(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->post(admin_url('auth/api_keys'), ['name' => 'x', 'scope_type' => 'custom', 'scopes' => ['not_a_scope'], 'current_password' => 'user1user1'])
            ->assertOk()->assertSee(exmtrans('api_key.errors.scopes'));
        $this->post(admin_url('auth/api_keys'), ['name' => 'x', 'allowed_ips' => 'not an ip', 'current_password' => 'user1user1'])
            ->assertOk()->assertSee(exmtrans('api_key.errors.allowed_ips'));
        // a table the user may not see, sent by hand
        $this->post(admin_url('auth/api_keys'), ['name' => 'x', 'table_type' => 'some', 'tables' => ['not_a_table'], 'current_password' => 'user1user1'])
            ->assertOk()->assertSee(exmtrans('api_key.errors.tables'));
        $this->assertSame(0, UserApiKey::count());
    }

    public function testOnlyTheUsersTheFeatureAllows(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->get(admin_url('auth/api_keys'))->assertForbidden();
        $this->post(admin_url('auth/api_keys'), ['name' => 'x'])->assertForbidden();
        $this->assertSame(0, UserApiKey::count());

        $this->setFeatures([Features::API_KEYS => ['enabled' => false]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('auth/api_keys'))->assertNotFound();
    }

    public function testAUserStopsOnlyTheirOwnKeys(): void
    {
        [$other] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER2), 'other', UserApiKeys::readOnlyScopes());
        [$mine] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'mine', UserApiKeys::readOnlyScopes());

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('auth/api_keys'))->assertOk()->assertSee('mine')->assertDontSee('other');
        // (an ajax request: the admin pages show a "not found" page with 200 otherwise)
        $this->post(admin_url("auth/api_keys/{$other->id}/revoke"), [], ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();
        $this->post(admin_url("auth/api_keys/{$other->id}/revoke"))->assertDontSee(exmtrans('api_key.message_revoked'));
        $this->assertNull($other->refresh()->revoked_at);

        $this->post(admin_url("auth/api_keys/{$mine->id}/revoke"))->assertRedirect();
        $this->assertNotNull($mine->refresh()->revoked_at);
    }

    public function testTheAdministratorsSeeAndStopAllKeys(): void
    {
        [$a] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'key a', UserApiKeys::readOnlyScopes());
        [$b] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'key b', UserApiKeys::readOnlyScopes());
        [$c] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER2), 'key c', UserApiKeys::readOnlyScopes());

        // not for the other users (an ajax request, so the denial is a plain 403)
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('api_keys'), $ajax)->assertForbidden()->assertDontSee('key c');
        $this->post(admin_url("api_keys/{$c->id}/revoke"), [], $ajax)->assertForbidden();
        $this->post(admin_url('api_keys/user/' . $this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER2) . '/revoke'), [], $ajax)->assertForbidden();
        $this->assertNull($c->refresh()->revoked_at);

        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('api_keys'))->assertOk()->assertSee('key a')->assertSee('key c')->assertDontSee($a->key_hash);
        $this->post(admin_url("api_keys/{$c->id}/revoke"))->assertRedirect();
        $this->assertNotNull($c->refresh()->revoked_at);
        $this->post(admin_url('api_keys/user/' . $this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1) . '/revoke'))->assertRedirect();
        $this->assertNotNull($a->refresh()->revoked_at);
        $this->assertNotNull($b->refresh()->revoked_at);

        // the admin page stays when the feature is off (to stop keys before it is turned on again)
        $this->setFeatures([Features::API_KEYS => ['enabled' => false]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('api_keys'))->assertOk()->assertSee(exmtrans('api_key.feature_off'));
    }

    /**
     * The password on the form has its own limit of tries (it does not count toward the lockout
     * of the login page).
     */
    public function testThePasswordHasALimitOfTries(): void
    {
        $user = $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        RateLimiter::clear(UserApiKeyController::passwordLimiterKey((int)$user->id));
        for ($i = 0; $i < UserApiKeyController::PASSWORD_TRIES; $i++) {
            $this->post(admin_url('auth/api_keys'), ['name' => 'x', 'current_password' => 'wrong'])->assertSee(exmtrans('validation.current_password'));
        }
        // now even the right password waits
        $this->post(admin_url('auth/api_keys'), ['name' => 'x', 'current_password' => 'user1user1'])
            ->assertOk()->assertSee(exmtrans('api_key.errors.password_tries', 15));
        $this->assertSame(0, UserApiKey::count());
        RateLimiter::clear(UserApiKeyController::passwordLimiterKey((int)$user->id));
    }

    /**
     * A user of SSO makes a key only soon after signing in, and the key is shorter.
     */
    public function testAUserOfSsoSignsInAgain(): void
    {
        \Illuminate\Support\Facades\DB::table('login_users')->where('id', TestDefine::TESTDATA_USER_LOGINID_USER1)->update(['login_provider' => 'test_sso']);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        config(['exment.api_key_max_days_sso' => 30]);

        // not signed in recently: sent to sign in again
        $this->post(admin_url('auth/api_keys'), ['name' => 'sso key'])
            ->assertOk()->assertSee(exmtrans('api_key.errors.sign_in_again', 15))->assertSee(admin_url('auth/logout'), false);
        $this->withSession([UserApiKeys::SESSION_SIGNED_IN_AT => time() - 20 * 60])
            ->post(admin_url('auth/api_keys'), ['name' => 'sso key'])->assertSee(exmtrans('api_key.errors.sign_in_again', 15));
        $this->assertSame(0, UserApiKey::count());

        // the Login event of the pages remembers when
        UserApiKeys::rememberSignIn('admin');
        $this->assertTrue(UserApiKeys::signedInRecently());
        UserApiKeys::rememberSignIn('adminapi');

        $page = $this->withSession([UserApiKeys::SESSION_SIGNED_IN_AT => time()])
            ->post(admin_url('auth/api_keys'), ['name' => 'sso key', 'days' => '365']);
        $page->assertOk()->assertSee(exmtrans('api_key.new_key'));
        /** @var UserApiKey $key */
        $key = UserApiKey::firstOrFail();
        // 365 is not offered to SSO: the default within 30 days
        $this->assertLessThanOrEqual(now()->addDays(30)->getTimestamp() + 60, $key->expires_at->getTimestamp());
    }

    /**
     * The user is told by mail when a key is made and when an administrator stops keys; the mail
     * has no key.
     */
    public function testTheMails(): void
    {
        Notification::fake();
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $page = $this->post(admin_url('auth/api_keys'), ['name' => 'mailed key', 'current_password' => 'user1user1']);
        $page->assertOk()->assertSee(exmtrans('api_key.new_key'));
        Notification::assertSentTimes(MailSendJob::class, 1);

        /** @var UserApiKey $key */
        $key = UserApiKey::firstOrFail();
        $sender = UserApiKeys::mailCreated($key, '192.0.2.1');
        $this->assertNotNull($sender);
        $body = (string)$sender->getBody();
        $this->assertStringContainsString('mailed key', $body);
        $this->assertStringContainsString('192.0.2.1', $body);
        $this->assertStringContainsString(admin_url('auth/api_keys'), $body);
        $this->assertStringNotContainsString(UserApiKeys::PREFIX, $body);

        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->post(admin_url('api_keys/user/' . $key->user_id . '/revoke'))->assertRedirect();
        Notification::assertSentTimes(MailSendJob::class, 3);
        // nothing stopped: no mail
        $this->post(admin_url('api_keys/user/' . $key->user_id . '/revoke'))->assertRedirect();
        Notification::assertSentTimes(MailSendJob::class, 3);
    }

    /**
     * A refusal the key got past (it was used after) is not shown any more.
     */
    public function testAnOldRefusalIsNotShown(): void
    {
        [$key] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'used again', UserApiKeys::readOnlyScopes());
        \Illuminate\Support\Facades\DB::table('user_api_keys')->where('id', $key->id)->update([
            'last_refused_at' => now()->subHour(), 'last_refused_reason' => 'ip', 'last_used_at' => now(),
        ]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('auth/api_keys'))->assertOk()->assertSee('used again')->assertDontSee(exmtrans('api_key.refusals.ip'));
    }

    public function testThePageExplainsTheAddresses(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('auth/api_keys'))->assertOk()->assertSee('ADMIN_TRUST_PROXY_IPS')->assertSee('CloudFront');
    }

    /**
     * The forms of the keys are in the web group, with the CSRF check.
     */
    public function testTheFormsCheckCsrf(): void
    {
        $router = app('router');
        foreach (['auth/api_keys', 'auth/api_keys/1/revoke', 'api_keys/1/revoke', 'api_keys/user/1/revoke'] as $path) {
            $route = $router->getRoutes()->match(\Illuminate\Http\Request::create(admin_url($path), 'POST'));
            $this->assertContains(\Exceedone\Exment\Middleware\VerifyCsrfToken::class, $router->gatherRouteMiddleware($route), $path);
        }
        // and the check refuses a POST without its token
        $request = \Illuminate\Http\Request::create(admin_url('auth/api_keys'), 'POST', ['name' => 'x']);
        $request->setLaravelSession(app('session')->driver());
        $middleware = new class (app(), app('encrypter')) extends \Exceedone\Exment\Middleware\VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        };
        $this->expectException(\Illuminate\Session\TokenMismatchException::class);
        $middleware->handle($request, function () {
            return response('passed');
        });
    }

    /**
     * Without the role groups (every user may use the admin pages), the keys of everyone are still
     * for the system administrators only.
     */
    public function testWithoutRoleGroups(): void
    {
        [$key] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER2), 'key of user2', UserApiKeys::readOnlyScopes());
        System::permission_available(false);
        System::clearRequestSession();
        try {
            $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
            $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
            $this->get(admin_url('api_keys'), $ajax)->assertForbidden();
            $this->post(admin_url("api_keys/{$key->id}/revoke"), [], $ajax)->assertForbidden();
            $this->assertNull($key->refresh()->revoked_at);

            $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
            $this->get(admin_url('api_keys'))->assertOk()->assertSee('key of user2');
        } finally {
            System::permission_available(true);
            System::clearRequestSession();
        }
    }

    /**
     * The link of the mail is on the URL of the site, not the host of the request; a name with
     * ${...} is not replaced in the mail.
     */
    public function testTheMailLinkAndName(): void
    {
        config(['app.url' => 'https://exment.example.invalid']);
        \Illuminate\Http\Request::setTrustedProxies(['*'], \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST);
        try {
            $this->withHeaders(['X-Forwarded-Host' => 'evil.example.invalid'])->get(admin_url('auth/setting'));
            [$key] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'name ${system.site_name} x', UserApiKeys::readOnlyScopes());
            $sender = UserApiKeys::mailCreated($key, '192.0.2.1');
            $this->assertNotNull($sender);
            $body = (string)$sender->getBody();
            $this->assertStringContainsString('https://exment.example.invalid/', $body);
            $this->assertStringNotContainsString('evil.example.invalid', $body);
            $this->assertStringContainsString('$ {system.site_name}', $body);
            $this->assertStringStartsWith('https://exment.example.invalid/', UserApiKeys::keysPageUrl());
        } finally {
            \Illuminate\Http\Request::setTrustedProxies([], \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR);
        }
    }

    /**
     * A sign-in to the pages (the login form; the OAuth and SAML pages sign in through the same
     * guard, SessionGuard::attempt) remembers when, for the users of SSO.
     */
    public function testASignInIsRemembered(): void
    {
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->post(admin_url('auth/login'), ['username' => 'user1', 'password' => 'user1user1'])->assertRedirect();
        $this->assertIsInt(session(UserApiKeys::SESSION_SIGNED_IN_AT));
        $this->assertTrue(UserApiKeys::signedInRecently());

        // the guard of the pages directly (what the SSO controllers call in the end)
        $this->flushSession();
        \Illuminate\Support\Facades\Auth::guard('admin')->login(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->assertTrue(UserApiKeys::signedInRecently());
        // not the API
        $this->flushSession();
        \Illuminate\Support\Facades\Auth::guard('adminapi')->setUser(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->assertFalse(UserApiKeys::signedInRecently());
    }
}
