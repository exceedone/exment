<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PasswordHistory;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Providers\RouteServiceProvider;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;

/**
 * Laravel 11+ rehashes the password after a login when the bcrypt cost changed ("rehash_on_login").
 * Accounts from Exment v6 (Laravel 10, cost 10) are rehashed on their first login on Laravel 12 (cost 12).
 *
 * Before the fix the framework saved the new hash through LoginUser's saving mutator, which hashed
 * it again (the second login failed), and an SSO login, which has no password in its credentials,
 * failed with "SSO provider error".
 *
 * The test creates its own users and rolls everything back, so it does not need `exment:inittest` data.
 */
class LoginRehashOnLoginTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Rehash-test-1234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['hashing.rehash_on_login' => true]);
    }

    protected function tearDown(): void
    {
        // the login settings are cached for the request
        System::clearCache();
        parent::tearDown();
    }

    public function testPasswordLoginWithALowCostHashStillWorksAfterTheRehash(): void
    {
        $this->useBcryptRounds(10);
        [$code, $loginUser] = $this->createPureLoginUser();
        $this->assertStringStartsWith('$2y$10$', $loginUser->password);
        $historyCount = $this->historyCount($loginUser);

        $this->useBcryptRounds(12);
        $this->assertTrue($this->attemptPasswordLogin($code), 'first login');

        $rehashed = $this->storedPassword($loginUser);
        $this->assertStringStartsWith('$2y$12$', $rehashed);
        $this->assertTrue(Hash::check(self::PASSWORD, $rehashed));
        // the password did not change: no password history
        $this->assertSame($historyCount, $this->historyCount($loginUser));

        $this->assertTrue($this->attemptPasswordLogin($code), 'second login');
        $this->assertSame($rehashed, $this->storedPassword($loginUser));
    }

    public function testPasswordLoginWithTheCurrentCostKeepsTheHash(): void
    {
        $this->useBcryptRounds(12);
        [$code, $loginUser] = $this->createPureLoginUser();
        $hash = $loginUser->password;

        $this->assertTrue($this->attemptPasswordLogin($code));
        $this->assertSame($hash, $this->storedPassword($loginUser));
    }

    public function testWrongPasswordDoesNotRehash(): void
    {
        $this->useBcryptRounds(10);
        [$code, $loginUser] = $this->createPureLoginUser();
        $hash = $loginUser->password;

        $this->useBcryptRounds(12);
        $this->assertFalse($this->attemptPasswordLogin($code, 'wrong-password'));
        $this->assertSame($hash, $this->storedPassword($loginUser));
    }

    /**
     * Pasting the stored hash into the password box of the login form is refused, before and
     * after the rehash, and does not change the stored hash.
     */
    public function testTheStoredHashIsNotAcceptedAsThePassword(): void
    {
        $this->withoutMiddleware(\Exceedone\Exment\Middleware\VerifyCsrfToken::class);
        $this->useBcryptRounds(10);
        [$code, $loginUser] = $this->createPureLoginUser();
        $lowCostHash = $loginUser->password;

        $this->useBcryptRounds(12);
        $this->postLoginForm($code, $lowCostHash);
        $this->assertGuest('admin');
        $this->assertSame($lowCostHash, $this->storedPassword($loginUser));

        $this->postLoginForm($code, self::PASSWORD);
        $this->assertAuthenticatedAs($loginUser, 'admin');
        $rehashed = $this->storedPassword($loginUser);
        $this->assertStringStartsWith('$2y$12$', $rehashed);

        foreach ([$rehashed, $lowCostHash] as $hash) {
            $this->postLoginForm($code, $hash);
            $this->assertGuest('admin');
            $this->assertSame($rehashed, $this->storedPassword($loginUser));
        }
    }

    /**
     * A password that looks like a bcrypt hash is a password as any other (as on v6): LoginUser
     * hashes it, and that string logs in, before and after the rehash.
     */
    public function testAPasswordThatLooksLikeAHashLogsIn(): void
    {
        $this->useBcryptRounds(10);
        $hashLikePassword = Hash::make('another-password');
        [$code, $loginUser] = $this->createPureLoginUser($hashLikePassword);
        $this->assertNotSame($hashLikePassword, $this->storedPassword($loginUser));

        $this->useBcryptRounds(12);
        $this->assertTrue($this->attemptPasswordLogin($code, $hashLikePassword), 'first login');
        $this->assertStringStartsWith('$2y$12$', $this->storedPassword($loginUser));
        $this->assertTrue($this->attemptPasswordLogin($code, $hashLikePassword), 'second login');
        $this->assertFalse($this->attemptPasswordLogin($code, 'another-password'));
    }

    public function testOAuthLoginOfAnAccountWithALowCostHashLogsIn(): void
    {
        $this->useBcryptRounds(10);
        [$code, $pureUser] = $this->createPureLoginUser();
        $oauthUser = new LoginUser();
        $oauthUser->base_user_id = $pureUser->base_user_id;
        $oauthUser->login_type = LoginType::OAUTH;
        $oauthUser->login_provider = 'google';
        $oauthUser->password = make_password(32);
        $oauthUser->save();
        $hash = $oauthUser->password;
        $this->assertStringStartsWith('$2y$10$', $hash);

        $this->useBcryptRounds(12);
        $this->createOAuthSetting();
        $this->mapSsoRoutes();
        $this->fakeGoogleUser($code . '@example.com');

        $response = $this->get(admin_url('auth/login/google/callback'));

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('auth/login', (string) $response->headers->get('Location'));
        $this->assertAuthenticatedAs($oauthUser, 'admin');
        // an SSO account has no Exment password to rehash
        $this->assertSame($hash, $this->storedPassword($oauthUser));
    }

    /**
     * The guard reads the hasher and "rehash_on_login" when it is created.
     */
    private function useBcryptRounds(int $rounds): void
    {
        config(['hashing.bcrypt.rounds' => $rounds]);
        app('hash')->forgetDrivers();
        app('auth')->forgetGuards();
    }

    /**
     * Login as AuthController does, in a new browser (no logged-in user).
     */
    private function attemptPasswordLogin(string $code, string $password = self::PASSWORD): bool
    {
        app('auth')->forgetGuards();

        return Auth::guard('admin')->attempt([
            'username' => $code,
            'password' => $password,
            'login_type' => LoginType::PURE,
        ]);
    }

    /**
     * Post the login form (AuthController) in a new browser.
     */
    private function postLoginForm(string $code, string $password): void
    {
        app('auth')->forgetGuards();
        $this->flushSession();

        $this->post(admin_url('auth/login'), [
            'username' => $code,
            'password' => $password,
        ])->assertRedirect();
    }

    /**
     * @return array{0: string, 1: LoginUser}
     */
    private function createPureLoginUser(string $password = self::PASSWORD): array
    {
        $code = 'rehash_' . short_uuid();

        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
        $user->setValue([
            'user_code' => $code,
            'user_name' => $code,
            'email' => $code . '@example.com',
        ]);
        $user->saved_notify(false);
        $user->save();

        $loginUser = new LoginUser();
        $loginUser->base_user_id = $user->id;
        $loginUser->password = $password;
        $loginUser->save();

        return [$code, $loginUser];
    }

    private function storedPassword(LoginUser $loginUser): string
    {
        return (string) LoginUser::query()->whereKey($loginUser->id)->value('password');
    }

    private function historyCount(LoginUser $loginUser): int
    {
        return PasswordHistory::where('login_user_id', $loginUser->id)->count();
    }

    private function createOAuthSetting(): LoginSetting
    {
        $setting = LoginSetting::create([
            'login_view_name' => 'Rehash test oauth',
            'login_type' => LoginType::OAUTH,
            'active_flg' => 1,
            'options' => [
                'oauth_provider_type' => 'google',
                'oauth_client_id' => 'rehash-test-client',
                'oauth_client_secret' => 'rehash-test-secret',
                'mapping_user_column' => 'email',
            ],
        ]);
        System::clearCache();

        return $setting;
    }

    /**
     * RouteServiceProvider registers the OAuth login routes at boot only when an active
     * setting exists: register them again as the application does.
     */
    private function mapSsoRoutes(): void
    {
        $map = new \ReflectionMethod(RouteServiceProvider::class, 'mapExmentAnonymousWebRotes');
        $map->invoke(new RouteServiceProvider($this->app));
        $routes = $this->app['router']->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }

    /**
     * Google answers the given user: the provider object exment asks for its user() is a stub.
     */
    private function fakeGoogleUser(string $email): void
    {
        $user = (new SocialiteUser())->setToken('rehash-test-token')->map([
            'id' => 'rehash-test-google-id',
            'name' => 'Rehash test',
            'email' => $email,
            'avatar' => null,
        ]);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->allows(['scopes' => $provider, 'user' => $user]);
        Socialite::shouldReceive('with')->with('google')->andReturn($provider);
    }
}
