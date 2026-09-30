<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * Screens built on the admin form (exment-admin-core) that have password inputs:
 * account setting screen (auth/setting) and user data screen (data/user/{id}).
 *
 * When the form is sent back (validation error, or error while saving on a pjax request),
 * a typed password is neither flashed to the session nor printed to the html.
 *
 * Every test asserts the expected (safe) behaviour.
 *
 * Uses login user id 1 (temporary email and password). Everything is rolled back, no mail is sent.
 */
class PasswordAdminFormFlashTest extends FeatureTestBase
{
    use DatabaseTransactions;
    use PasswordTestTrait;

    protected const OLD_PASSWORD = 'Old-Leaked-2026!';
    protected const NEW_PASSWORD = 'New-Secure-2026!';
    protected const TYPED_PASSWORD = 'Typed-Secret-2026!';
    protected const TYPED_CURRENT_PASSWORD = 'Typed-Current-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();
    }

    public function testAccountSettingValidationErrorDoesNotFlashPasswords(): void
    {
        $login_user = $this->prepareLoginUser();
        $this->be($login_user, 'admin');

        // wrong current password
        $this->putSetting()->assertRedirect(admin_url('auth/setting'))->assertSessionHasErrors('current_password');

        $this->assertPasswordIs($login_user, static::OLD_PASSWORD);
        $this->assertPasswordNotFlashed();
    }

    public function testAccountSettingScreenDoesNotPrintTypedPasswords(): void
    {
        $login_user = $this->prepareLoginUser();
        $this->be($login_user, 'admin');

        $this->putSetting()->assertRedirect(admin_url('auth/setting'));
        $html = (string) $this->get(admin_url('auth/setting'))->assertStatus(200)->getContent();

        $inputs = $this->passwordInputs($html);
        $this->assertCount(3, $inputs, 'account setting form should have 3 password inputs');
        foreach ($inputs as $input) {
            $this->assertStringNotContainsString(static::TYPED_PASSWORD, $input, 'typed password is printed to the html');
            $this->assertStringNotContainsString(static::TYPED_CURRENT_PASSWORD, $input, 'typed current password is printed to the html');
        }
    }

    /**
     * An error while saving is shown as the error page (status 200) to a signed in user,
     * so the pjax middleware does not send the form back with input.
     */
    public function testControlAccountSettingErrorOnPjaxShowsErrorPage(): void
    {
        $login_user = $this->prepareLoginUser();
        $this->be($login_user, 'admin');
        LoginUser::saving(function () {
            throw new \RuntimeException('error while saving');
        });

        $html = (string) $this->putSetting(
            ['current_password' => static::OLD_PASSWORD],
            ['X-PJAX' => 'true', 'X-PJAX-Container' => '#pjax-container']
        )->assertStatus(200)->getContent();

        $this->assertStringContainsString('error while saving', $html);
        $this->assertStringNotContainsString(static::TYPED_PASSWORD, $html);
        $this->assertPasswordIs($login_user, static::OLD_PASSWORD);
        $this->assertPasswordNotFlashed();
    }

    public function testUserDataCreateValidationErrorDoesNotFlashPasswords(): void
    {
        $login_user = $this->prepareLoginUser();
        $this->be($login_user, 'admin');
        $url = admin_urls('data', SystemTableName::USER);

        // user name is required
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->from($url . '/create')
            ->post($url, [
                'value' => [
                    'user_code' => 'flash-test-' . uniqid(),
                    'user_name' => '',
                    'email' => 'flash-test@example.invalid',
                ],
                'use_loginuser' => '1',
                'reset_password' => '1',
                'create_password_auto' => '0',
                'password' => static::TYPED_PASSWORD,
                'password_confirmation' => static::TYPED_PASSWORD,
                'send_password' => '0',
            ])->assertRedirect($url . '/create')->assertSessionHasErrors();

        $this->assertPasswordNotFlashed();
        $this->assertNotEmpty($this->flashedInput()['value']['user_code'] ?? null, 'other input should still be kept for the form');
    }

    public function testUserDataValidationErrorDoesNotFlashPasswords(): void
    {
        $login_user = $this->prepareLoginUser();
        $this->be($login_user, 'admin');
        $url = admin_urls('data', SystemTableName::USER, $login_user->base_user_id);

        // user name is required
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->from($url . '/edit')
            ->put($url, [
                'value' => [
                    'user_code' => $login_user->base_user->getValue('user_code'),
                    'user_name' => '',
                    'email' => $login_user->base_user->getValue('email'),
                ],
                'use_loginuser' => '1',
                'reset_password' => '1',
                'create_password_auto' => '0',
                'password' => static::TYPED_PASSWORD,
                'password_confirmation' => static::TYPED_PASSWORD,
                'send_password' => '0',
            ])->assertRedirect($url . '/edit')->assertSessionHasErrors();

        $this->assertPasswordIs($login_user, static::OLD_PASSWORD);
        $this->assertPasswordNotFlashed();
    }

    // ------------------------------------------------------------------ //
    //  helpers                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Account setting screen, by default with a wrong current password.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @return TestResponse
     */
    protected function putSetting(array $data = [], array $headers = []): TestResponse
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->from(admin_url('auth/setting'))
            ->put(admin_url('auth/setting'), array_merge([
                'current_password' => static::TYPED_CURRENT_PASSWORD,
                'password' => static::TYPED_PASSWORD,
                'password_confirmation' => static::TYPED_PASSWORD,
            ], $data), $headers);
    }
}
