<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Controllers\AuthOAuthController;
use Exceedone\Exment\Enums\LoginProviderType;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DataImportExport\Providers\Import\LoginUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Helpers of the tests about password (Password*Test).
 *
 * Browsers are told apart by their cookies: call resetAuth(), then send the cookie of the browser.
 *
 * The test class defines the constants. (Constants of trait are for PHP 8.2 or later)
 * - OLD_PASSWORD : password of the login user before the test changes it
 * - NEW_PASSWORD : password the test changes to
 * - TYPED_PASSWORD : password typed into a form that is sent back
 */
trait PasswordTestTrait
{
    // ------------------------------------------------------------------ //
    //  users                                                              //
    // ------------------------------------------------------------------ //

    /**
     * Login user id 1, with email and OLD_PASSWORD. "remember me" is not used yet.
     */
    protected function prepareLoginUser(): LoginUser
    {
        $login_user = LoginUser::find(1);
        if (is_null($login_user)) {
            $this->markTestSkipped('login user id 1 is not found');
        }
        if (empty($login_user->base_user->getValue('email'))) {
            $login_user->base_user->setValue(['email' => 'password-test@example.invalid'])->saved_notify(false)->save();
        }
        DB::table('login_users')->where('id', 1)->update([
            'password' => Hash::make(static::OLD_PASSWORD),
            'remember_token' => null,
            'password_reset_flg' => 0,
        ]);
        $login_user = LoginUser::find(1);
        $this->assertNotEmpty($login_user->email);
        DB::table('password_reset_tokens')->where('email', $login_user->email)->delete();

        return $login_user;
    }

    /**
     * Create a user (custom value) that does not have login user. No role group is assigned.
     */
    protected function createUser(): CustomValue
    {
        $code = 'passwordtest_' . short_uuid();

        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
        $user->setValue([
            'user_code' => $code,
            'user_name' => $code,
            'email' => $code . '@example.invalid',
        ]);
        $user->saved_notify(false);
        $user->save();

        return $user;
    }

    /**
     * Create a user and its login user, with OLD_PASSWORD.
     */
    protected function createLoginUser(): LoginUser
    {
        $login_user = new LoginUser();
        $login_user->base_user_id = $this->createUser()->id;
        $login_user->password = static::OLD_PASSWORD;
        $login_user->save();

        return $login_user;
    }

    /**
     * @return mixed
     */
    protected function column(LoginUser $login_user, string $column)
    {
        return DB::table('login_users')->where('id', $login_user->id)->value($column);
    }

    protected function assertPasswordIs(LoginUser $login_user, string $password): void
    {
        $stored = (string) $this->column($login_user, 'password');
        $this->assertTrue(Hash::check($password, $stored), 'stored password is not the expected one');
    }

    // ------------------------------------------------------------------ //
    //  browsers                                                           //
    // ------------------------------------------------------------------ //

    protected function guard(): SessionGuard
    {
        $guard = Auth::guard('admin');
        if (!($guard instanceof SessionGuard)) {
            $this->fail('admin guard is not a session guard');
        }

        return $guard;
    }

    /**
     * Forget everything a browser would hold: signed-in user, session, cookies.
     */
    protected function resetAuth(): void
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        // cookies queued by the requests before are sent with every response of the test
        app('cookie')->flushQueuedCookies();
    }

    /**
     * Sign in on the login screen as a new browser, "remember me" checked.
     *
     * @return string remember cookie value as the browser holds it (decrypted)
     */
    protected function loginWithRemember(LoginUser $login_user, ?string $password = null): string
    {
        $this->resetAuth();
        $response = $this->postAs(admin_url('auth/login'), [
            'username' => $login_user->base_user->getValue('user_code'),
            'password' => $password ?? static::OLD_PASSWORD,
            'remember' => 1,
        ]);
        $this->assertAuthenticatedAs($login_user, 'admin');

        $value = $this->cookieOf($response, $this->guard()->getRecallerName());
        $this->assertStringStartsWith($login_user->id . '|', $value);

        return $value;
    }

    /**
     * Cookie the browser holds after it received the response (decrypted).
     *
     * @param TestResponse $response
     */
    protected function cookieOf(TestResponse $response, string $cookie_name): string
    {
        $cookie = $response->getCookie($cookie_name);
        if (is_null($cookie)) {
            $this->fail('response does not have the cookie: ' . $cookie_name);
        }

        return (string) $cookie->getValue();
    }

    /**
     * Open the admin top page as a browser that has nothing but the remember cookie.
     *
     * @return array{authenticated: bool, via_remember: bool, status: int, location: string}
     */
    protected function browseWithOnlyRememberCookie(string $cookie): array
    {
        return $this->browse([$this->guard()->getRecallerName() => $cookie]);
    }

    /**
     * Open the admin top page as a browser that has nothing but the cookies.
     *
     * @param array<string, string> $cookies
     * @return array{authenticated: bool, via_remember: bool, status: int, location: string}
     */
    protected function browse(array $cookies): array
    {
        $this->resetAuth();
        $response = $this->withCookies($cookies)->get(admin_url(''));

        $guard = $this->guard();
        $state = [
            'authenticated' => $guard->check(),
            'via_remember' => $guard->viaRemember(),
            'status' => $response->getStatusCode(),
            'location' => (string) ($response->headers->get('Location') ?? admin_url('')),
        ];
        $this->resetAuth();

        return $state;
    }

    /**
     * Data of the session as the session driver stores it.
     *
     * @return array<string, mixed>|null null if the session is not stored
     */
    protected function storedSession(string $session_id): ?array
    {
        $payload = $this->app->make('session.store')->getHandler()->read($session_id);
        if (!is_string($payload) || $payload === '') {
            return null;
        }
        $data = unserialize($payload);

        return is_array($data) ? $data : null;
    }

    // ------------------------------------------------------------------ //
    //  SSO                                                                //
    // ------------------------------------------------------------------ //

    /**
     * Set OAuth login up. The provider always answers that the user signed in to it.
     *
     * @return string url the provider redirects the browser to, after the user signed in to it
     */
    protected function prepareSsoLogin(CustomValue $user): string
    {
        $provider_name = 'passwordtest';
        LoginSetting::create([
            'login_view_name' => $provider_name,
            'login_type' => LoginType::OAUTH,
            'active_flg' => 1,
            'options' => [
                'oauth_provider_type' => LoginProviderType::OTHER,
                'oauth_provider_name' => $provider_name,
                'mapping_user_column' => 'email',
                'sso_jit' => '0',
                'update_user_info' => '0',
            ],
        ]);

        $provider = \Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn((new SocialiteUser())->map([
            'id' => $user->getValue('user_code'),
            'name' => $user->getValue('user_name'),
            'email' => $user->getValue('email'),
        ]));
        Socialite::shouldReceive('with')->with($provider_name)->andReturn($provider);

        // The route is defined only if OAuth setting exists when the application boots
        Route::middleware(['adminweb', 'admin_anonymous'])
            ->get(admin_base_path('auth/login/{provider}/callback'), [AuthOAuthController::class, 'callback']);

        return admin_url("auth/login/{$provider_name}/callback");
    }

    /**
     * Sign in by SSO as a new browser: the provider redirects the browser to the callback.
     */
    protected function loginBySso(string $callback_url): TestResponse
    {
        $this->resetAuth();
        $response = $this->get($callback_url);
        $response->assertRedirect(admin_url(''));
        $this->assertAuthenticated('admin');

        return $response;
    }

    // ------------------------------------------------------------------ //
    //  screens                                                            //
    // ------------------------------------------------------------------ //

    /**
     * @param array<string, mixed> $data
     * @return TestResponse
     */
    protected function postAs(string $url, array $data): TestResponse
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)->from($url)->post($url, $data);
    }

    /**
     * Token of the emailed link to reset the password.
     */
    protected function createToken(LoginUser $login_user): string
    {
        return Password::broker('exment_admins')->createToken($login_user);
    }

    /**
     * Reset password screen (auth/reset/{token}): the user chose a password.
     *
     * @return TestResponse
     */
    protected function postReset(string $token, string $password): TestResponse
    {
        return $this->postResetRaw($token, ['token' => $token, 'password' => $password, 'password_confirmation' => $password]);
    }

    /**
     * Reset password screen, with the input as the browser sends it.
     *
     * @param string $url_token token in the url (also the page the browser is sent back to)
     * @param array<string, string> $data
     * @return TestResponse
     */
    protected function postResetRaw(string $url_token, array $data): TestResponse
    {
        return $this->postAs(admin_url('auth/reset/' . $url_token), $data);
    }

    /**
     * Account setting screen of the signed in user.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @return TestResponse
     */
    protected function putSetting(array $data, array $headers = []): TestResponse
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->from(admin_url('auth/setting'))
            ->put(admin_url('auth/setting'), $data, $headers);
    }

    /**
     * Login user screen: an administrator sets the password of a user.
     *
     * @param array<string, mixed> $data
     * @return TestResponse
     */
    protected function putLoginUser(LoginUser $login_user, string $password, array $data = []): TestResponse
    {
        $url = admin_urls('loginuser', $login_user->base_user_id);

        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->from($url . '/edit')
            ->put($url, array_merge([
                'use_loginuser' => '1',
                'reset_password' => '1',
                'create_password_auto' => '0',
                'password' => $password,
                'password_confirmation' => $password,
                'send_password' => '0',
                'password_reset_flg' => '0',
            ], $data));
    }

    /**
     * Import of login users, a row of the file.
     *
     * @param array<string, mixed> $data
     */
    protected function importLoginUser(LoginUser $login_user, array $data): void
    {
        (new LoginUserProvider(['primary_key' => 'id']))->importData([
            'data' => array_merge(['id' => $login_user->base_user_id, 'use_loginuser' => '1'], $data),
            'model' => $login_user->base_user,
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function passwordInputs(string $html): array
    {
        preg_match_all('/<input[^>]*type="password"[^>]*>/', $html, $matches);

        return $matches[0];
    }

    /**
     * @return array<string, mixed>
     */
    protected function flashedInput(): array
    {
        $flashed = $this->app->make('session.store')->get('_old_input', []);

        return is_array($flashed) ? $flashed : [];
    }

    protected function assertPasswordNotFlashed(): void
    {
        $flashed = $this->flashedInput();
        foreach (['password', 'password_confirmation', 'current_password'] as $key) {
            $this->assertArrayNotHasKey($key, $flashed, $key . ' is flashed to the session: ' . json_encode($flashed));
        }

        // nor under another key. Passwords of the test are the constants named *_PASSWORD
        foreach ((new \ReflectionClass(static::class))->getConstants() as $name => $password) {
            if (is_string($password) && substr($name, -9) === '_PASSWORD') {
                $this->assertStringNotContainsString($password, (string) json_encode($flashed), $name . ' is flashed to the session');
            }
        }
    }

    // ------------------------------------------------------------------ //
    //  mail                                                               //
    // ------------------------------------------------------------------ //

    /**
     * Send mails really, by a mailer that cannot reach its server.
     */
    protected function useFailingMailer(): void
    {
        Notification::swap(new ChannelManager($this->app));
        Mail::extend('failing', function () {
            return new class () extends AbstractTransport {
                protected function doSend(SentMessage $message): void
                {
                    throw new TransportException('mail server is down');
                }

                public function __toString(): string
                {
                    return 'failing://';
                }
            };
        });
        config([
            'mail.default' => 'failing',
            'mail.mailers.failing' => ['transport' => 'failing'],
            'queue.default' => 'sync',
        ]);
        app('mail.manager')->forgetMailers();
    }
}
