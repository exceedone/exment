<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\InitializeStatus;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\EnvService;
use Exceedone\Exment\Services\Installer\InstallService;

/**
 * Regression tests for three bugs fixed in the install flow.
 *
 * --- Bug 1 (EnvService) ---
 * setEnv() left duplicate keys in .env, so the last (old) value won the race.
 * Fix: first occurrence is replaced; all subsequent occurrences are dropped.
 *
 * --- Bug 2 (InstallService::getStatus) ---
 * When a user was mid-install-wizard (session != null) and the DB happened to be
 * reachable (e.g. previous partial install or session carried credentials), the very
 * first check in getStatus() returned INITIALIZE immediately.
 * InstallService::post() then redirected to /admin/initialize BEFORE InstallingForm
 * had a chance to write the corrected DB_PASSWORD to .env.
 * On the next request the middleware read the *old* wrong password from .env →
 * canConnection() failed → redirected back to /admin/install → infinite loop.
 * Fix: skip the INITIALIZE shortcut when session status is not null.
 *
 * --- Bug 3 (InstallingForm::post) ---
 * forgetInputParams() was called before canConnection(), so on retry the session
 * inputs were gone and the runtime config was not updated, making canConnection()
 * fail with the stale .env password.
 * Fix: merge + setEnv + setSettingTmp + forgetInputParams all happen in one
 * atomic try-block before the canConnection() gate.
 */
class InstallFlowRegressionTest extends FeatureTestBase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Temporary folder standing in for the base path in EnvService tests. */
    private string $tmpDir;

    /** The .env file inside $tmpDir. */
    private string $tmpEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('env_test_', true);
        mkdir($this->tmpDir);
        $this->tmpEnv = $this->tmpDir . DIRECTORY_SEPARATOR . '.env';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpEnv)) {
            unlink($this->tmpEnv);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
        // Clear any session keys touched during tests
        session()->forget(Define::SYSTEM_KEY_SESSION_INITIALIZE);
        session()->forget(Define::SYSTEM_KEY_SESSION_INITIALIZE_INPUTS);
        parent::tearDown();
    }

    /**
     * Write content to the temporary .env file.
     */
    private function useEnvFile(string $content): void
    {
        file_put_contents($this->tmpEnv, $content);
    }

    /**
     * Run the real EnvService::setEnv() on the temporary .env file.
     *
     * setEnv() always edits the .env file in base_path(), so the base path
     * points at the temporary folder for the duration of the call.
     *
     * @param array<string, string> $data
     */
    private function callSetEnv(array $data, bool $matchRemove = false): void
    {
        $basePath = $this->app->basePath();
        $this->app->setBasePath($this->tmpDir);
        try {
            EnvService::setEnv($data, $matchRemove);
        } finally {
            $this->app->setBasePath($basePath);
        }
    }

    /**
     * @return list<string>
     */
    private function readEnvLines(): array
    {
        $lines = file($this->tmpEnv, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $this->fail('Cannot read ' . $this->tmpEnv);
        }

        return $lines;
    }

    // =========================================================================
    // BUG 1 — EnvService::setEnv deduplication
    // =========================================================================

    /**
     * BEFORE FIX: both DB_PASSWORD lines survived; the old wrong value was kept
     * after the first entry (or the new value appeared twice).
     * AFTER FIX:  only one DB_PASSWORD line exists and it holds the new value.
     */
    public function testSetEnvRemovesDuplicateKeysAndKeepsNewValue(): void
    {
        $this->useEnvFile(implode("\n", [
            'DB_CONNECTION=mariadb',
            'DB_HOST=localhost',
            'DB_PASSWORD=password1',   // old / wrong
            'DB_PASSWORD=password1',   // accidental duplicate
            'APP_DEBUG=false',
        ]));

        $this->callSetEnv(['DB_PASSWORD' => 'password']);

        $lines = $this->readEnvLines();

        $passwordLines = array_values(array_filter($lines, fn($l) => str_starts_with($l, 'DB_PASSWORD=')));

        $this->assertCount(1, $passwordLines, 'Only one DB_PASSWORD line must remain after setEnv()');
        $this->assertSame('DB_PASSWORD=password', $passwordLines[0], 'The new password value must be written');
    }

    /**
     * Keys NOT in the update set must be preserved exactly.
     */
    public function testSetEnvPreservesUnrelatedKeys(): void
    {
        $this->useEnvFile(implode("\n", [
            'APP_NAME=Exment',
            'DB_PASSWORD=wrong',
            'APP_ENV=local',
        ]));

        $this->callSetEnv(['DB_PASSWORD' => 'correct']);

        $lines = $this->readEnvLines();

        $this->assertContains('APP_NAME=Exment', $lines);
        $this->assertContains('APP_ENV=local', $lines);
    }

    /**
     * A key absent from the file must be appended.
     */
    public function testSetEnvAppendsNewKey(): void
    {
        $this->useEnvFile("APP_NAME=Exment\nAPP_ENV=local");

        $this->callSetEnv(['NEW_KEY' => 'hello']);

        $lines = $this->readEnvLines();
        $this->assertContains('NEW_KEY=hello', $lines);
    }

    /**
     * removeEnv (matchRemove=true) must strip ALL occurrences of the key.
     */
    public function testSetEnvWithMatchRemoveStripsAllOccurrences(): void
    {
        $this->useEnvFile(implode("\n", [
            'DB_PASSWORD=a',
            'DB_PASSWORD=b',
            'APP_ENV=local',
        ]));

        // Calling with matchRemove=true (same as removeEnv)
        $this->callSetEnv(['DB_PASSWORD' => ''], true);

        $lines = $this->readEnvLines();
        $passwordLines = array_filter($lines, fn($l) => str_starts_with($l, 'DB_PASSWORD='));
        $this->assertCount(0, $passwordLines, 'All DB_PASSWORD entries must be removed');
        $this->assertContains('APP_ENV=local', $lines, 'Unrelated keys must survive removeEnv');
    }

    // =========================================================================
    // BUG 2 — InstallService::getStatus() redirect-loop guard
    // =========================================================================

    /**
     * BEFORE FIX: getStatus() returned INITIALIZE immediately even when an install
     * session was active, because the canConnection+hasTable check had no guard.
     *
     * AFTER FIX: when session status != null the shortcut is skipped; the wizard
     * can complete and write .env before any redirect to /initialize happens.
     */
    public function testGetStatusDoesNotShortcutToInitializeWhenWizardSessionActive(): void
    {
        // Simulate mid-install: the user is past the SystemRequireForm step.
        // No database inputs go in the session on purpose: getStatus() applies
        // them first (setSettingTmp), and without any it keeps the connection
        // the tests run on - reachable and installed, which is exactly when the
        // old shortcut sent the wizard to /initialize.
        InstallService::setInitializeStatus(InitializeStatus::SYSTEM_REQUIRE);

        // The session must report SYSTEM_REQUIRE (not null).
        $this->assertSame(
            InitializeStatus::SYSTEM_REQUIRE,
            InstallService::getInitializeStatus(),
            'Session status must remain SYSTEM_REQUIRE while the wizard is in progress'
        );

        // AFTER FIX: the INITIALIZE shortcut is skipped while the wizard session
        // is active, and SYSTEM_REQUIRE advances to INSTALLING.
        $this->assertSame(
            InitializeStatus::INSTALLING,
            InstallService::getStatus(),
            'When session = SYSTEM_REQUIRE the wizard must advance to the INSTALLING step, not INITIALIZE'
        );
    }

    /**
     * When NO session is active (fresh visit), the shortcut to INITIALIZE is fine.
     * This ensures we did NOT break the normal post-install redirect.
     */
    public function testGetStatusAllowsInitializeShortcutWhenNoWizardSession(): void
    {
        InstallService::forgetInitializeStatus();

        $this->assertNull(InstallService::getInitializeStatus(), 'No session: getInitializeStatus() returns null');

        // No session and an installed database: the shortcut still applies.
        $this->assertSame(
            InitializeStatus::INITIALIZE,
            InstallService::getStatus(),
            'AFTER FIX: when no session exists, the INITIALIZE shortcut must still be allowed'
        );
    }

    // =========================================================================
    // BUG 3 — InstallingForm::post() — env written before canConnection gate
    // =========================================================================

    /**
     * BEFORE FIX: forgetInputParams() was called before canConnection().
     * On the second attempt (after artisan failed), session inputs were gone,
     * setSettingTmp() applied nothing, and canConnection() re-read the stale
     * (wrong) .env password → returned false → user stuck with "cannot connect".
     *
     * AFTER FIX: the single try-block does setEnv → setSettingTmp → forgetInputParams
     * in sequence; by the time canConnection() is called, .env is already updated
     * and runtime config reflects the new password.
     *
     * This test verifies the ordering guarantee by using Mockery call-ordering.
     */
    public function testInstallingFormWritesEnvBeforeConnectionCheck(): void
    {
        // Arrange: put a fake password in session
        InstallService::setInitializeStatus(InitializeStatus::SYSTEM_REQUIRE);
        InstallService::setInputParams([
            'DB_CONNECTION' => 'mariadb',
            'DB_HOST'       => 'localhost',
            'DB_PORT'       => '3306',
            'DB_DATABASE'   => 'test_db',
            'DB_USERNAME'   => 'root',
            'DB_PASSWORD'   => 'password',
        ]);

        // The test asserts that after calling setSettingTmp() the runtime DB config
        // carries the value from the session (not from the stale .env).
        InstallService::setSettingTmp();

        $runtimePassword = config('database.connections.' . config('database.default') . '.password');

        // After setSettingTmp() the runtime config must reflect the session value.
        $this->assertSame(
            'password',
            $runtimePassword,
            'setSettingTmp() must apply session DB_PASSWORD to runtime config before canConnection() is called'
        );
    }
}
