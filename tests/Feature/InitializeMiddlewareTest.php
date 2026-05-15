<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;

/**
 * Test for Initialize middleware behavior when EXMENT_INITIALIZE=1 and DB connection fails.
 *
 * Bug: Every request logs "Error fetching table names" and redirects to install page (wrong UX).
 * Expected fix: Return HTTP 503 instead of redirecting to install.
 */
class InitializeMiddlewareTest extends FeatureTestBase
{
    /** @var string|false */
    private $originalExmentInitialize;

    protected function setUp(): void
    {
        parent::setUp();
        // Save original value
        $this->originalExmentInitialize = getenv('EXMENT_INITIALIZE');
    }

    protected function tearDown(): void
    {
        // Restore EXMENT_INITIALIZE env
        if ($this->originalExmentInitialize === false) {
            putenv('EXMENT_INITIALIZE');
            unset($_ENV['EXMENT_INITIALIZE'], $_SERVER['EXMENT_INITIALIZE']);
        } else {
            putenv('EXMENT_INITIALIZE=' . $this->originalExmentInitialize);
            $_ENV['EXMENT_INITIALIZE'] = $this->originalExmentInitialize;
            $_SERVER['EXMENT_INITIALIZE'] = $this->originalExmentInitialize;
        }

        // Restore System cache so DB connection check works normally for other tests
        System::clearCache();

        parent::tearDown();
    }

    /**
     * When EXMENT_INITIALIZE=1 and DB connection fails,
     * the system should return HTTP 503 (not redirect to install page).
     *
     * CURRENTLY FAILS (returns 302 redirect to install).
     * Should PASS after fix in Initialize.php.
     */
    public function testDbFailWithExmentInitializeShouldReturn503(): void
    {
        // --- Arrange ---
        // 1. Set EXMENT_INITIALIZE=1 (simulate already-installed system)
        putenv('EXMENT_INITIALIZE=1');
        $_ENV['EXMENT_INITIALIZE'] = '1';
        $_SERVER['EXMENT_INITIALIZE'] = '1';

        // 2. Force canConnection() to return false by pre-populating the System request session cache.
        //    System::cache() checks requestSession first; if set, skips the real DB call.
        //    This avoids TCP timeouts while still triggering the "DB unavailable" code path.
        System::setRequestSession(Define::SYSTEM_KEY_SESSION_CAN_CONNECTION_DATABASE, false);

        // 3. Also pre-populate hasTable cache so the Doctrine DBAL call is skipped
        //    (avoids logging "Error fetching table names" during the test itself).
        System::setRequestSession(Define::SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES, []);

        // --- Act ---
        $response = $this->get('/admin/dashboard');

        // --- Assert ---
        // Expected AFTER fix: 503 Service Unavailable
        // Current behavior (before fix): 302 redirect to /admin/install  ← FAILS HERE
        $response->assertStatus(503);
    }

    /**
     * When EXMENT_INITIALIZE is NOT set and DB connection fails,
     * the system should redirect to install page (correct behavior, no regression).
     */
    public function testDbFailWithoutExmentInitializeShouldRedirectToInstall(): void
    {
        // --- Arrange ---
        // Ensure EXMENT_INITIALIZE is not set
        putenv('EXMENT_INITIALIZE');
        unset($_ENV['EXMENT_INITIALIZE'], $_SERVER['EXMENT_INITIALIZE']);

        // Force canConnection() and hasTable() to return failure
        System::setRequestSession(Define::SYSTEM_KEY_SESSION_CAN_CONNECTION_DATABASE, false);
        System::setRequestSession(Define::SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES, []);

        // --- Act ---
        $response = $this->get('/admin/dashboard');

        // --- Assert ---
        // Should redirect to install (correct behavior when not yet initialized)
        $response->assertRedirect('/admin/install');
    }
}
