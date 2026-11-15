<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Model\Define;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * #1735 (FIXED): a password typed into a form was flashed to the session (plain text in the
 * session store) and printed back into the form when the form was sent back with an error.
 *
 * Fix: Define::DONT_FLASH_INPUT_KEYS + get_flash_input(); the screens with password inputs
 * send the form back with withInput(get_flash_input()) instead of withInput(), and the
 * password inputs of auth/change and auth/reset no longer print old('password').
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

    /**
     * Package root (independent of whether ./exment or the vendor symlink is used).
     */
    protected function packagePath(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    /**
     * Remove comments, so that only code is checked.
     */
    protected function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (!is_array($token)) {
                $out .= $token;
            } elseif ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                $out .= $token[1];
            }
        }
        return $out;
    }

    public function test_keys_not_to_flash(): void
    {
        $this->assertSame(self::KEYS, Define::DONT_FLASH_INPUT_KEYS);
    }

    public function test_flash_input_removes_only_passwords(): void
    {
        $this->bindRequest($this->input());
        $expected = ['user_name' => 'name', 'value' => ['user_code' => 'code', 'email' => 'a@example.com']];

        $this->assertSame($expected, get_flash_input());
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
        $this->assertNull(old('password'));
        $this->assertSame('code', old('value.user_code'));
    }

    /**
     * Screens with password inputs must not send the form back with the whole input.
     */
    public function test_screens_with_passwords_do_not_flash_whole_input(): void
    {
        $files = [
            'src/Controllers/AuthController.php' => 3,
            'src/Controllers/LoginUserController.php' => 1,
            'src/Controllers/ResetPasswordController.php' => 1,
            'src/PartialCrudItems/Providers/LoginUserItem.php' => 2,
            'src/Services/Installer/InitializeFormTrait.php' => 1,
            'src/Services/Login/LoginService.php' => 1,
        ];

        foreach ($files as $file => $fixed) {
            $path = $this->packagePath($file);
            $this->assertFileExists($path);
            $code = $this->stripComments((string) file_get_contents($path));
            $this->assertSame(0, substr_count($code, '->withInput()'), "$file still flashes the whole input");
            $this->assertSame(
                $fixed,
                substr_count($code, '->withInput(get_flash_input())'),
                "$file: expected $fixed redirects without passwords"
            );
        }
    }

    /**
     * The password inputs must not print a value back.
     */
    public function test_password_inputs_do_not_print_old_input(): void
    {
        foreach (['resources/views/auth/change.blade.php' => 3, 'resources/views/auth/reset.blade.php' => 2] as $file => $inputs) {
            $path = $this->packagePath($file);
            $this->assertFileExists($path);
            $view = (string) file_get_contents($path);

            $this->assertSame($inputs, preg_match_all('/<input[^>]*type="password"[^>]*>/', $view, $matches), "$file: password inputs");
            foreach ($matches[0] as $input) {
                $this->assertStringNotContainsString('value=', $input, "$file prints a password back");
            }
        }
    }
}
