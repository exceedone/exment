<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;

/**
 * "Forgot password" flow hardening (auth/forget, auth/reset/{token}).
 *
 * 1. Resetting via the emailed link clears password_reset_flg. Otherwise the user is sent to the
 *    change-password screen right after choosing a password (and the history rule rejects the one
 *    just chosen).
 * 4. Reset mails are throttled (auth.passwords.exment_admins.throttle).
 *
 * Uses login user id 1 (temporary email if it has none). Everything is rolled back, no mail is sent.
 */
class PasswordResetSecurityTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const NEW_PASSWORD = 'Secure-Reset-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();
    }

    // ------------------------------------------------------------------ //
    //  1. password_reset_flg                                              //
    // ------------------------------------------------------------------ //

    public function testResetByLinkClearsPasswordResetFlag(): void
    {
        $login_user = $this->loginUserWithEmail();
        $this->setLoginUser($login_user, ['password_reset_flg' => 1]);

        $this->postReset($this->createToken($login_user), static::NEW_PASSWORD)->assertRedirect(admin_url('auth/login'));

        $this->assertSame(0, (int) $this->column($login_user, 'password_reset_flg'), 'self-service reset must clear password_reset_flg');

        // and the next login goes to the app, not to the forced change-password screen
        $this->resetAuth();
        $response = $this->postLogin($login_user, static::NEW_PASSWORD);
        $this->assertAuthenticatedAs($login_user, 'admin');
        $this->assertNotSame(admin_url('auth/change'), $response->headers->get('Location'), 'user must not be forced to change the password again');
    }

    public function testResetByLinkKeepsFlagOffWhenItWasOff(): void
    {
        $login_user = $this->loginUserWithEmail();
        $this->setLoginUser($login_user, ['password_reset_flg' => 0]);

        $this->postReset($this->createToken($login_user), static::NEW_PASSWORD)->assertRedirect();

        $this->assertSame(0, (int) $this->column($login_user, 'password_reset_flg'));
    }

    // ------------------------------------------------------------------ //
    //  4. throttle                                                        //
    // ------------------------------------------------------------------ //

    public function testResetMailThrottleIsConfigured(): void
    {
        $throttle = (int) config('auth.passwords.exment_admins.throttle');
        $this->assertGreaterThanOrEqual(1, $throttle, 'auth.passwords.exment_admins.throttle must be set (0 = unlimited reset mails)');
    }

    public function testSecondResetMailIsThrottledByBroker(): void
    {
        $login_user = $this->loginUserWithEmail();
        $broker = \Password::broker('exment_admins');
        $credentials = ['login_type' => 'pure', 'target_column' => 'email', 'username' => $login_user->email];

        $this->assertSame(\Password::RESET_LINK_SENT, $broker->sendResetLink($credentials));
        $this->assertSame(\Password::RESET_THROTTLED, $broker->sendResetLink($credentials), 'a second reset mail right after the first must be throttled');
    }

    public function testSecondForgotPasswordRequestIsRejectedOnScreen(): void
    {
        $login_user = $this->loginUserWithEmail();

        $this->postForget($login_user->email)
            ->assertRedirect()
            ->assertSessionHas('status', trans(\Password::RESET_LINK_SENT))
            ->assertSessionHasNoErrors();

        $this->postForget($login_user->email)
            ->assertRedirect()
            ->assertSessionHasErrors(['email' => trans(\Password::RESET_THROTTLED)]);

        Notification::assertCount(1);
        $this->assertNotSame(\Password::RESET_THROTTLED, trans(\Password::RESET_THROTTLED), 'throttled message must be translated');
    }

    public function testThrottledMessageHasExmentFallbackTranslation(): void
    {
        // used when the app's published passwords.php predates the 'throttled' key
        foreach (['ja', 'en'] as $locale) {
            $message = trans('exment::exment.login.password_reset_throttled', [], $locale);
            $this->assertNotSame('exment::exment.login.password_reset_throttled', $message, "missing {$locale} fallback");
            $this->assertNotEmpty($message);
        }
    }

    // ------------------------------------------------------------------ //
    //  helpers                                                            //
    // ------------------------------------------------------------------ //

    protected function loginUserWithEmail(): LoginUser
    {
        $login_user = LoginUser::find(1);
        if (empty($login_user->base_user->getValue('email'))) {
            $login_user->base_user->setValue(['email' => 'reset-security@example.invalid'])->saved_notify(false)->save();
            $login_user = LoginUser::find(1);
        }
        $this->assertNotEmpty($login_user->email);
        \DB::table('password_reset_tokens')->where('email', $login_user->email)->delete();

        return $login_user;
    }

    protected function setLoginUser(LoginUser $login_user, array $values): void
    {
        \DB::table('login_users')->where('id', $login_user->id)->update($values);
    }

    protected function column(LoginUser $login_user, string $column)
    {
        return \DB::table('login_users')->where('id', $login_user->id)->value($column);
    }

    protected function createToken(LoginUser $login_user): string
    {
        return \Password::broker('exment_admins')->createToken($login_user);
    }

    protected function postReset(string $token, string $password)
    {
        return $this->postResetRaw($token, ['token' => $token, 'password' => $password, 'password_confirmation' => $password]);
    }

    protected function postResetRaw(string $token, array $data)
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->from(admin_url('auth/reset/' . $token))
            ->post(admin_url('auth/reset/' . $token), $data);
    }

    protected function postForget(string $email)
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->from(admin_url('auth/forget'))
            ->post(admin_url('auth/forget'), ['email' => $email]);
    }

    protected function postLogin(LoginUser $login_user, string $password)
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post(admin_url('auth/login'), [
                'username' => $login_user->base_user->getValue('user_code'),
                'password' => $password,
            ]);
    }

    protected function resetAuth(): void
    {
        app('auth')->forgetGuards();
        $this->flushSession();
    }

}
