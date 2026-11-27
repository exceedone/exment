<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PasswordHistory;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Testing\TestResponse;

/**
 * The change password screen (admin/auth/change) tells the user why it is shown.
 *
 * A login whose password is older than "password_expiration_days" is sent to this screen
 * (AuthController::checkPasswordLimit). Before the fix the screen only showed the password
 * rule, so the user did not know the password had expired. The screen now shows
 * "user.help.password_change" while the session holds the expired flag; a user who opens the
 * screen by himself, or who is sent there for the first login, does not see it.
 *
 * The test creates its own user and rolls everything back.
 */
class PasswordExpiredNoticeTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Expired-test-1234';
    private const NEW_PASSWORD = 'Expired-new-5678';
    private const EXPIRATION_DAYS = 30;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        System::password_expiration_days(self::EXPIRATION_DAYS);
        System::clearCache();
    }

    protected function tearDown(): void
    {
        // the system settings are cached for the request
        System::clearCache();
        parent::tearDown();
    }

    public function testAnExpiredPasswordShowsTheReasonOnTheChangeScreen(): void
    {
        [$code] = $this->createPureLoginUser(self::EXPIRATION_DAYS + 10);

        $this->postLoginForm($code, self::PASSWORD)->assertRedirect(admin_url('auth/change'));
        $this->assertTrue(session()->has(Define::SYSTEM_KEY_SESSION_PASSWORD_LIMIT));

        $response = $this->get(admin_url('auth/change'))->assertOk();
        $this->assertCaption($response, exmtrans('user.help.password_change'));
        // the password rule stays under the new password field
        $response->assertSee(\Exment::get_password_help(), false);
    }

    public function testAnExpiredPasswordKeepsTheReasonOnEveryRedirect(): void
    {
        [$code] = $this->createPureLoginUser(self::EXPIRATION_DAYS + 10);
        $this->postLoginForm($code, self::PASSWORD)->assertRedirect(admin_url('auth/change'));

        // another page still sends the user back, and the screen still says why
        $this->get(admin_url('data/' . SystemTableName::USER))->assertRedirect(admin_url('auth/change'));
        $this->assertCaption($this->get(admin_url('auth/change'))->assertOk(), exmtrans('user.help.password_change'));
    }

    public function testAPasswordWithinTheLimitIsNotSentToTheChangeScreen(): void
    {
        [$code] = $this->createPureLoginUser(self::EXPIRATION_DAYS - 10);

        $response = $this->postLoginForm($code, self::PASSWORD)->assertRedirect();
        $this->assertNotSame(admin_url('auth/change'), $response->headers->get('Location'));
        $this->assertFalse(session()->has(Define::SYSTEM_KEY_SESSION_PASSWORD_LIMIT));
    }

    public function testOpeningTheChangeScreenByHimselfShowsNoExpiredNotice(): void
    {
        [$code, $loginUser] = $this->createPureLoginUser(0);
        $this->postLoginForm($code, self::PASSWORD);
        $this->assertAuthenticatedAs($loginUser, 'admin');

        $response = $this->get(admin_url('auth/change'))->assertOk();
        $this->assertCaption($response, \Exment::get_password_help());
        $response->assertDontSee(exmtrans('user.help.password_change'), false);
    }

    public function testNoExpirationSettingShowsNoExpiredNotice(): void
    {
        System::password_expiration_days(0);
        System::clearCache();
        [$code] = $this->createPureLoginUser(self::EXPIRATION_DAYS + 10);

        $response = $this->postLoginForm($code, self::PASSWORD)->assertRedirect();
        $this->assertNotSame(admin_url('auth/change'), $response->headers->get('Location'));
        $this->get(admin_url('auth/change'))->assertOk()->assertDontSee(exmtrans('user.help.password_change'), false);
    }

    public function testTheFirstLoginKeepsItsOwnMessage(): void
    {
        // the first login change wins over the expiration, as before
        [$code, $loginUser] = $this->createPureLoginUser(self::EXPIRATION_DAYS + 10);
        \DB::table('login_users')->where('id', $loginUser->id)->update(['password_reset_flg' => 1]);

        $this->postLoginForm($code, self::PASSWORD)->assertRedirect(admin_url('auth/change'));
        $this->assertTrue(session()->has(Define::SYSTEM_KEY_SESSION_FIRST_CHANGE_PASSWORD));

        $response = $this->get(admin_url('auth/change'))->assertOk();
        $this->assertCaption($response, exmtrans('user.help.first_change_password'));
        $response->assertDontSee(exmtrans('user.help.password_change'), false);
    }

    public function testChangingTheExpiredPasswordLetsTheUserIn(): void
    {
        [$code, $loginUser] = $this->createPureLoginUser(self::EXPIRATION_DAYS + 10);
        $this->postLoginForm($code, self::PASSWORD)->assertRedirect(admin_url('auth/change'));

        $this->post(admin_url('auth/change'), [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(admin_url('auth/login'));
        $this->assertFalse(session()->has(Define::SYSTEM_KEY_SESSION_PASSWORD_LIMIT));

        // the new password is a new history: the next login goes to the app
        $response = $this->postLoginForm($code, self::NEW_PASSWORD)->assertRedirect();
        $this->assertAuthenticatedAs($loginUser, 'admin');
        $this->assertNotSame(admin_url('auth/change'), $response->headers->get('Location'));
        $this->get(admin_url('auth/change'))->assertOk()->assertDontSee(exmtrans('user.help.password_change'), false);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertCaption(TestResponse $response, string $caption): void
    {
        $response->assertSee('<p class="login-box-msg">' . e($caption) . '</p>', false);
    }

    /**
     * Post the login form (AuthController) in a new browser.
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function postLoginForm(string $code, string $password): TestResponse
    {
        app('auth')->forgetGuards();
        $this->flushSession();

        return $this->post(admin_url('auth/login'), [
            'username' => $code,
            'password' => $password,
        ]);
    }

    /**
     * Create a login user whose password was set $daysAgo days ago.
     *
     * @return array{0: string, 1: LoginUser}
     */
    private function createPureLoginUser(int $daysAgo): array
    {
        $code = 'expired_' . short_uuid();

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
        $loginUser->password = self::PASSWORD;
        $loginUser->save();

        // the history the expiration is counted from
        PasswordHistory::where('login_user_id', $loginUser->id)
            ->update(['created_at' => Carbon::now()->subDays($daysAgo)]);
        $this->assertSame(1, PasswordHistory::where('login_user_id', $loginUser->id)->count());

        return [$code, $loginUser];
    }
}
