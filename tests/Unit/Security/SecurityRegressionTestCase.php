<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

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
}
