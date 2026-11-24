<?php

namespace Exceedone\Exment\Tests\Unit;

/**
 * The "QrCode" alias (composer.json extra.laravel.aliases) still works (#1902).
 *
 * PHPStan does not read this file (phpstan.neon.dist excludePaths): after it reads a static
 * call through a Laravel alias, it reads the facade behind it (QrCodeFacade) wrongly and
 * reports "return statement is missing" in getFacadeAccessor(). That happened only when the
 * same worker read this file first, so CI failed now and then.
 */
class QrCodeAliasTest extends UnitTestBase
{
    public function testAlias(): void
    {
        $this->assertStringContainsString('<svg', \QrCode::format('svg')->generate('alias'));
    }
}
