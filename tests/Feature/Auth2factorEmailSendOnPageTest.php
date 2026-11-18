<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * 2factor by email: the 2factor page sends the code when the user has no valid one.
 * The code is sent at login, but a session can come to the page without it:
 * logged in before 2factor was turned on, changed the password at login, or the code has expired.
 */
class Auth2factorEmailSendOnPageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Tf2Send_passw0rd';

    protected function setUp(): void
    {
        parent::setUp();

        config(['exment.login_use_2factor' => true]);
        System::login_use_2factor(true);
        System::login_2factor_provider(Login2FactorProviderType::EMAIL);
        System::clearCache();

        Notification::fake();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    public function testASessionLoggedInBefore2factorWasTurnedOnGetsTheCode(): void
    {
        $login_user = $this->createLoginUser();
        $this->be($login_user);

        $this->get(admin_url(''))->assertRedirect(admin_url('auth-2factor'));
        $this->get(admin_url('auth-2factor'))
            ->assertOk()
            ->assertSee(exmtrans('2factor.message.email_send_verify', config('exment.login_2factor_valid_period', 10)), false);

        $this->assertSame(1, $this->countValidCodes($login_user));
        Notification::assertSentTimes(MailSendJob::class, 1);

        // the sent code passes 2factor
        $this->post(admin_url('auth-2factor/verify'), ['verify_code' => $this->getValidCode($login_user)])
            ->assertRedirect(admin_url(''))
            ->assertSessionHas(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR, true);
        $this->get(admin_url(''))->assertOk();
    }

    public function testReloadingThePageDoesNotSendTheCodeAgain(): void
    {
        $login_user = $this->createLoginUser();
        $this->be($login_user);

        $this->get(admin_url('auth-2factor'))->assertOk();
        $this->get(admin_url('auth-2factor'))->assertOk();

        $this->assertSame(1, $this->countValidCodes($login_user));
        Notification::assertSentTimes(MailSendJob::class, 1);
    }

    public function testAnExpiredCodeIsSentAgain(): void
    {
        $login_user = $this->createLoginUser();
        $this->be($login_user);

        $this->get(admin_url('auth-2factor'))->assertOk();
        DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('login_user_id', $login_user->id)
            ->update(['valid_period_datetime' => Carbon::now()->subMinutes(1)]);

        $this->get(admin_url('auth-2factor'))->assertOk();

        $this->assertSame(1, $this->countValidCodes($login_user));
        Notification::assertSentTimes(MailSendJob::class, 2);
    }

    public function testTheLoginSendsTheCodeOnlyOnce(): void
    {
        $login_user = $this->createLoginUser();

        $this->post(admin_url('auth/login'), [
            'username' => $login_user->user_code,
            'password' => self::PASSWORD,
        ])->assertRedirect();
        $this->get(admin_url(''))->assertRedirect(admin_url('auth-2factor'));
        $this->get(admin_url('auth-2factor'))->assertOk();

        $this->assertSame(1, $this->countValidCodes($login_user));
        Notification::assertSentTimes(MailSendJob::class, 1);
    }

    public function testTheUserWhoChangedThePasswordAtLoginGetsTheCode(): void
    {
        $login_user = $this->createLoginUser();
        $login_user->password_reset_flg = true;
        $login_user->save();

        $this->post(admin_url('auth/login'), [
            'username' => $login_user->user_code,
            'password' => self::PASSWORD,
        ])->assertRedirect(admin_url('auth/change'));
        $this->post(admin_url('auth/change'), [
            'current_password' => self::PASSWORD,
            'password' => 'Tf2Send_changed1',
            'password_confirmation' => 'Tf2Send_changed1',
        ])->assertRedirect(admin_url('auth/login'));
        $this->assertSame(0, $this->countValidCodes($login_user));

        $this->get(admin_url(''))->assertRedirect(admin_url('auth-2factor'));
        $this->get(admin_url('auth-2factor'))->assertOk();

        $this->assertSame(1, $this->countValidCodes($login_user));
        Notification::assertSentTimes(MailSendJob::class, 1);
    }

    public function testASessionThatPassed2factorDoesNotGetTheCode(): void
    {
        $login_user = $this->createLoginUser();
        $this->be($login_user);

        $this->withSession([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true])
            ->get(admin_url('auth-2factor'))
            ->assertOk();

        $this->assertSame(0, $this->countValidCodes($login_user));
        Notification::assertNothingSent();
    }

    public function testNoCodeIsSentWhen2factorIsOff(): void
    {
        config(['exment.login_use_2factor' => false]);
        $login_user = $this->createLoginUser();
        $this->be($login_user);

        $this->get(admin_url('auth-2factor'))->assertOk();

        $this->assertSame(0, $this->countValidCodes($login_user));
        Notification::assertNothingSent();
    }

    public function testAnAjaxRequestDoesNotSendTheCode(): void
    {
        $login_user = $this->createLoginUser();
        $this->be($login_user);

        $this->get(admin_url('auth-2factor'), ['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(0, $this->countValidCodes($login_user));
        Notification::assertNothingSent();
    }

    /**
     * Create a user (custom value) and its login user, with the email to send the code.
     */
    private function createLoginUser(): LoginUser
    {
        $code = 'tf2send_' . short_uuid();

        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
        $user->setValue([
            'user_code' => $code,
            'user_name' => $code,
            'email' => $code . '@example.com',
        ]);
        $user->saved_notify(false);
        $user->save();

        $login_user = new LoginUser();
        $login_user->base_user_id = $user->id;
        $login_user->password = self::PASSWORD;
        $login_user->save();

        return LoginUser::query()->whereKey($login_user->id)->firstOrFail();
    }

    private function countValidCodes(LoginUser $login_user): int
    {
        return DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('login_user_id', $login_user->id)
            ->where('verify_type', '2factor_email')
            ->where('valid_period_datetime', '>=', Carbon::now())
            ->count();
    }

    private function getValidCode(LoginUser $login_user): string
    {
        return (string) DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('login_user_id', $login_user->id)
            ->where('verify_type', '2factor_email')
            ->where('valid_period_datetime', '>=', Carbon::now())
            ->value('verify_code');
    }
}
