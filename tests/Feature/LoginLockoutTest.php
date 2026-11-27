<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * The lockout after too many wrong tries (exment.max_attempts) lasts exment.decay_minutes
 * minutes, on the login page and on the second factor (e-mail code, Google Authenticator).
 * RateLimiter::hit() takes seconds: the minutes were passed as seconds, so a 60 minute lock
 * ended after 60 seconds.
 * The lockout of the second factor is shown on the form, as a wrong code is, not as the
 * error page (the user is signed in there, so Exment::error() made it the error page).
 */
class LoginLockoutTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const USERNAME = 'login_lockout_test';
    private const MAX_ATTEMPTS = 3;
    // not the default (60): the lock length comes from the setting
    private const DECAY_MINUTES = 7;

    /** @var LoginUser */
    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();
        Mail::fake();
        config(['exment.throttle' => true, 'exment.max_attempts' => self::MAX_ATTEMPTS, 'exment.decay_minutes' => self::DECAY_MINUTES]);

        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertInstanceOf(LoginUser::class, $user);
        $this->user = $user;
        $this->clearLimits();
    }

    protected function tearDown(): void
    {
        $this->clearLimits();
        Carbon::setTestNow();
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    private function clearLimits(): void
    {
        app(RateLimiter::class)->clear($this->loginKey());
        app(RateLimiter::class)->clear('2factor|' . $this->user->id);
    }

    private function loginKey(): string
    {
        return Str::lower(self::USERNAME) . '|127.0.0.1';
    }

    private function lockMessage(): string
    {
        return exmtrans('login.throttle', self::DECAY_MINUTES);
    }

    // Login page ----------------------------------------------------

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function postLogin(): \Illuminate\Testing\TestResponse
    {
        $this->withoutMiddleware(\Exceedone\Exment\Middleware\VerifyCsrfToken::class);
        return $this->post(admin_url('auth/login'), [
            'username' => self::USERNAME,
            'password' => 'wrong-password-' . Str::random(8),
        ]);
    }

    private function loginError(): ?string
    {
        $errors = session('errors');
        return $errors ? $errors->first('username') : null;
    }

    public function testLoginIsLockedForTheMinutesOfTheSetting(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 27, 10, 0, 0));
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $this->postLogin()->assertStatus(302);
            $this->assertSame(exmtrans('error.login_failed'), $this->loginError(), 'a wrong password before the limit');
        }

        // the lock is decay_minutes * 60 seconds
        $this->assertSame(self::DECAY_MINUTES * 60, app(RateLimiter::class)->availableIn($this->loginKey()));

        $this->postLogin()->assertStatus(302);
        $this->assertSame($this->lockMessage(), $this->loginError(), 'locked after max_attempts');

        // still locked after 2 minutes, and one second before the end
        Carbon::setTestNow(Carbon::create(2026, 11, 27, 10, 2, 0));
        $this->postLogin()->assertStatus(302);
        $this->assertSame(exmtrans('login.throttle', self::DECAY_MINUTES - 2), $this->loginError());
        Carbon::setTestNow(Carbon::create(2026, 11, 27, 10, self::DECAY_MINUTES - 1, 59));
        $this->postLogin()->assertStatus(302);
        $this->assertSame(exmtrans('login.throttle', 1), $this->loginError());

        // open again when the time is over: a wrong password is answered as before the lock
        Carbon::setTestNow(Carbon::create(2026, 11, 27, 10, self::DECAY_MINUTES, 1));
        $this->postLogin()->assertStatus(302);
        $this->assertSame(exmtrans('error.login_failed'), $this->loginError());
        $this->assertSame(1, app(RateLimiter::class)->attempts($this->loginKey()));
    }

    public function testLoginIsNotLockedBeforeMaxAttempts(): void
    {
        for ($i = 0; $i < self::MAX_ATTEMPTS - 1; $i++) {
            $this->postLogin()->assertStatus(302);
        }
        $this->postLogin()->assertStatus(302);
        $this->assertSame(exmtrans('error.login_failed'), $this->loginError(), 'the last allowed try is checked as usual');
    }

    public function testLoginIsNotLockedWhenThrottleIsOff(): void
    {
        config(['exment.throttle' => false]);
        for ($i = 0; $i < self::MAX_ATTEMPTS + 2; $i++) {
            $this->postLogin()->assertStatus(302);
            $this->assertSame(exmtrans('error.login_failed'), $this->loginError());
        }
    }

    // Second factor -------------------------------------------------

    private function signInForTheSecondFactor(): void
    {
        config(['exment.login_use_2factor' => true]);
        System::login_use_2factor(true);
        System::clearCache();
        $this->be($this->user);
    }

    private function useGoogleProvider(): void
    {
        System::login_2factor_provider(Login2FactorProviderType::GOOGLE);
        System::clearCache();
    }

    /**
     * pragmarx/google2fa is a library the site adds when it uses Google Authenticator.
     */
    private function giveUserASecretKey(): string
    {
        if (!class_exists(Google2FA::class)) {
            $this->markTestSkipped('pragmarx/google2fa is not installed.');
        }
        $key = (new Google2FA())->generateSecretKey();
        $this->user->auth2fa_key = encrypt($key);
        $this->user->auth2fa_available = true;
        $this->user->save();
        return $key;
    }

    private function storeEmailCode(string $code): void
    {
        \DB::table(SystemTableName::EMAIL_CODE_VERIFY)->insert([
            'login_user_id' => $this->user->id,
            'email' => $this->user->email,
            'verify_code' => $code,
            'verify_type' => '2factor_email',
            'valid_period_datetime' => Carbon::now()->addMinutes(60)->format('Y/m/d H:i'),
        ]);
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function postCode(string $code): \Illuminate\Testing\TestResponse
    {
        System::clearRequestSession();
        return $this->from(admin_url('auth-2factor'))->post(admin_url('auth-2factor/verify'), ['verify_code' => $code]);
    }

    private function codeError(): ?string
    {
        $errors = session('errors');
        return $errors ? $errors->first('verify_code') : null;
    }

    /**
     * The lock answer: back to the form with the message on the code, not the error page.
     *
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertLockShownOnTheForm(\Illuminate\Testing\TestResponse $response, string $message): void
    {
        $response->assertStatus(302);
        $response->assertRedirect(admin_url('auth-2factor'));
        $this->assertSame($message, $this->codeError());
        $this->assertFalse(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
    }

    public function testEmailCodeIsLockedForTheMinutesOfTheSettingAndShownOnTheForm(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 27, 10, 0, 0));
        $this->signInForTheSecondFactor();
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $this->postCode('000000')->assertStatus(302);
            $this->assertSame(exmtrans('2factor.message.verify_failed'), $this->codeError());
        }
        $this->assertSame(self::DECAY_MINUTES * 60, app(RateLimiter::class)->availableIn('2factor|' . $this->user->id));

        // locked: even the right code is not taken
        $this->storeEmailCode('123456');
        $this->assertLockShownOnTheForm($this->postCode('123456'), $this->lockMessage());

        Carbon::setTestNow(Carbon::create(2026, 11, 27, 10, 2, 0));
        $this->assertLockShownOnTheForm($this->postCode('123456'), exmtrans('login.throttle', self::DECAY_MINUTES - 2));

        // open again when the time is over
        Carbon::setTestNow(Carbon::create(2026, 11, 27, 10, self::DECAY_MINUTES, 1));
        $this->postCode('123456')->assertRedirect(admin_url(''));
        $this->assertTrue(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
    }

    public function testGoogleCodeIsLockedForTheMinutesOfTheSettingAndShownOnTheForm(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 27, 10, 0, 0));
        $this->signInForTheSecondFactor();
        $this->useGoogleProvider();
        $key = $this->giveUserASecretKey();
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $this->postCode('not-a-code')->assertStatus(302);
            $this->assertSame(exmtrans('2factor.message.verify_failed'), $this->codeError());
        }
        $this->assertSame(self::DECAY_MINUTES * 60, app(RateLimiter::class)->availableIn('2factor|' . $this->user->id));

        $this->assertLockShownOnTheForm($this->postCode('not-a-code'), $this->lockMessage());

        // open again when the time is over: the current code is taken
        Carbon::setTestNow(Carbon::create(2026, 11, 27, 10, self::DECAY_MINUTES, 1));
        $this->postCode((new Google2FA())->getCurrentOtp($key))->assertRedirect(admin_url(''));
        $this->assertTrue(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
    }

    /**
     * The lock is checked before the code (and before the library is used): the answer of
     * the Google provider without pragmarx/google2fa.
     */
    public function testGoogleCodeLockIsShownOnTheForm(): void
    {
        $this->signInForTheSecondFactor();
        $this->useGoogleProvider();
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            app(RateLimiter::class)->hit('2factor|' . $this->user->id, self::DECAY_MINUTES * 60);
        }

        $this->assertLockShownOnTheForm($this->postCode('000000'), $this->lockMessage());
    }

    public function testGoogleRegisterLockIsShownOnTheForm(): void
    {
        $this->signInForTheSecondFactor();
        $this->useGoogleProvider();
        $url = admin_url('auth-2factor/google/register?code=not-a-stored-code');
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $this->from(admin_url('auth-2factor'))->get($url)->assertStatus(302);
        }
        $this->assertSame(self::DECAY_MINUTES * 60, app(RateLimiter::class)->availableIn('2factor|' . $this->user->id));

        $this->assertLockShownOnTheForm($this->from(admin_url('auth-2factor'))->get($url), $this->lockMessage());
    }

    public function testSecondFactorIsNotLockedWhenThrottleIsOff(): void
    {
        config(['exment.throttle' => false]);
        $this->signInForTheSecondFactor();
        for ($i = 0; $i < self::MAX_ATTEMPTS + 1; $i++) {
            $this->postCode('000000')->assertStatus(302);
            $this->assertSame(exmtrans('2factor.message.verify_failed'), $this->codeError());
        }
        $this->storeEmailCode('123456');
        $this->postCode('123456')->assertRedirect(admin_url(''));
    }
}
