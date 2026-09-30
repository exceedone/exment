<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\SsoLoginErrorType;
use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\Installer\InitializeForm;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * Password handling outside of the "forgot password" link (see PasswordResetRememberFlashTest).
 *
 * 1. Every way of changing a password rotates remember_token, so that a "remember me" cookie
 *    issued before the change does not sign in any more:
 *    change password screen (auth/change), reset by an administrator (login user screen,
 *    exment:resetpassword, import), account setting screen (auth/setting).
 * 2. A typed password is neither flashed to the session nor printed to the html:
 *    failed login, error while signing in, login user screen, change password screen.
 * 3. Change password screen (auth/change) sent by a browser that is not signed in goes to login page.
 *    The screen is in the routes that do not check login.
 *
 * Every test asserts the expected (safe) behaviour. Tests named "...Control..." only prove that
 * the scenario itself is set up correctly.
 *
 * Uses login user id 1 (temporary email and password). Everything is rolled back, no mail is sent.
 */
class PasswordChangeSecurityTest extends FeatureTestBase
{
    use DatabaseTransactions;
    use PasswordTestTrait;

    protected const OLD_PASSWORD = 'Old-Leaked-2026!';
    protected const NEW_PASSWORD = 'New-Secure-2026!';
    protected const TYPED_PASSWORD = 'Typed-Secret-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();
    }

    // ------------------------------------------------------------------ //
    //  1. remember_token is rotated                                       //
    // ------------------------------------------------------------------ //

    public function testChangePasswordScreenRotatesRememberToken(): void
    {
        $login_user = $this->prepareLoginUser();
        $cookie = $this->loginWithRemember($login_user);
        $before = $this->column($login_user, 'remember_token');

        // same browser, still signed in
        $this->postAs(admin_url('auth/change'), [
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ])->assertRedirect(admin_url('auth/login'));

        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);
        $this->assertNotSame($before, $this->column($login_user, 'remember_token'), 'remember_token must be rotated');
        $this->assertFalse($this->browseWithOnlyRememberCookie($cookie)['authenticated'], 'remember cookie issued before the change still signs in');
    }

    public function testResetByServiceRotatesRememberToken(): void
    {
        $login_user = $this->prepareLoginUser();
        $cookie = $this->loginWithRemember($login_user);
        $before = $this->column($login_user, 'remember_token');
        $this->resetAuth();

        // exment:resetpassword and import call the service the same way
        LoginService::resetPassword($login_user->refresh(), ['password' => static::NEW_PASSWORD]);

        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);
        $this->assertNotSame($before, $this->column($login_user, 'remember_token'), 'remember_token must be rotated');
        $this->assertFalse($this->browseWithOnlyRememberCookie($cookie)['authenticated'], 'remember cookie issued before the reset still signs in');
    }

    public function testImportRotatesRememberToken(): void
    {
        $login_user = $this->prepareLoginUser();
        $cookie = $this->loginWithRemember($login_user);
        $before = $this->column($login_user, 'remember_token');
        $this->resetAuth();

        $this->importLoginUser($login_user->refresh(), ['password' => static::NEW_PASSWORD]);

        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);
        $this->assertNotSame($before, $this->column($login_user, 'remember_token'), 'remember_token must be rotated');
        $this->assertFalse($this->browseWithOnlyRememberCookie($cookie)['authenticated'], 'remember cookie issued before the import still signs in');
    }

    public function testResetOnLoginUserScreenRotatesRememberToken(): void
    {
        $login_user = $this->prepareLoginUser();
        $cookie = $this->loginWithRemember($login_user);
        $before = $this->column($login_user, 'remember_token');

        $this->putLoginUser($login_user, static::NEW_PASSWORD)->assertRedirect();

        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);
        $this->assertNotSame($before, $this->column($login_user, 'remember_token'), 'remember_token must be rotated');
        $this->assertFalse($this->browseWithOnlyRememberCookie($cookie)['authenticated'], 'remember cookie issued before the reset still signs in');
    }

    public function testAccountSettingScreenRotatesRememberToken(): void
    {
        $login_user = $this->prepareLoginUser();
        $cookie = $this->loginWithRemember($login_user);
        $before = $this->column($login_user, 'remember_token');

        $this->putSetting([
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ])->assertRedirect(admin_url('auth/setting'));

        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);
        $this->assertNotSame($before, $this->column($login_user, 'remember_token'), 'remember_token must be rotated');
        $this->assertFalse($this->browseWithOnlyRememberCookie($cookie)['authenticated'], 'remember cookie issued before the change still signs in');
    }

    public function testControlAccountSettingWithoutPasswordKeepsRememberToken(): void
    {
        $login_user = $this->prepareLoginUser();
        $cookie = $this->loginWithRemember($login_user);
        $before = $this->column($login_user, 'remember_token');

        $this->putSetting([])->assertRedirect(admin_url('auth/setting'));

        $this->assertPasswordIs($login_user, static::OLD_PASSWORD);
        $this->assertSame($before, $this->column($login_user, 'remember_token'), 'remember_token must stay when the password is not changed');
        $this->assertTrue($this->browseWithOnlyRememberCookie($cookie)['authenticated'], 'remember cookie should keep working when the password is not changed');
    }

    public function testImportWithSamePasswordKeepsRememberToken(): void
    {
        $login_user = $this->prepareLoginUser();
        $cookie = $this->loginWithRemember($login_user);
        $before = $this->column($login_user, 'remember_token');
        $password = $this->column($login_user, 'password');
        $this->resetAuth();

        // the file has the password the user already has
        $this->importLoginUser($login_user->refresh(), ['password' => static::OLD_PASSWORD]);

        $this->assertSame($password, $this->column($login_user, 'password'), 'password should be left as it was');
        $this->assertSame($before, $this->column($login_user, 'remember_token'), 'remember_token must stay when the password is not changed');
        $this->assertTrue($this->browseWithOnlyRememberCookie($cookie)['authenticated'], 'remember cookie should keep working when the password is not changed');
    }

    public function testPasswordSavedByModelRotatesRememberToken(): void
    {
        $login_user = $this->prepareLoginUser();
        $cookie = $this->loginWithRemember($login_user);
        $before = $this->column($login_user, 'remember_token');
        $this->resetAuth();

        // ex. a plugin or a batch. None of the screens is used
        $model = $login_user->refresh();
        $model->password = static::NEW_PASSWORD;
        $model->save();

        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);
        $this->assertNotSame($before, $this->column($login_user, 'remember_token'), 'remember_token must be rotated');
        $this->assertFalse($this->browseWithOnlyRememberCookie($cookie)['authenticated'], 'remember cookie issued before the change still signs in');
    }

    public function testSsoLoginKeepsPasswordAndRememberToken(): void
    {
        $user = $this->createUser();
        $callback_url = $this->prepareSsoLogin($user);

        // login user is created at the first login, with a password nobody knows
        $this->loginBySso($callback_url);
        $login_user = LoginUser::where('base_user_id', $user->id)->first();
        $this->assertNotNull($login_user, 'login user should be created at the first login');
        $password = $this->column($login_user, 'password');
        $this->assertNotEmpty($password);

        // LDAP user signs in on the login screen, where "remember me" can be checked
        $remember_token = 'remember-token-of-another-browser';
        DB::table('login_users')->where('id', $login_user->id)->update(['remember_token' => $remember_token]);

        $this->loginBySso($callback_url);

        $this->assertSame($password, $this->column($login_user, 'password'), 'password must not be changed by signing in');
        $this->assertSame($remember_token, $this->column($login_user, 'remember_token'), 'remember_token must stay when the user only signed in');
    }

    // ------------------------------------------------------------------ //
    //  2. typed password is not flashed / printed                         //
    // ------------------------------------------------------------------ //

    public function testFailedLoginDoesNotFlashPassword(): void
    {
        $username = 'no-such-user-' . uniqid();

        $this->postLogin($username, static::TYPED_PASSWORD)->assertRedirect()->assertSessionHasErrors('username');

        $this->assertPasswordNotFlashed();
        $this->assertSame($username, $this->flashedInput()['username'] ?? null, 'username should still be kept for the login form');
    }

    public function testLoginSsoErrorDoesNotFlashPassword(): void
    {
        Event::listen(Attempting::class, function () {
            throw new SsoLoginErrorException(SsoLoginErrorType::UNDEFINED_ERROR, 'sso error');
        });
        $username = 'no-such-user-' . uniqid();

        $this->postLogin($username, static::TYPED_PASSWORD)->assertRedirect(admin_url('auth/login'));

        $this->assertPasswordNotFlashed();
        $this->assertSame($username, $this->flashedInput()['username'] ?? null, 'username should still be kept for the login form');
    }

    public function testLoginUnexpectedErrorDoesNotFlashPassword(): void
    {
        Event::listen(Attempting::class, function () {
            throw new \RuntimeException('unexpected error while signing in');
        });
        $username = 'no-such-user-' . uniqid();

        $this->postLogin($username, static::TYPED_PASSWORD)->assertRedirect(admin_url('auth/login'));

        $this->assertPasswordNotFlashed();
        $this->assertSame($username, $this->flashedInput()['username'] ?? null, 'username should still be kept for the login form');
    }

    public function testLoginUserScreenInvalidPasswordIsNotFlashed(): void
    {
        $login_user = $this->prepareLoginUser();
        $this->be($login_user, 'admin');

        // too short for the password rule
        $this->putLoginUser($login_user, 'Ab1!')->assertRedirect()->assertSessionHasErrors('password');

        $this->assertPasswordIs($login_user, static::OLD_PASSWORD);
        $this->assertPasswordNotFlashed();
    }

    public function testLoginUserScreenMissingPasswordIsNotFlashed(): void
    {
        $login_user = $this->prepareLoginUser();
        $this->be($login_user, 'admin');

        $this->putLoginUser($login_user, '', ['password_confirmation' => static::TYPED_PASSWORD])
            ->assertRedirect()->assertSessionHasErrors('create_password_auto');

        $this->assertPasswordNotFlashed();
    }

    public function testLoginUserScreenMailFailureDoesNotFlashPassword(): void
    {
        $this->useFailingMailer();
        $login_user = $this->prepareLoginUser();
        $this->be($login_user, 'admin');

        $this->putLoginUser($login_user, static::TYPED_PASSWORD, ['send_password' => '1'])->assertRedirect();

        $this->assertPasswordIs($login_user, static::OLD_PASSWORD);
        $this->assertPasswordNotFlashed();
    }

    /**
     * First setup screen (admin/initialize), where the first administrator and its password are typed.
     * The screen itself opens only before the system is initialized, so the form handler is called directly.
     */
    public function testInitializeScreenInvalidInputDoesNotFlashPassword(): void
    {
        $request = Request::create(admin_url('initialize'), 'POST', [
            'site_name' => 'Exment',
            'user_code' => 'first-admin',
            'user_name' => 'First Admin',
            'email' => 'not-an-email',
            'password' => static::TYPED_PASSWORD,
            'password_confirmation' => static::TYPED_PASSWORD,
        ]);
        $request->setLaravelSession($this->app->make('session.store'));
        $this->app->instance('request', $request);

        $form = new InitializeForm();
        $response = (new \ReflectionMethod($form, 'postInitializeForm'))->invoke($form, $request, 'initialize', true, true);

        $this->assertInstanceOf(RedirectResponse::class, $response, 'invalid email should be rejected');
        $this->assertPasswordNotFlashed();
        $this->assertSame('first-admin', $this->flashedInput()['user_code'] ?? null, 'other input should still be kept for the form');
    }

    public function testChangePasswordScreenDoesNotPrintOldInput(): void
    {
        $login_user = $this->prepareLoginUser();
        $this->be($login_user, 'admin');

        $html = (string) $this->withSession(['_old_input' => [
            'current_password' => static::TYPED_PASSWORD,
            'password' => static::TYPED_PASSWORD,
            'password_confirmation' => static::TYPED_PASSWORD,
        ]])->get(admin_url('auth/change'))->assertStatus(200)->getContent();

        $inputs = $this->passwordInputs($html);
        $this->assertCount(3, $inputs, 'change password form should have 3 password inputs');
        foreach ($inputs as $input) {
            $this->assertStringNotContainsString(static::TYPED_PASSWORD, $input, 'typed password is printed to the html');
        }
    }

    // ------------------------------------------------------------------ //
    //  3. change password screen without login                            //
    // ------------------------------------------------------------------ //

    public function testChangePasswordScreenSentWithoutLoginIsSentToLoginPage(): void
    {
        $login_user = $this->prepareLoginUser();

        // ex. the session expired while the screen was open
        $this->resetAuth();
        $this->postAs(admin_url('auth/change'), [
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ])->assertRedirect(admin_url('auth/login'));

        $this->assertPasswordIs($login_user, static::OLD_PASSWORD);
        $this->assertPasswordNotFlashed();
    }

    // ------------------------------------------------------------------ //
    //  helpers                                                            //
    // ------------------------------------------------------------------ //

    /**
     * @return TestResponse
     */
    protected function postLogin(string $username, string $password): TestResponse
    {
        return $this->postAs(admin_url('auth/login'), ['username' => $username, 'password' => $password]);
    }
}
