<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;

/**
 * Base class for security regression tests (proving the commit b26a6dd fixes and the
 * JVN security fixes work at runtime).
 *
 * DATA SAFETY:
 *  - Uses DatabaseTransactions => every INSERT/UPDATE is ROLLED BACK in tearDown.
 *  - Does NOT use DatabaseMigrations / migrate:fresh (that would WIPE the dev DB).
 *  - DDL needs use CREATE TEMPORARY TABLE (no implicit commit, session-scoped).
 *
 * Running: the whole Security folder is safe (these tests never migrate:fresh):
 *   vendor/bin/phpunit exment/tests/Unit/Security/
 * or a single file, e.g.:
 *   vendor/bin/phpunit exment/tests/Unit/Security/Bug1GroupKeySqlInjectionFixedTest.php
 * Do NOT run the entire Unit suite, which pulls in ExmentTestCase -> DatabaseMigrations
 * -> migrate:fresh -> dev data loss.
 *
 * Covered: Bug1GroupKeySqlInjectionFixedTest, Bug2TemplateDeleteAuthFixedTest,
 * Bug3FileAuthzConstraintTest, Bug5FileDeleteArraySpliceFixedTest,
 * JvnPluginPublicFileTraversalFixedTest, JvnFieldTypeFlashMessageXssFixedTest,
 * JvnColumnItemXssFixedTest, JvnNotifyNavbarLabelXssFixedTest,
 * JvnPublicFormCustomCssInjectionFixedTest, JvnUrlLinkXssHardeningTest.
 */
abstract class SecurityRegressionTestCase extends TestCase
{
    use DatabaseTransactions;

    /** Read the real package source (independent of whether ./exment or vendor/... symlink is used). */
    protected function exmentSource(string $relativeFromSrc): string
    {
        $path = dirname(__DIR__, 3) . '/src/' . ltrim($relativeFromSrc, '/');
        $this->assertFileExists($path, "Source not found: $path");
        return (string) file_get_contents($path);
    }

    /**
     * Make System::initialized() report an installed system for the current test.
     *
     * Several guards (TemplateController::searchTemplate / ::delete) only authorize once
     * the system is installed, because the install wizard runs anonymously before that.
     * A test database whose `system` table has not been migrated makes initialized()
     * false, the guard is skipped BY DESIGN, and the assertion silently stops testing
     * anything. Writing the value into System's request-session cache (which
     * System::cache() consults before the database) sets the precondition without
     * needing the table.
     */
    protected function forceSystemInitialized(): void
    {
        System::setRequestSession(sprintf(Define::SYSTEM_KEY_SESSION_SYSTEM_CONFIG, 'initialized'), true);
    }

    protected function tearDown(): void
    {
        // System::$requestSession is static: a forced value would otherwise change the
        // behaviour of every later test in the same process.
        System::clearRequestSession(sprintf(Define::SYSTEM_KEY_SESSION_SYSTEM_CONFIG, 'initialized'));

        parent::tearDown();
    }
}
