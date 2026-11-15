<?php

namespace Exceedone\Exment\Tests\Unit\Pure;

use PHPUnit\Framework\TestCase;

/**
 * Lightweight base class for "pure" unit tests.
 *
 * These tests do NOT boot the Laravel application, connect to a database,
 * or use the service container. They exercise pure logic only (e.g. the
 * passes() methods of Validator rules), so they run in milliseconds.
 *
 * The Exment global helpers (isMatchString, is_list, rmcomma, ...) live in
 * src/Services/Helpers.php and are normally required by ExmentServiceProvider
 * at boot time. Since we skip booting, we require that file once here.
 * The lower-level *_ex helpers (strcmp_ex, ...) come from laravel-admin and
 * are already autoloaded via composer "files".
 */
abstract class PureTestBase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once __DIR__ . '/../../../src/Services/Helpers.php';
    }
}
