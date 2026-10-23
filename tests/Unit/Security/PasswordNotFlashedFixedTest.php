<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Model\Define;
use ExmentAdminCore\Admin\Form as AdminForm;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * SEC-01 (FIXED): a password typed into a form was flashed to the session (plain text in the
 * session store) and printed back into the form when the form was sent back with an error.
 *
 * Fix (port of the v6 hotfix issue_1734_1735, exment + laravel-admin):
 *  - exment: Define::DONT_FLASH_INPUT_KEYS + get_flash_input(); the screens with password inputs
 *    send the form back with withInput(get_flash_input()) instead of withInput().
 *  - exment-admin-core: Form::DONT_FLASH_INPUT_KEYS + Form::getFlashInput(); Form, Widgets\Form,
 *    the Pjax middleware and the login controller use it instead of withInput().
 *
 * Other inputs are still flashed, only password, password_confirmation and current_password are removed.
 */
class PasswordNotFlashedFixedTest extends TestCase
{
    protected const KEYS = ['password', 'password_confirmation', 'current_password'];

    /**
     * @param array<mixed> $input
     */
    protected function bindRequest(array $input): void
    {
        $request = Request::create('/admin/auth/setting', 'PUT', $input);
        $request->setLaravelSession($this->app['session.store']);
        $this->app->instance('request', $request);
    }

    /**
     * @return array<mixed>
     */
    protected function input(): array
    {
        return [
            'user_name' => 'name',
            'value' => ['user_code' => 'code', 'email' => 'a@example.com'],
            'password' => 'Typed-Secret-2026!',
            'password_confirmation' => 'Typed-Other-2026!',
            'current_password' => 'Typed-Current-2026!',
        ];
    }

    public function test_keys_are_the_same_on_exment_and_admin_core(): void
    {
        $this->assertSame(self::KEYS, Define::DONT_FLASH_INPUT_KEYS);
        $this->assertSame(self::KEYS, AdminForm::DONT_FLASH_INPUT_KEYS);
    }

    public function test_flash_input_removes_only_passwords(): void
    {
        $this->bindRequest($this->input());
        $expected = ['user_name' => 'name', 'value' => ['user_code' => 'code', 'email' => 'a@example.com']];

        $this->assertSame($expected, get_flash_input());
        $this->assertSame($expected, AdminForm::getFlashInput());
    }

    public function test_redirect_back_does_not_put_passwords_in_session(): void
    {
        $this->bindRequest($this->input());
        $session = $this->app['session.store'];

        back()->withInput(get_flash_input());
        $old = $session->get('_old_input');
        $this->assertSame('name', $old['user_name'] ?? null, 'other inputs are still flashed');
        foreach (self::KEYS as $key) {
            $this->assertArrayNotHasKey($key, $old);
        }
        $this->assertStringNotContainsString('Typed-', serialize($session->all()));

        $session->forget('_old_input');
        back()->withInput(AdminForm::getFlashInput());
        $this->assertStringNotContainsString('Typed-', serialize($session->all()));
        $this->assertNull(old('password'));
        $this->assertSame('code', old('value.user_code'));
    }

    /**
     * Screens with password inputs must not send the form back with the whole input.
     */
    public function test_screens_with_passwords_do_not_flash_whole_input(): void
    {
        $exment = dirname(__DIR__, 3) . '/src/';
        $files = [
            $exment . 'Controllers/AuthController.php' => 3,
            $exment . 'Controllers/LoginUserController.php' => 1,
            $exment . 'Controllers/ResetPasswordController.php' => 1,
            $exment . 'PartialCrudItems/Providers/LoginUserItem.php' => 2,
            $exment . 'Services/Installer/InitializeFormTrait.php' => 1,
            $exment . 'Services/Login/LoginService.php' => 1,
        ];
        $adminCore = dirname((string) (new \ReflectionClass(AdminForm::class))->getFileName()) . '/';
        $files += [
            $adminCore . 'Form.php' => 3,
            $adminCore . 'Widgets/Form.php' => 1,
            $adminCore . 'Middleware/Pjax.php' => 1,
            $adminCore . 'Controllers/AuthController.php' => 1,
        ];

        foreach ($files as $path => $fixed) {
            $this->assertFileExists($path);
            $code = CodebaseGuardTestCase::stripComments((string) file_get_contents($path));
            $this->assertSame(0, substr_count($code, '->withInput()'), "$path still flashes the whole input");
            $this->assertSame(
                $fixed,
                substr_count($code, '->withInput(get_flash_input())') + substr_count($code, 'getFlashInput())'),
                "$path: expected $fixed redirects without passwords"
            );
        }
    }
}
