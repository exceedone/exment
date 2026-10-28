<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * POST admin/auth/change (ChangePasswordController::change). A validator failure
 * returns back()->withErrors() and leaves the password untouched; success rewrites
 * it and redirects to the login page - neither path renders a view that could
 * exit() mid-request, so this is safe to call for real.
 */
class ChangePasswordCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const ADMIN_CURRENT_PASSWORD = 'adminadmin';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testWrongCurrentPasswordIsRejected(): void
    {
        $this->post(admin_url('auth/change'), [
            'current_password' => 'this-is-not-the-current-password',
            'password' => 'NewPassw0rd1',
            'password_confirmation' => 'NewPassw0rd1',
        ])->assertRedirect()->assertSessionHasErrors('current_password');

        $this->assertTrue(\Hash::check(self::ADMIN_CURRENT_PASSWORD, LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->password));
    }

    public function testMismatchedConfirmationIsRejected(): void
    {
        $this->post(admin_url('auth/change'), [
            'current_password' => self::ADMIN_CURRENT_PASSWORD,
            'password' => 'NewPassw0rd1',
            'password_confirmation' => 'NewPassw0rd2-different',
        ])->assertRedirect()->assertSessionHasErrors('password');

        $this->assertTrue(\Hash::check(self::ADMIN_CURRENT_PASSWORD, LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->password));
    }

    public function testValidChangeUpdatesThePasswordAndRedirectsToLogin(): void
    {
        $this->post(admin_url('auth/change'), [
            'current_password' => self::ADMIN_CURRENT_PASSWORD,
            'password' => 'NewPassw0rd1',
            'password_confirmation' => 'NewPassw0rd1',
        ])->assertRedirect(admin_url('auth/login'));

        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertTrue(\Hash::check('NewPassw0rd1', $admin->password));
        $this->assertFalse(boolval($admin->password_reset_flg));
    }
}
