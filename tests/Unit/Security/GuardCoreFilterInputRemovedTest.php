<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Tests\TestCase;
use Exceedone\Exment\Middleware\LogOperation;
use ExmentAdminCore\Admin\Middleware\LogOperation as CoreLogOperation;

/**
 * GUARD: the core middleware's own masking (filterInput / admin.operation_log.
 * filter_input) was removed - Exment must still cover everything it used to.
 *
 * Why it was removed: on Exment the core handle() never runs (Exment overrides
 * it), so filterInput() was dead code whose leftover config key kept making
 * readers - and the update manual - believe masking was configured there.
 *
 * Why this guard exists: filterInput() used to be an accidental safety net. When
 * the "admin.log" alias regression pointed the alias back at the core class, it
 * was the only thing still masking "password" (as "*****-filtered-out-*****").
 * With it gone, Exment's masking is the ONLY masking left, so the parity
 * assertions below must hold, and GuardOperationLogMiddlewareAliasTest must keep
 * the alias pinned. Do not weaken either without a replacement.
 *
 * Keys the removed config used to mask:
 *   token, password, password_remember, password_confirmation
 * "password_remember" is intentionally not asserted: no form posts a field of
 * that name. The login form's checkbox is "remember" (value "1"), which is not
 * a credential - that entry never matched anything.
 */
class GuardCoreFilterInputRemovedTest extends TestCase
{
    /** Build a request path below the admin prefix ("admin/auth/reset/x" etc.). */
    private function adminPath(string $uri): string
    {
        return ltrim(admin_base_path($uri), '/');
    }

    /**
     * @param array<mixed> $input
     * @return array<mixed>
     */
    private function mask(array $input, string $path): array
    {
        return LogOperation::maskInputArray($input, $path);
    }

    private function coreClassFile(): string
    {
        $file = (new \ReflectionClass(CoreLogOperation::class))->getFileName();
        $this->assertIsString($file);
        return $file;
    }

    private function corePackageConfigFile(): string
    {
        // <pkg>/src/Middleware/LogOperation.php -> <pkg>/config/admin.php
        return dirname($this->coreClassFile(), 3) . '/config/admin.php';
    }

    // ----- the removal itself stays removed --------------------------------

    public function testCoreMiddlewareNoLongerDefinesFilterInput(): void
    {
        $this->assertFalse(
            (new \ReflectionClass(CoreLogOperation::class))->hasMethod('filterInput'),
            'filterInput() is back on the core middleware. It is dead code on Exment (the core '
            . 'handle() never runs) and its config key misleads readers into thinking masking is '
            . 'configured in config/admin.php. Masking belongs to Exment\'s LogOperation.'
        );
    }

    public function testNoMiddlewareSourceStillMentionsFilterInput(): void
    {
        // catches a half-revert: method deleted but the call in handle() left
        // behind (fatal), or the call removed while the method lingers.
        foreach ([$this->coreClassFile(), (new \ReflectionClass(LogOperation::class))->getFileName()] as $file) {
            $this->assertStringNotContainsString(
                'filterInput',
                (string) file_get_contents((string) $file),
                basename((string) $file) . ' still references filterInput().'
            );
        }
    }

    public function testCorePackageConfigNoLongerDeclaresFilterInput(): void
    {
        $file = $this->corePackageConfigFile();
        $this->assertFileExists($file);
        $this->assertStringNotContainsString(
            'filter_input',
            (string) file_get_contents($file),
            'The core package config declares filter_input again. Nothing reads it - an orphan '
            . 'config key is exactly what caused the reported misunderstanding.'
        );
    }

    // ----- but the parts Exment inherits must NOT be removed ---------------

    public function testCoreStillProvidesTheMethodsExmentInherits(): void
    {
        // Exment::shouldLogOperation() calls inAllowedMethods() and
        // Exment::inExceptArray() calls parent::inExceptArray().
        foreach (['inAllowedMethods', 'inExceptArray'] as $method) {
            $this->assertTrue(
                method_exists(CoreLogOperation::class, $method),
                "Core middleware lost {$method}(), which Exment's LogOperation still calls."
            );
        }
    }

    public function testCoreConfigStillDeclaresExceptAndAllowedMethods(): void
    {
        // these two keys of the operation_log block are still live: deleting the
        // whole block (instead of just filter_input) breaks them. "except" has no
        // default, so a missing key also raises a foreach() warning.
        $source = (string) file_get_contents($this->corePackageConfigFile());
        $this->assertStringContainsString("'except'", $source);
        $this->assertStringContainsString("'allowed_methods'", $source);

        $this->assertIsArray(
            config('admin.operation_log.except'),
            'config("admin.operation_log.except") is not an array; inExceptArray() will warn and '
            . 'log routes that must never be logged.'
        );
    }

    // ----- parity: every key the removed filter used to mask ---------------

    public function testPasswordKeysStayMaskedWithoutTheCoreFilter(): void
    {
        $masked = $this->mask(
            ['password' => 'real-pass', 'password_confirmation' => 'real-pass', 'name' => 'foo'],
            $this->adminPath('auth/setting')
        );

        $this->assertSame('***', $masked['password']);
        $this->assertSame('***', $masked['password_confirmation']);
        $this->assertSame('foo', $masked['name'], 'Business data must stay readable.');
    }

    public function testResetTokenStaysMaskedWithoutTheCoreFilter(): void
    {
        // auth/reset.blade.php posts the raw reset token back as a hidden field
        // named "token" - the only screen in Exment that posts that key.
        $masked = $this->mask(
            ['token' => 'raw-reset-token', 'password' => 'p'],
            $this->adminPath('auth/reset/raw-reset-token')
        );

        $this->assertSame('***', $masked['token']);
    }

    public function testResetTokenInThePathStaysMasked(): void
    {
        // the token is also a path segment, on both the mail link (GET) and the
        // form action (POST); it is a bearer credential, so it is masked whole.
        $masked = LogOperation::hidePathParams($this->adminPath('auth/reset/raw-reset-token'));

        $this->assertStringNotContainsString('raw-reset-token', $masked);
        $this->assertStringEndsWith('***', $masked);
    }

    public function testTokenIsNotMaskedOnBusinessScreens(): void
    {
        // deliberate difference from the removed core filter, which masked "token"
        // on every screen: a user-defined table may own a "token" column, and
        // over-masking it would strip the operation log of its audit value.
        $masked = $this->mask(
            ['token' => 'business-value'],
            $this->adminPath('data/client')
        );

        $this->assertSame(
            'business-value',
            $masked['token'],
            '"token" is masked globally again - user-defined business columns are being hidden. '
            . 'Scope it to the screen that posts it (mask_columns_by_uri) instead.'
        );
    }
}
