<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Providers\LoginUserProvider;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Hash;

/**
 * #1734: a "remember me" cookie is checked only against login_users.remember_token. The token
 * was not renewed when the password changed, so a cookie issued before the change kept signing
 * in. It is renewed now on every password change: by the user (auth/change), by the mail link
 * (auth/reset/{token}) and by an administrator (LoginService::resetPassword()).
 *
 * Uses the administrator of the test data (login user id 1). Everything is rolled back.
 */
class PasswordChangeRememberTokenTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const ADMIN_CURRENT_PASSWORD = 'adminadmin';

    private const OLD_TOKEN = 'issue1734-remember-token-issued-before-the-password-change';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();

        // the token of a "remember me" cookie issued before
        \Illuminate\Support\Facades\DB::table('login_users')
            ->where('id', TestDefine::TESTDATA_USER_LOGINID_ADMIN)
            ->update(['remember_token' => self::OLD_TOKEN]);
        $this->assertNotNull($this->retrieveByOldToken(), 'the cookie signs in before the password is changed');
    }

    public function testChangingTheOwnPasswordRenewsTheRememberToken(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $this->post(admin_url('auth/change'), [
            'current_password' => self::ADMIN_CURRENT_PASSWORD,
            'password' => 'NewPassw0rd1',
            'password_confirmation' => 'NewPassw0rd1',
        ])->assertRedirect(admin_url('auth/login'));

        $this->assertTrue(Hash::check('NewPassw0rd1', strval($this->stored('password'))));
        $this->assertRememberTokenRenewed();
    }

    public function testResettingThePasswordByMailLinkRenewsTheRememberToken(): void
    {
        $login_user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        if (empty($login_user->base_user->getValue('email'))) {
            $login_user->base_user->setValue(['email' => 'issue1734@example.invalid'])->saved_notify(false)->save();
            $login_user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        }
        $this->assertNotEmpty($login_user->email);
        $token = \Illuminate\Support\Facades\Password::broker('exment_admins')->createToken($login_user);

        $new = 'Reset-Flow-1734!';
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->from(admin_url('auth/reset/' . $token))
            ->post(admin_url('auth/reset/' . $token), [
                'token' => $token,
                'password' => $new,
                'password_confirmation' => $new,
            ])->assertRedirect();

        $this->assertTrue(Hash::check($new, strval($this->stored('password'))), 'the password is reset');
        $this->assertRememberTokenRenewed();
    }

    public function testAPasswordResetByAnAdministratorRenewsTheRememberToken(): void
    {
        $login_user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        LoginService::resetPassword($login_user, ['password' => 'Admin-Reset-1734!']);

        $this->assertTrue(Hash::check('Admin-Reset-1734!', strval($this->stored('password'))), 'the password is reset');
        $this->assertRememberTokenRenewed();
    }

    public function testSavingWithoutAPasswordChangeKeepsTheRememberToken(): void
    {
        $login_user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $login_user->password_reset_flg = !boolval($login_user->password_reset_flg);
        $login_user->save();

        // the same password again is not a change either
        $login_user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $login_user->password = self::ADMIN_CURRENT_PASSWORD;
        $login_user->save();

        $this->assertSame(self::OLD_TOKEN, $this->stored('remember_token'));
        $this->assertNotNull($this->retrieveByOldToken());
    }

    private function assertRememberTokenRenewed(): void
    {
        $token = $this->stored('remember_token');
        $this->assertNotEmpty($token, 'a new remember token is stored');
        $this->assertNotSame(self::OLD_TOKEN, $token);
        $this->assertNull($this->retrieveByOldToken(), 'the cookie issued before the change no longer signs in');
    }

    /**
     * The value stored for the administrator in login_users.
     *
     * @return mixed
     */
    private function stored(string $column)
    {
        return \Illuminate\Support\Facades\DB::table('login_users')->where('id', TestDefine::TESTDATA_USER_LOGINID_ADMIN)->value($column);
    }

    /**
     * What the session guard does with a "remember me" cookie: look the user up by id and token.
     *
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    private function retrieveByOldToken()
    {
        $provider = new LoginUserProvider($this->app->make('hash'), LoginUser::class);

        return $provider->retrieveByToken(TestDefine::TESTDATA_USER_LOGINID_ADMIN, self::OLD_TOKEN);
    }
}
