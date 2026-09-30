<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * The password is reset with "send the password by mail", and the mail cannot be sent.
 * (exment:resetpassword --send=1, import of login users. LoginService::resetPassword)
 *
 * The password must be left as it was. Otherwise the user cannot sign in any more:
 * the previous password is gone, and nobody was told the new one.
 *
 * Also the mail of the link to reset the password (auth/forget) cannot be sent.
 * The user must be able to try again at once.
 *
 * Tests named "...Control..." only prove that the scenario itself is set up correctly.
 *
 * Uses login user id 1 (temporary email and password). Everything is rolled back, no mail is sent.
 */
class PasswordResetMailFailureTest extends FeatureTestBase
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

    public function testControlCommandResetsPasswordWhenMailIsSent(): void
    {
        $login_user = $this->prepareLoginUser();
        $histories = $this->countPasswordHistories($login_user);

        $exit_code = $this->resetByCommand($login_user, ['--password' => static::NEW_PASSWORD, '--send' => '1']);

        $this->assertSame(0, $exit_code, Artisan::output());
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);
        $this->assertSame($histories + 1, $this->countPasswordHistories($login_user));
    }

    public function testCommandKeepsPasswordWhenMailIsNotSent(): void
    {
        $this->useFailingMailer();
        $login_user = $this->prepareLoginUser();
        $before = $this->row($login_user);

        $exit_code = $this->resetByCommand($login_user, ['--password' => static::NEW_PASSWORD, '--send' => '1']);

        $this->assertSame(-1, $exit_code, 'command should report the error');
        $this->assertLoginUserIsNotChanged($before);
    }

    public function testCommandKeepsPasswordWhenMailOfRandomPasswordIsNotSent(): void
    {
        $this->useFailingMailer();
        $login_user = $this->prepareLoginUser();
        $before = $this->row($login_user);

        // new password is not shown on console when it is sent by mail, so nobody knows it
        $exit_code = $this->resetByCommand($login_user, ['--random' => '1', '--send' => '1']);

        $this->assertSame(-1, $exit_code, 'command should report the error');
        $this->assertLoginUserIsNotChanged($before);
    }

    public function testControlCommandResetsPasswordWithoutMail(): void
    {
        $this->useFailingMailer();
        $login_user = $this->prepareLoginUser();

        $exit_code = $this->resetByCommand($login_user, ['--password' => static::NEW_PASSWORD, '--send' => '0']);

        $this->assertSame(0, $exit_code, Artisan::output());
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);
    }

    public function testServiceKeepsPasswordWhenMailIsNotSent(): void
    {
        $this->useFailingMailer();
        $login_user = $this->prepareLoginUser();
        $before = $this->row($login_user);

        // import of login users calls the service the same way
        try {
            LoginService::resetPassword($login_user, ['password' => static::NEW_PASSWORD, 'send_password' => true]);
            $this->fail('error of sending mail should be thrown');
        } catch (TransportExceptionInterface $ex) {
            $this->assertSame('mail server is down', $ex->getMessage());
        }

        $this->assertLoginUserIsNotChanged($before);
    }

    public function testControlImportResetsPasswordWhenMailIsSent(): void
    {
        $login_user = $this->prepareLoginUser();

        $this->importLoginUser($login_user, ['password' => static::NEW_PASSWORD, 'send_password' => '1']);

        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);
    }

    public function testImportKeepsPasswordWhenMailIsNotSent(): void
    {
        $this->useFailingMailer();
        $login_user = $this->prepareLoginUser();
        $before = $this->row($login_user);

        try {
            $this->importLoginUser($login_user, ['password' => static::NEW_PASSWORD, 'send_password' => '1']);
            $this->fail('error of sending mail should be thrown');
        } catch (TransportExceptionInterface $ex) {
            $this->assertSame('mail server is down', $ex->getMessage());
        }

        $this->assertLoginUserIsNotChanged($before);
    }

    public function testServiceDoesNotCreateLoginUserWhenMailIsNotSent(): void
    {
        $this->useFailingMailer();
        $user = $this->createUser();
        $login_user = new LoginUser();
        $login_user->base_user_id = $user->id;

        try {
            LoginService::resetPassword($login_user, ['password' => static::NEW_PASSWORD, 'send_password' => true]);
            $this->fail('error of sending mail should be thrown');
        } catch (TransportExceptionInterface $ex) {
            $this->assertSame('mail server is down', $ex->getMessage());
        }

        $this->assertSame(0, \DB::table('login_users')->where('base_user_id', $user->id)->count(), 'login user is created, but the password was not told');
    }

    public function testTransactionOfCallerIsKeptWhenMailIsNotSent(): void
    {
        $this->useFailingMailer();
        $login_user = $this->prepareLoginUser();
        $level = \DB::transactionLevel();

        \DB::beginTransaction();
        \DB::table('login_users')->where('id', $login_user->id)->update(['password_reset_flg' => 1]);
        try {
            LoginService::resetPassword($login_user, ['password' => static::NEW_PASSWORD, 'send_password' => true]);
            $this->fail('error of sending mail should be thrown');
        } catch (TransportExceptionInterface $ex) {
            // what the caller did before is decided by the caller
            $this->assertSame($level + 1, \DB::transactionLevel());
            $this->assertSame(1, (int) $this->row($login_user)->password_reset_flg);
        } finally {
            // also when the test fails. Otherwise the row stays locked for the tests after this
            while (\DB::transactionLevel() > $level) {
                \DB::rollBack();
            }
        }

        $this->assertSame($level, \DB::transactionLevel());
        $this->assertPasswordIs($login_user, static::OLD_PASSWORD);
        $this->assertSame(0, (int) $this->row($login_user)->password_reset_flg);
    }

    // ------------------------------------------------------------------ //
    //  mail of the link to reset the password (auth/forget)               //
    // ------------------------------------------------------------------ //

    /**
     * No mail reached the user, so the user is not made to wait for throttle.
     * (Throttle after the mail was sent: PasswordResetSecurityTest)
     */
    public function testForgetPasswordIsNotThrottledWhenMailIsNotSent(): void
    {
        $this->useFailingMailer();
        $login_user = $this->prepareLoginUser();
        $this->assertGreaterThanOrEqual(1, (int) config('auth.passwords.exment_admins.throttle'), 'control: throttle should be set');

        $this->postForget($login_user)
            ->assertRedirect()
            ->assertSessionHas('status_error', exmtrans('error.mailsend_failed'))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $this->countResetTokens($login_user), 'token of the link that was not sent is left');

        // the user tries again at once
        $this->postForget($login_user)
            ->assertRedirect()
            ->assertSessionHas('status_error', exmtrans('error.mailsend_failed'))
            ->assertSessionHasNoErrors();
    }

    public function testForgetPasswordSendsLinkAfterMailWasNotSent(): void
    {
        $login_user = $this->prepareLoginUser();
        $broker = \Password::broker('exment_admins');
        $credentials = ['login_type' => 'pure', 'target_column' => 'email', 'username' => $login_user->email];

        $this->useFailingMailer();
        $this->postForget($login_user)->assertSessionHas('status_error', exmtrans('error.mailsend_failed'));

        // mail server is back
        Notification::fake();
        $this->assertSame(\Password::RESET_LINK_SENT, $broker->sendResetLink($credentials), 'link is not sent though the mail before was not sent');
        $this->assertSame(1, $this->countResetTokens($login_user));
        $this->assertSame(\Password::RESET_THROTTLED, $broker->sendResetLink($credentials), 'a second reset mail right after the first must be throttled');
    }

    // ------------------------------------------------------------------ //
    //  helpers                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Forgot password screen, as a new browser.
     */
    protected function postForget(LoginUser $login_user): TestResponse
    {
        $this->resetAuth();

        return $this->postAs(admin_url('auth/forget'), ['email' => $login_user->email]);
    }

    protected function countResetTokens(LoginUser $login_user): int
    {
        return \DB::table('password_reset_tokens')->where('email', $login_user->email)->count();
    }

    /**
     * @param array<string, string> $options
     */
    protected function resetByCommand(LoginUser $login_user, array $options): int
    {
        return Artisan::call('exment:resetpassword', array_merge(['--id' => (string) $login_user->base_user_id], $options));
    }

    /**
     * @return \stdClass
     */
    protected function row(LoginUser $login_user)
    {
        $row = \DB::table('login_users')->where('id', $login_user->id)->first();
        if (!($row instanceof \stdClass)) {
            $this->fail('login user is not found');
        }

        return $row;
    }

    /**
     * @param \stdClass $before
     */
    protected function assertLoginUserIsNotChanged($before): void
    {
        $after = \DB::table('login_users')->where('id', $before->id)->first();
        if (!($after instanceof \stdClass)) {
            $this->fail('login user is not found');
        }

        $this->assertTrue(Hash::check(static::OLD_PASSWORD, (string) $after->password), 'previous password does not work any more');
        $this->assertSame($before->password, $after->password, 'password is changed');
        $this->assertSame($before->remember_token, $after->remember_token, 'remember token is changed');
        $this->assertSame((int) $before->password_reset_flg, (int) $after->password_reset_flg, 'password_reset_flg is changed');
    }

    protected function countPasswordHistories(LoginUser $login_user): int
    {
        return \DB::table('password_histories')->where('login_user_id', $login_user->id)->count();
    }
}
