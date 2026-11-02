<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Testing\TestResponse;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginFailure;
use Exceedone\Exment\Model\LoginIpBlock;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Auth2factor\Auth2factorLockService;
use Exceedone\Exment\Services\Auth2factor\Auth2factorRecoveryCodeService;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Services\Auth2factor\Providers\Email;
use Exceedone\Exment\Services\Auth2factor\Providers\Google;
use Exceedone\Exment\Tests\DatabaseTransactions;
use PragmaRX\Google2FA\Google2FA;

/**
 * Stronger one-time password of 2factor authentication (protection against brute-force attacks):
 * - wrong codes of a user (one-time password or recovery code): 5 in 15 minutes -> logged out and locked for the user
 *   (login setting "login_2factor_lock" / "login_2factor_lock_failures" / "login_2factor_lock_minutes"). Per user, not per IP address.
 * - e-mail: only the latest one-time password is valid.
 * - Google authenticator: 30 seconds before and after are accepted (config "exment.login_2factor_google_window"),
 *   and a code accepted once is never accepted again.
 *
 * This test creates its own users and rolls everything back. (no `exment:inittest` data needed)
 */
class Login2factorLockTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const PASSWORD = 'Login2factorLock-2026!';
    protected const IP = '133.242.20.1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        \Notification::fake();

        config(['exment.login_use_2factor' => true]);
        // the old throttle (per IP address, by the cache) is not the subject here, and its cache outlives the test
        config(['exment.throttle' => false]);
        Auth2factorService::providers(Login2FactorProviderType::EMAIL, Email::class);
        Auth2factorService::providers(Login2FactorProviderType::GOOGLE, Google::class);
        System::login_use_2factor(true);
        System::login_2factor_provider(Login2FactorProviderType::GOOGLE);
        System::login_2factor_target_role_group(false);
        System::login_country_restriction(false);
        System::login_ip_block(false);
        System::login_captcha(false);
        System::login_history_notify_new_ip(false);
        System::login_2factor_lock(true);
        System::login_2factor_lock_failures(5);
        System::login_2factor_lock_minutes(15);

        // independent of the records of the local database (ex. a demo). "delete", not "truncate": rolled back after the test
        LoginFailure::query()->delete();
        LoginIpBlock::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Defaults (nothing saved on the login setting page): on, 5 wrong codes in 15 minutes. Google window 1 (30 seconds before and after).
     */
    public function testDefaults(): void
    {
        \DB::table(SystemTableName::SYSTEM)->whereIn('system_name', ['login_2factor_lock', 'login_2factor_lock_failures', 'login_2factor_lock_minutes'])->delete();
        System::clearCache();

        $this->assertTrue(System::login_2factor_lock());
        $this->assertTrue(Auth2factorLockService::isEnabled());
        $this->assertSame(5, Auth2factorLockService::getMaxFailures());
        $this->assertSame(15, Auth2factorLockService::getLockMinutes());
        $this->assertSame(1, Auth2factorService::getGoogleWindow());
    }

    /**
     * A value out of the range (saved by other than the setting page): the default is used.
     */
    public function testSettingOutOfRangeUsesDefault(): void
    {
        foreach ([0, -1, Auth2factorLockService::FAILURES_MAX + 1, 'abc'] as $value) {
            System::login_2factor_lock_failures($value);
            $this->assertSame(5, Auth2factorLockService::getMaxFailures(), "failures {$value}");
        }
        foreach ([0, -1, Auth2factorLockService::LOCK_MINUTES_MAX + 1, 'abc'] as $value) {
            System::login_2factor_lock_minutes($value);
            $this->assertSame(15, Auth2factorLockService::getLockMinutes(), "minutes {$value}");
        }

        System::login_2factor_lock_failures(Auth2factorLockService::FAILURES_MAX);
        System::login_2factor_lock_minutes(Auth2factorLockService::LOCK_MINUTES_MAX);
        $this->assertSame(Auth2factorLockService::FAILURES_MAX, Auth2factorLockService::getMaxFailures());
        $this->assertSame(Auth2factorLockService::LOCK_MINUTES_MAX, Auth2factorLockService::getLockMinutes());
    }

    /**
     * Changed on the login setting page: 3 wrong codes in 1 minute. The 3rd wrong code locks, with "1 minute" to wait,
     * and the lock ends after 1 minute.
     */
    public function testChangedSettingsAreUsed(): void
    {
        System::login_2factor_lock_failures(3);
        System::login_2factor_lock_minutes(1);
        $login_user = $this->createLoginUser();
        $secret = $this->setUpGoogle($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        for ($i = 0; $i < 2; $i++) {
            $this->postVerify(['verify_code' => $this->wrongOtp($secret)])
                ->assertSessionHasErrors(['verify_code' => exmtrans('2factor.message.verify_failed')]);
            $this->assertLoggedIn();
        }
        $this->postVerify(['verify_code' => $this->wrongOtp($secret)])
            ->assertRedirect(admin_url('auth/login'))
            ->assertSessionHasErrors(['username' => exmtrans('2factor.message.locked', 1)]);
        $this->assertNotLoggedIn();
        $this->assertTrue(Auth2factorLockService::isLocked($login_user));

        $this->travelSeconds(60);
        $this->assertFalse(Auth2factorLockService::isLocked($login_user));
        $this->postLogin($login_user)->assertStatus(302);
        $this->postVerify(['verify_code' => $this->otp($secret)])->assertRedirect(admin_url(''));
        $this->assertTrue(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
    }

    /**
     * 4 wrong codes: the error, still on the one-time password page. The 5th: logged out with the minutes to wait.
     * Logging in with the password again: logged out at once. After 15 minutes: the right code passes.
     */
    public function testLockedAfterWrongCodes(): void
    {
        $login_user = $this->createLoginUser();
        $secret = $this->setUpGoogle($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        for ($i = 0; $i < 4; $i++) {
            $this->postVerify(['verify_code' => $this->wrongOtp($secret)])
                ->assertSessionHasErrors(['verify_code' => exmtrans('2factor.message.verify_failed')]);
            $this->assertLoggedIn();
        }

        $this->postVerify(['verify_code' => $this->wrongOtp($secret)])
            ->assertRedirect(admin_url('auth/login'))
            ->assertSessionHasErrors(['username' => exmtrans('2factor.message.locked', 15)]);
        $this->assertNotLoggedIn();
        $this->assertTrue(Auth2factorLockService::isLocked($login_user));
        $this->assertSame(5, LoginFailure::query()->where('login_user_id', $login_user->id)->where('reason', LoginFailure::REASON_2FACTOR)->count());

        // the password again: the one-time password page logs out at once, even with the right code
        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth-2factor'))->assertRedirect(admin_url('auth/login'))->assertSessionHasErrors('username');
        $this->assertNotLoggedIn();

        $this->postLogin($login_user)->assertStatus(302);
        $this->postVerify(['verify_code' => $this->otp($secret)])->assertRedirect(admin_url('auth/login'));
        $this->assertNotLoggedIn();
        $this->assertFalse(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));

        // 14 minutes later: still locked (1 minute left)
        $this->travelSeconds(14 * 60);
        $this->assertSame(1, Auth2factorLockService::getRemainingMinutes($login_user));

        // 15 minutes later: passes
        $this->travelSeconds(60);
        $this->assertFalse(Auth2factorLockService::isLocked($login_user));
        $this->postLogin($login_user)->assertStatus(302);
        $this->postVerify(['verify_code' => $this->otp($secret, 1)])->assertRedirect(admin_url(''));
        $this->assertTrue(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
    }

    /**
     * Locked per user: another user of the same IP address passes.
     */
    public function testLockIsPerUser(): void
    {
        $locked = $this->createLoginUser();
        $lockedSecret = $this->setUpGoogle($locked);
        $other = $this->createLoginUser();
        $otherSecret = $this->setUpGoogle($other);

        $this->postLogin($locked)->assertStatus(302);
        for ($i = 0; $i < 5; $i++) {
            $this->postVerify(['verify_code' => $this->wrongOtp($lockedSecret)]);
        }
        $this->assertNotLoggedIn();

        $this->postLogin($other)->assertStatus(302);
        $this->postVerify(['verify_code' => $this->otp($otherSecret)])->assertRedirect(admin_url(''));
    }

    /**
     * The right code resets the count: 4 wrong + right, then 4 wrong again is not locked.
     * Wrong codes older than 15 minutes are not counted.
     */
    public function testCountIsResetBySuccessAndPeriod(): void
    {
        $login_user = $this->createLoginUser();
        $secret = $this->setUpGoogle($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        for ($i = 0; $i < 4; $i++) {
            $this->postVerify(['verify_code' => $this->wrongOtp($secret)]);
        }
        $this->postVerify(['verify_code' => $this->otp($secret)])->assertRedirect(admin_url(''));
        $this->assertSame(0, LoginFailure::query()->where('login_user_id', $login_user->id)->count());

        $this->postLogin($login_user)->assertStatus(302);
        for ($i = 0; $i < 4; $i++) {
            $this->postVerify(['verify_code' => $this->wrongOtp($secret)])->assertSessionHasErrors('verify_code');
        }
        $this->assertLoggedIn();

        // 15 minutes later: the 4 are out of the period
        $this->travelSeconds(15 * 60 + 1);
        $this->postVerify(['verify_code' => $this->wrongOtp($secret)])->assertSessionHasErrors('verify_code');
        $this->assertLoggedIn();
        $this->assertFalse(Auth2factorLockService::isLocked($login_user));
    }

    /**
     * Wrong recovery codes are counted too.
     */
    public function testWrongRecoveryCodesAreCounted(): void
    {
        $login_user = $this->createLoginUser();
        $secret = $this->setUpGoogle($login_user);
        Auth2factorRecoveryCodeService::generate($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->postVerify(['verify_code' => $this->wrongOtp($secret)]);
        $this->postVerify(['verify_code' => $this->wrongOtp($secret)]);
        for ($i = 0; $i < 2; $i++) {
            $this->postRecovery('ABCDE-FGHJK')->assertSessionHasErrors('recovery_code');
        }
        $this->postRecovery('ABCDE-FGHJK')
            ->assertRedirect(admin_url('auth/login'))
            ->assertSessionHasErrors(['username' => exmtrans('2factor.message.locked', 15)]);
        $this->assertNotLoggedIn();

        // the recovery page of a locked user logs out too
        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth-2factor/recovery'))->assertRedirect(admin_url('auth/login'));
        $this->assertSame(Auth2factorRecoveryCodeService::CODE_COUNT, Auth2factorRecoveryCodeService::getRemainingCount($login_user));
    }

    /**
     * E-mail: only the latest one-time password is valid. Locked: the code sent is invalidated.
     */
    public function testEmailOnlyLatestCodeIsValid(): void
    {
        System::login_2factor_provider(Login2FactorProviderType::EMAIL);
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user)->assertStatus(302);
        $first = $this->emailCode($login_user);
        $this->postLogin($login_user)->assertStatus(302);
        $second = $this->emailCode($login_user);
        $this->assertSame(1, $this->countEmailCodes($login_user), 'the first code is deleted');

        if ($first !== $second) {
            $this->postVerify(['verify_code' => $first])->assertSessionHasErrors('verify_code');
        }
        $this->postVerify(['verify_code' => $second])->assertRedirect(admin_url('auth-2factor/recovery-codes'));

        // locked: the code is deleted
        $this->postLogin($login_user)->assertStatus(302);
        $this->assertSame(1, $this->countEmailCodes($login_user));
        for ($i = 0; $i < 5; $i++) {
            $this->postVerify(['verify_code' => 'abc']);
        }
        $this->assertNotLoggedIn();
        $this->assertSame(0, $this->countEmailCodes($login_user));
    }

    /**
     * E-mail: a locked user logging in with the password again gets no new code (no mail), and is logged out on the page of 2factor.
     * After the lock: a code is sent at login again.
     */
    public function testLockedEmailUserGetsNoNewCode(): void
    {
        System::login_2factor_provider(Login2FactorProviderType::EMAIL);
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user)->assertStatus(302);
        for ($i = 0; $i < 5; $i++) {
            $this->postVerify(['verify_code' => 'abc']);
        }
        $this->assertTrue(Auth2factorLockService::isLocked($login_user));

        \Notification::fake();
        $this->postLogin($login_user)->assertStatus(302);
        $this->assertSame(0, $this->countEmailCodes($login_user));
        \Notification::assertNothingSent();
        $this->get(admin_url('auth-2factor'))->assertRedirect(admin_url('auth/login'))
            ->assertSessionHasErrors(['username' => exmtrans('2factor.message.locked', 15)]);
        $this->assertNotLoggedIn();

        $this->travelSeconds(15 * 60);
        $this->assertFalse(Auth2factorLockService::isLocked($login_user));
        $this->postLogin($login_user)->assertStatus(302);
        $this->assertSame(1, $this->countEmailCodes($login_user));
        \Notification::assertSentTimes(MailSendJob::class, 1);
    }
    /**
     * Google: the code of 30 seconds before or after is accepted, 60 seconds is not. The window can be changed.
     */
    public function testGoogleWindow(): void
    {
        $login_user = $this->createLoginUser();
        $secret = $this->setUpGoogle($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->postVerify(['verify_code' => $this->otp($secret, -2)])->assertSessionHasErrors('verify_code');
        $this->postVerify(['verify_code' => $this->otp($secret, -1)])->assertRedirect(admin_url(''));

        $later = $this->createLoginUser();
        $laterSecret = $this->setUpGoogle($later);
        $this->postLogin($later)->assertStatus(302);
        $this->postVerify(['verify_code' => $this->otp($laterSecret, 2)])->assertSessionHasErrors('verify_code');
        $this->postVerify(['verify_code' => $this->otp($laterSecret, 1)])->assertRedirect(admin_url(''));

        config(['exment.login_2factor_google_window' => 2]);
        $wide = $this->createLoginUser();
        $wideSecret = $this->setUpGoogle($wide);
        $this->postLogin($wide)->assertStatus(302);
        $this->postVerify(['verify_code' => $this->otp($wideSecret, -2)])->assertRedirect(admin_url(''));
    }

    /**
     * Google: a code accepted once is never accepted again, nor an older code.
     */
    public function testGoogleCodeIsNotReused(): void
    {
        $login_user = $this->createLoginUser();
        $secret = $this->setUpGoogle($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->postVerify(['verify_code' => $this->otp($secret)])->assertRedirect(admin_url(''));
        $this->assertSame((new Google2FA())->getTimestamp(), intval(LoginUser::query()->where('id', $login_user->id)->value('auth2fa_last_timestep')));

        // the same code: rejected
        $this->postLogin($login_user)->assertStatus(302);
        $this->postVerify(['verify_code' => $this->otp($secret)])->assertSessionHasErrors('verify_code');
        // an older code: rejected
        $this->postVerify(['verify_code' => $this->otp($secret, -1)])->assertSessionHasErrors('verify_code');
        // the next code: accepted
        $this->postVerify(['verify_code' => $this->otp($secret, 1)])->assertRedirect(admin_url(''));
    }

    /**
     * Google, the first set up (not registered yet): wrong codes on the register page are counted too.
     * Locked: logged out, the register link of the mail is invalidated, and the user is still not registered.
     */
    public function testLockedOnGoogleRegistration(): void
    {
        $login_user = $this->createLoginUser();
        Auth2factorRecoveryCodeService::generate($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth-2factor'))->assertStatus(200)->assertSee(exmtrans('2factor.message.google.message_available'));
        $this->get(admin_url('auth-2factor/google/sendmail'))->assertStatus(200);
        $register = \DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('login_user_id', $login_user->id)
            ->where('verify_type', '2factor_' . Login2FactorProviderType::GOOGLE)
            ->value('verify_code');
        $this->assertIsString($register);
        $this->get(admin_url('auth-2factor/google/register?code=' . $register))->assertStatus(200);
        $key = LoginUser::query()->where('id', $login_user->id)->value('auth2fa_key');
        $this->assertNotNull($key, 'the secret key is created by the register page');
        $secret = decrypt($key);

        for ($i = 0; $i < 4; $i++) {
            $this->postVerify(['verify_code' => $this->wrongOtp($secret), 'code' => $register])
                ->assertSessionHasErrors(['verify_code' => exmtrans('2factor.message.verify_failed')]);
        }
        $this->postVerify(['verify_code' => $this->wrongOtp($secret), 'code' => $register])
            ->assertRedirect(admin_url('auth/login'))
            ->assertSessionHasErrors(['username' => exmtrans('2factor.message.locked', 15)]);
        $this->assertNotLoggedIn();
        $this->assertFalse(boolval(LoginUser::query()->where('id', $login_user->id)->value('auth2fa_available')), 'not registered');
        $this->assertSame(0, \DB::table(SystemTableName::EMAIL_CODE_VERIFY)->where('login_user_id', $login_user->id)->count(), 'the register link is invalidated');

        // the password again: logged out on the page of 2factor, even with the right code
        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth-2factor'))->assertRedirect(admin_url('auth/login'))->assertSessionHasErrors('username');
        $this->postLogin($login_user)->assertStatus(302);
        $this->postVerify(['verify_code' => $this->otp($secret), 'code' => $register])->assertRedirect(admin_url('auth/login'));
        $this->assertNotLoggedIn();
        $this->assertFalse(boolval(LoginUser::query()->where('id', $login_user->id)->value('auth2fa_available')));
    }

    /**
     * 2 wrong codes posted at the same time at the limit (4 wrong codes before): the other request records its wrong code
     * after this request checked the lock. Both are counted, and this request locks the user.
     */
    public function testWrongCodesAtTheSameTime(): void
    {
        $login_user = $this->createLoginUser();
        $secret = $this->setUpGoogle($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        for ($i = 0; $i < 3; $i++) {
            $this->postVerify(['verify_code' => $this->wrongOtp($secret)]);
        }
        $this->assertLoggedIn();

        // the other request records its wrong code just after the lock check of this request (only once)
        $recorded = new \ArrayObject();
        \DB::listen(function ($query) use ($login_user, $recorded) {
            if ($recorded->count() > 0 || stripos($query->sql, 'select') !== 0 || strpos($query->sql, SystemTableName::LOGIN_FAILURE) === false) {
                return;
            }
            $recorded->append(true);
            LoginFailure::query()->insert([
                'ip_address' => '133.242.20.2',
                'ip_key' => '133.242.20.2',
                'login_user_id' => $login_user->id,
                'base_user_id' => $login_user->base_user_id,
                'username' => $login_user->user_code,
                'reason' => LoginFailure::REASON_2FACTOR,
                'login_type' => LoginType::PURE,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });

        $this->postVerify(['verify_code' => $this->wrongOtp($secret)])
            ->assertRedirect(admin_url('auth/login'))
            ->assertSessionHasErrors(['username' => exmtrans('2factor.message.locked', 15)]);
        $this->assertNotLoggedIn();
        $this->assertSame(5, LoginFailure::query()->where('login_user_id', $login_user->id)->count());
        $this->assertTrue(Auth2factorLockService::isLocked($login_user));
    }

    /**
     * Google: the same right code posted twice at the same time (Ex. a stolen code): the other request accepted it
     * after this request read the last accepted time step. Only one of them is accepted.
     */
    public function testSameGoogleCodeAtTheSameTime(): void
    {
        $login_user = $this->createLoginUser();
        $secret = $this->setUpGoogle($login_user);
        $timestep = (new Google2FA())->getTimestamp();

        $this->postLogin($login_user)->assertStatus(302);

        // the other request saves the time step just after this request read it (only once)
        \DB::listen(function ($query) use ($login_user, $timestep) {
            if (stripos($query->sql, 'select') !== 0 || strpos($query->sql, 'auth2fa_last_timestep') === false) {
                return;
            }
            LoginUser::query()->where('id', $login_user->id)->whereNull('auth2fa_last_timestep')->update(['auth2fa_last_timestep' => $timestep]);
        });

        $this->postVerify(['verify_code' => $this->otp($secret)])
            ->assertSessionHasErrors(['verify_code' => exmtrans('2factor.message.verify_failed')]);
        $this->assertFalse(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)), 'not passed by the code used by the other request');
        $this->assertSame($timestep, intval(LoginUser::query()->where('id', $login_user->id)->value('auth2fa_last_timestep')));
        $this->assertSame(1, LoginFailure::query()->where('login_user_id', $login_user->id)->count(), 'counted as a wrong code');
    }

    /**
     * Before "exment:update" (the table of the failures and the column of the last time step are not created yet):
     * the one-time password works as before. Wrong codes never lock, and the right code passes.
     */
    public function testBeforeMigration(): void
    {
        $login_user = $this->createLoginUser();
        $secret = $this->setUpGoogle($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->hideBruteForceTables();
        $this->assertFalse(Auth2factorLockService::isEnabled());

        for ($i = 0; $i < 6; $i++) {
            $this->postVerify(['verify_code' => $this->wrongOtp($secret)])->assertSessionHasErrors('verify_code');
        }
        $this->assertLoggedIn();
        $this->assertFalse(Auth2factorLockService::isLocked($login_user));

        $this->postVerify(['verify_code' => $this->otp($secret)])->assertRedirect(admin_url(''));
        $this->assertTrue(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
        $this->assertNull(LoginUser::query()->where('id', $login_user->id)->value('auth2fa_last_timestep'), 'the column is not used');
        $this->assertSame(0, LoginFailure::query()->where('login_user_id', $login_user->id)->count());
    }

    /**
     * Garbage (not a string) is a wrong code, not a server error.
     */
    public function testGarbageCodeIsWrong(): void
    {
        $login_user = $this->createLoginUser();
        $this->setUpGoogle($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->postVerify(['verify_code' => ['123456']])->assertSessionHasErrors('verify_code');
        $this->postVerify([])->assertSessionHasErrors('verify_code');
        $this->assertSame(2, LoginFailure::query()->where('login_user_id', $login_user->id)->count());
    }

    /**
     * The lock is off: never logged out by wrong codes, and nothing is counted.
     */
    public function testLockOff(): void
    {
        System::login_2factor_lock(false);
        $login_user = $this->createLoginUser();
        $secret = $this->setUpGoogle($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        for ($i = 0; $i < 7; $i++) {
            $this->postVerify(['verify_code' => $this->wrongOtp($secret)])->assertSessionHasErrors('verify_code');
        }
        $this->assertLoggedIn();
        $this->assertSame(0, LoginFailure::query()->where('login_user_id', $login_user->id)->count());
    }

    /**
     * Wrong one-time passwords never block the IP address. (Only the user is locked)
     */
    public function testWrongCodesDoNotBlockTheIp(): void
    {
        System::login_ip_block(true);
        System::login_ip_block_failures(3);
        $login_user = $this->createLoginUser();
        $secret = $this->setUpGoogle($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        for ($i = 0; $i < 5; $i++) {
            $this->postVerify(['verify_code' => $this->wrongOtp($secret)]);
        }
        $this->assertSame(0, LoginIpBlock::query()->count());
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    protected function createLoginUser(): LoginUser
    {
        $code = 'l2fl_' . short_uuid();

        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
        $user->setValue([
            'user_code' => $code,
            'user_name' => $code . '_name',
            'email' => $code . '@example.com',
        ]);
        $user->saved_notify(false);
        $user->save();

        $login_user = new LoginUser();
        $login_user->base_user_id = $user->id;
        $login_user->login_type = LoginType::PURE;
        $login_user->password = self::PASSWORD;
        $login_user->save();

        $login_user = LoginUser::find($login_user->id);
        $this->assertInstanceOf(LoginUser::class, $login_user);
        return $login_user;
    }

    /**
     * Register the Google authenticator of the user (set up completed, recovery codes already issued:
     * so that a successful 2factor login goes to the admin page, not to the page of the new recovery codes).
     *
     * @return string the secret key
     */
    protected function setUpGoogle(LoginUser $login_user): string
    {
        $secret = (new Google2FA())->generateSecretKey();
        LoginUser::query()->where('id', $login_user->id)->update([
            'auth2fa_key' => encrypt($secret),
            'auth2fa_available' => true,
        ]);
        Auth2factorRecoveryCodeService::generate($login_user);
        return $secret;
    }

    /**
     * @param int $step time steps (30 seconds) from now
     */
    protected function otp(string $secret, int $step = 0): string
    {
        $g2fa = new Google2FA();
        return $g2fa->oathTotp($secret, $g2fa->getTimestamp() + $step);
    }

    /**
     * A code that is not valid at any time near now.
     */
    protected function wrongOtp(string $secret): string
    {
        $valid = [];
        for ($step = -3; $step <= 3; $step++) {
            $valid[] = $this->otp($secret, $step);
        }
        for ($i = 0; $i < 100; $i++) {
            $code = sprintf('%06d', random_int(0, 999999));
            if (!in_array($code, $valid, true)) {
                return $code;
            }
        }
        $this->fail('no wrong code');
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postLogin(LoginUser $login_user): TestResponse
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->flushSession();

        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->post(admin_url('auth/login'), [
                'username' => $login_user->user_code,
                'password' => self::PASSWORD,
            ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postVerify(array $data): TestResponse
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->from(admin_url('auth-2factor'))
            ->post(admin_url('auth-2factor/verify'), $data);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postRecovery(string $code): TestResponse
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->from(admin_url('auth-2factor/recovery'))
            ->post(admin_url('auth-2factor/recovery/verify'), ['recovery_code' => $code]);
    }

    protected function emailCode(LoginUser $login_user): string
    {
        $code = \DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('login_user_id', $login_user->id)
            ->where('verify_type', '2factor_' . Login2FactorProviderType::EMAIL)
            ->orderBy('id', 'desc')
            ->value('verify_code');
        $this->assertNotNull($code, 'the one-time password must be issued');
        return (string)$code;
    }

    protected function countEmailCodes(LoginUser $login_user): int
    {
        return \DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('login_user_id', $login_user->id)
            ->where('verify_type', '2factor_' . Login2FactorProviderType::EMAIL)
            ->count();
    }

    protected function travelSeconds(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
    }

    /**
     * Make hasTable() / hasColumn() answer that the table of the failures and the column of the last time step
     * do not exist. (as before "exment:update". Until System::clearRequestSession())
     */
    protected function hideBruteForceTables(): void
    {
        System::clearRequestSession(Define::SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES);
        hasTable(SystemTableName::LOGIN_FAILURE);
        $tables = System::requestSession(Define::SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES);
        $this->assertIsArray($tables);
        System::setRequestSession(
            Define::SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES,
            array_values(array_diff($tables, [SystemTableName::LOGIN_FAILURE, SystemTableName::LOGIN_IP_BLOCK]))
        );

        $columnKey = sprintf(Define::SYSTEM_KEY_SESSION_DATABASE_COLUMN_NAMES_IN_TABLE, SystemTableName::LOGIN_USER);
        System::clearRequestSession($columnKey);
        hasColumn(SystemTableName::LOGIN_USER, 'id');
        $columns = System::requestSession($columnKey);
        $this->assertIsArray($columns);
        System::setRequestSession($columnKey, array_values(array_diff($columns, ['auth2fa_last_timestep'])));

        $this->assertFalse(hasTable(SystemTableName::LOGIN_FAILURE));
        $this->assertFalse(hasColumn(SystemTableName::LOGIN_USER, 'auth2fa_last_timestep'));
    }

    protected function assertLoggedIn(): void
    {
        $this->assertTrue(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check(), 'must be logged in');
    }

    protected function assertNotLoggedIn(): void
    {
        app('auth')->forgetGuards();
        $this->assertFalse(\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check(), 'must not be logged in');
    }
}
