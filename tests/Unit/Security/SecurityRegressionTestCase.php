<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Base class for security regression tests (proving the commit b26a6dd fixes work at runtime).
 *
 * DATA SAFETY:
 *  - Uses DatabaseTransactions => every INSERT/UPDATE is ROLLED BACK in tearDown.
 *  - Does NOT use DatabaseMigrations / migrate:fresh (that would WIPE the dev DB).
 *  - DDL needs use CREATE TEMPORARY TABLE (no implicit commit, session-scoped).
 *
 * Run each file INDIVIDUALLY (do NOT run the whole Unit suite: it pulls in the
 * ExmentTestCase tests -> DatabaseMigrations -> migrate:fresh -> data loss):
 *
 *   vendor/bin/phpunit exment/tests/Unit/Security/Bug1GroupKeySqlInjectionFixedTest.php
 *   vendor/bin/phpunit exment/tests/Unit/Security/Bug2TemplateDeleteAuthFixedTest.php
 *   vendor/bin/phpunit exment/tests/Unit/Security/Bug3FileAuthzConstraintTest.php
 *   vendor/bin/phpunit exment/tests/Unit/Security/Bug5FileDeleteArraySpliceFixedTest.php
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
