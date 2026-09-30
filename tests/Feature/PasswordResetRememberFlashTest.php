<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;

/**
 * "Forgot password" flow (auth/reset/{token}).
 *
 * - A "remember me" cookie issued before the reset does not sign in after the reset.
 *   LoginUser rotates remember_token when the password is saved.
 * - Submitting the form with a token that is not valid neither flashes the typed passwords to the
 *   session, nor prints them to the html of the form.
 *
 * password_reset_flg and the throttle of the reset mail: PasswordResetSecurityTest.
 *
 * Every test asserts the expected (safe) behaviour. Tests named "...Control..." only prove that
 * the scenario itself is set up correctly.
 *
 * Uses login user id 1 (temporary email and password). Everything is rolled back, no mail is sent.
 */
class PasswordResetRememberFlashTest extends FeatureTestBase
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
    //  remember me cookie                                                 //
    // ------------------------------------------------------------------ //

    public function testControlRememberCookieSignsInBeforeReset(): void
    {
        $login_user = $this->prepareLoginUser();
        $cookie = $this->loginWithRemember($login_user);

        $state = $this->browseWithOnlyRememberCookie($cookie);

        $this->assertTrue($state['authenticated'], 'remember cookie should sign in while the password is unchanged');
        $this->assertTrue($state['via_remember'], 'sign in should come from the remember cookie, not from a session');
    }

    public function testControlGarbageRememberCookieIsRejected(): void
    {
        $login_user = $this->prepareLoginUser();
        $this->loginWithRemember($login_user);

        $state = $this->browseWithOnlyRememberCookie($login_user->id . '|' . str_repeat('x', 60) . '|x');

        $this->assertFalse($state['authenticated'], 'a cookie with a wrong token must not sign in');
    }

    public function testResetByLinkRotatesRememberToken(): void
    {
        $login_user = $this->prepareLoginUser();
        $this->loginWithRemember($login_user);
        $before = $this->column($login_user, 'remember_token');
        $this->assertNotEmpty($before, 'login with remember should have stored a remember_token');

        $this->resetAuth();
        $this->postReset($this->createToken($login_user), static::NEW_PASSWORD)->assertRedirect(admin_url('auth/login'));

        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);
        $this->assertNotSame($before, $this->column($login_user, 'remember_token'), 'remember_token must be rotated when the password is reset');
    }

    public function testRememberCookieIssuedBeforeResetIsRejectedAfterReset(): void
    {
        $login_user = $this->prepareLoginUser();

        // someone who knows the old password signs in with "remember me" and keeps the cookie
        $cookie = $this->loginWithRemember($login_user);

        // the owner resets the password by the emailed link, from another browser
        $this->resetAuth();
        $this->postReset($this->createToken($login_user), static::NEW_PASSWORD)->assertRedirect(admin_url('auth/login'));
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        // the kept cookie is used again, with no session
        $state = $this->browseWithOnlyRememberCookie($cookie);

        $this->assertFalse(
            $state['authenticated'],
            'remember cookie issued before the reset still signs in (landed on: ' . $state['location'] . ', status ' . $state['status'] . ')'
        );
    }

    // ------------------------------------------------------------------ //
    //  typed password flashed to session / printed to html                //
    // ------------------------------------------------------------------ //

    public function testWrongTokenDoesNotFlashPasswordsToSession(): void
    {
        $token = 'token-that-does-not-exist';

        $this->postResetRaw($token, $this->typedInput($token))->assertRedirect();

        $this->assertPasswordNotFlashed();
    }

    public function testWrongTokenDoesNotStorePasswordInSessionStore(): void
    {
        $token = 'token-that-does-not-exist';

        $response = $this->postResetRaw($token, $this->typedInput($token))->assertRedirect();

        // what the session driver stored: file, table of database and so on
        $stored = $this->storedSession($this->cookieOf($response, (string) config('session.cookie')));
        $this->assertNotNull($stored, 'session should be stored');
        $this->assertStringNotContainsString(static::TYPED_PASSWORD, serialize($stored), 'plain password is stored in the session');
    }

    /**
     * Ordinary flow (one tab): the page the user is sent back to carries the same token,
     * so it is rejected as well and the reset form is never rendered.
     */
    public function testControlWrongTokenInOrdinaryFlowEndsAtLoginPage(): void
    {
        $token = 'token-that-does-not-exist';

        $this->postResetRaw($token, $this->typedInput($token))->assertRedirect(admin_url('auth/reset/' . $token));
        $this->get(admin_url('auth/reset/' . $token))->assertRedirect(admin_url('auth/login'));
        $html = (string) $this->get(admin_url('auth/login'))->assertStatus(200)->getContent();

        $this->assertStringNotContainsString(static::TYPED_PASSWORD, $html);
    }

    /**
     * The form is rendered while the flashed input is still there only when the page the user is
     * sent back to has a usable token (submitted token differs from the one in that page's url).
     */
    public function testResetFormDoesNotPrintFlashedPassword(): void
    {
        $login_user = $this->prepareLoginUser();
        $valid = $this->createToken($login_user);

        $this->postResetRaw($valid, $this->typedInput('token-that-does-not-exist'))->assertRedirect(admin_url('auth/reset/' . $valid));
        $html = (string) $this->get(admin_url('auth/reset/' . $valid))->assertStatus(200)->getContent();

        $inputs = $this->passwordInputs($html);
        $this->assertCount(2, $inputs, 'reset form should have 2 password inputs');
        foreach ($inputs as $input) {
            $this->assertStringNotContainsString(static::TYPED_PASSWORD, $input, 'typed password is printed to the html');
        }
    }

    // ------------------------------------------------------------------ //
    //  helpers                                                            //
    // ------------------------------------------------------------------ //

    /**
     * @return array<string, string>
     */
    protected function typedInput(string $token): array
    {
        return ['token' => $token, 'password' => static::TYPED_PASSWORD, 'password_confirmation' => static::TYPED_PASSWORD];
    }
}
