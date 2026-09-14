<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

/**
 * "Forgot password" flow: GET/POST auth/reset/{token}.
 *
 * Regression: on Laravel 11+ the app's base controller (App\Http\Controllers\Controller) no longer
 * provides ValidatesRequests, so ResetPasswordController::reset() calling $this->validate() ended
 * with "Call to undefined method ...::validate()" (HTTP 500) when the user submitted a new password.
 *
 * Uses login user id 1, gives it a temporary email if needed. Everything is rolled back.
 */
class PasswordResetFlowTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testResetPasswordByTokenSucceeds(): void
    {
        $login_user = $this->loginUserWithEmail();
        $old_hash = \DB::table('login_users')->where('id', $login_user->id)->value('password');
        $token = \Password::broker('exment_admins')->createToken($login_user);

        $this->get(admin_url('auth/reset/' . $token))->assertStatus(200);

        $new = 'Reset-Flow-2026!';
        $response = $this->postReset($token, $new, $new);

        $response->assertRedirect();
        $stored = \DB::table('login_users')->where('id', $login_user->id)->value('password');
        $this->assertNotSame($old_hash, $stored, 'password should be updated');
        $this->assertTrue(Hash::check($new, $stored), 'new password must be usable');
        $this->assertSame(0, \DB::table('password_reset_tokens')->where('email', $login_user->email)->count(), 'token should be consumed');
    }

    public function testResetPasswordValidationErrorRedirectsBack(): void
    {
        $login_user = $this->loginUserWithEmail();
        $old_hash = \DB::table('login_users')->where('id', $login_user->id)->value('password');
        $token = \Password::broker('exment_admins')->createToken($login_user);

        // confirmation mismatch -> validation error, not 500
        $response = $this->postReset($token, 'Reset-Flow-2026!', 'Different-2026!');

        $response->assertStatus(302);
        $response->assertSessionHasErrors('password');
        $this->assertSame($old_hash, \DB::table('login_users')->where('id', $login_user->id)->value('password'), 'password must not change');
    }

    // ------------------------------------------------------------------ //

    protected function loginUserWithEmail(): LoginUser
    {
        $login_user = LoginUser::find(1);
        if (empty($login_user->base_user->getValue('email'))) {
            $login_user->base_user->setValue(['email' => 'reset-flow@example.invalid'])->saved_notify(false)->save();
            $login_user = LoginUser::find(1);
        }
        $this->assertNotEmpty($login_user->email);

        return $login_user;
    }

    protected function postReset(string $token, string $password, string $confirmation)
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->from(admin_url('auth/reset/' . $token))
            ->post(admin_url('auth/reset/' . $token), [
                'token' => $token,
                'password' => $password,
                'password_confirmation' => $confirmation,
            ]);
    }
}
