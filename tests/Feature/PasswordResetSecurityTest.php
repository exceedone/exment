<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

/**
 * "Forgot password" flow (auth/forget, auth/reset/{token}).
 *
 * - Resetting via the emailed link clears password_reset_flg. Otherwise the user is sent to the
 *   change-password screen right after choosing a password (and the history rule rejects the one
 *   just chosen).
 * - Reset mails are throttled (auth.passwords.exment_admins.throttle).
 *
 * The "remember me" cookie and the typed password of this flow: PasswordResetRememberFlashTest.
 *
 * Uses login user id 1 (temporary email and password). Everything is rolled back, no mail is sent.
 */
class PasswordResetSecurityTest extends FeatureTestBase
{
    use DatabaseTransactions;
    use PasswordTestTrait;

    protected const OLD_PASSWORD = 'Old-Leaked-2026!';
    protected const NEW_PASSWORD = 'Secure-Reset-2026!';
    protected const TYPED_PASSWORD = 'Typed-Secret-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();
    }

    // ------------------------------------------------------------------ //
    //  password_reset_flg                                                 //
    // ------------------------------------------------------------------ //

    public function testResetByLinkClearsPasswordResetFlag(): void
    {
        $login_user = $this->prepareLoginUser();
        DB::table('login_users')->where('id', $login_user->id)->update(['password_reset_flg' => 1]);

        $this->postReset($this->createToken($login_user), static::NEW_PASSWORD)->assertRedirect(admin_url('auth/login'));

        $this->assertSame(0, (int) $this->column($login_user, 'password_reset_flg'), 'self-service reset must clear password_reset_flg');

        // and the next login goes to the app, not to the forced change-password screen
        $this->resetAuth();
        $response = $this->postAs(admin_url('auth/login'), [
            'username' => $login_user->base_user->getValue('user_code'),
            'password' => static::NEW_PASSWORD,
        ]);
        $this->assertAuthenticatedAs($login_user, 'admin');
        $this->assertNotSame(admin_url('auth/change'), $response->headers->get('Location'), 'user must not be forced to change the password again');
    }

    public function testResetByLinkKeepsFlagOffWhenItWasOff(): void
    {
        // prepareLoginUser() turns the flag off
        $login_user = $this->prepareLoginUser();

        $this->postReset($this->createToken($login_user), static::NEW_PASSWORD)->assertRedirect();

        $this->assertSame(0, (int) $this->column($login_user, 'password_reset_flg'));
    }

    // ------------------------------------------------------------------ //
    //  throttle                                                           //
    // ------------------------------------------------------------------ //

    public function testResetMailThrottleIsConfigured(): void
    {
        $throttle = (int) config('auth.passwords.exment_admins.throttle');
        $this->assertGreaterThanOrEqual(1, $throttle, 'auth.passwords.exment_admins.throttle must be set (0 = unlimited reset mails)');
    }

    public function testSecondResetMailIsThrottledByBroker(): void
    {
        $login_user = $this->prepareLoginUser();
        $broker = Password::broker('exment_admins');
        $credentials = ['login_type' => 'pure', 'target_column' => 'email', 'username' => $login_user->email];

        $this->assertSame(Password::RESET_LINK_SENT, $broker->sendResetLink($credentials));
        $this->assertSame(Password::RESET_THROTTLED, $broker->sendResetLink($credentials), 'a second reset mail right after the first must be throttled');
    }

    public function testSecondForgotPasswordRequestIsRejectedOnScreen(): void
    {
        $login_user = $this->prepareLoginUser();
        $this->setThrottledMessageOfApp('throttled message of the app');

        $this->postForget($login_user->email)
            ->assertRedirect()
            ->assertSessionHas('status', trans(Password::RESET_LINK_SENT))
            ->assertSessionHasNoErrors();

        $this->postForget($login_user->email)
            ->assertRedirect()
            ->assertSessionHasErrors(['email' => 'throttled message of the app']);

        Notification::assertCount(1);
    }

    public function testSecondForgotPasswordRequestShowsMessageOfExmentWhenAppDoesNotHaveIt(): void
    {
        $login_user = $this->prepareLoginUser();
        // lang files of the app were published before the message was added
        $this->setThrottledMessageOfApp(null);

        $this->postForget($login_user->email)->assertRedirect()->assertSessionHasNoErrors();

        $message = trans('exment::exment.login.password_reset_throttled');
        $this->assertNotSame('exment::exment.login.password_reset_throttled', $message);
        $this->postForget($login_user->email)
            ->assertRedirect()
            ->assertSessionHasErrors(['email' => $message]);

        Notification::assertCount(1);
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

    /**
     * Set the message of "passwords.throttled" the app has (lang/{locale}/passwords.php), for the locale of the screens.
     * Not to depend on the lang files of the environment.
     *
     * @param string|null $message null if the app does not have the message
     */
    protected function setThrottledMessageOfApp(?string $message): void
    {
        // Exment sets the locale while it handles a request
        $this->get(admin_url('auth/forget'))->assertStatus(200);
        $locale = app()->getLocale();

        $lines = trans('passwords', [], $locale);
        $lines = is_array($lines) ? $lines : [];
        unset($lines['throttled']);
        if (!is_null($message)) {
            $lines['throttled'] = $message;
        }
        app('translator')->setLoaded(['*' => ['passwords' => [$locale => $lines]]]);

        $this->assertSame(!is_null($message), \Lang::has(Password::RESET_THROTTLED, $locale, false));
    }

    protected function postForget(string $email): TestResponse
    {
        return $this->postAs(admin_url('auth/forget'), ['email' => $email]);
    }
}
