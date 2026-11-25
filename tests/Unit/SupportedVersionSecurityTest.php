<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\SystemRequire\Security\SecurityCheck;
use Exceedone\Exment\Services\SystemRequire\Security\SupportedVersionSecurity;

/**
 * The security check says when the support of PHP ends or has ended (ISS00171) and when the
 * database is older than the recommended version (ISS00216). Nothing is stopped.
 */
class SupportedVersionSecurityTest extends UnitTestBase
{
    protected function tearDown(): void
    {
        SupportedVersionSecurity::$phpVersion = null;
        SupportedVersionSecurity::$today = null;
        SupportedVersionSecurity::$database = null;
        parent::tearDown();
    }

    /**
     * @param array{type: string, version: string}|false $database
     */
    protected function at(string $php, string $today, $database = false): SupportedVersionSecurity
    {
        SupportedVersionSecurity::$phpVersion = $php;
        SupportedVersionSecurity::$today = $today;
        SupportedVersionSecurity::$database = $database;
        return new SupportedVersionSecurity();
    }

    public function testPhpWhileItIsSupported(): void
    {
        $item = $this->at('8.3', '2026-11-25', ['type' => 'mysql', 'version' => '8.0.36']);
        $this->assertSame(SystemRequireResult::OK, $item->checkResult());
        $this->assertSame([], $item->findings());
        $this->assertNull($item->getFixText());
    }

    public function testPhp82NearTheEndOfItsSupport(): void
    {
        $item = $this->at('8.2', '2026-11-25');
        $this->assertSame(SystemRequireResult::WARNING, $item->checkResult());
        $this->assertCount(1, $item->findings());
        $this->assertStringContainsString('2026-12-31', $item->findings()[0]);
        $this->assertNotNull($item->getFixText());

        // months before the end: nothing said
        $this->assertSame(SystemRequireResult::OK, $this->at('8.2', '2026-06-01')->checkResult());
    }

    public function testPhpAfterTheEndOfItsSupport(): void
    {
        $item = $this->at('8.2', '2027-01-01');
        $this->assertSame(SystemRequireResult::NG, $item->checkResult());
        $this->assertStringContainsString('8.2', $item->findings()[0]);
        $this->assertSame(SystemRequireResult::NG, $this->at('8.1', '2026-11-25')->checkResult());
    }

    public function testADatabaseOlderThanRecommended(): void
    {
        foreach ([
            ['mysql', '5.7.44', true],
            ['mysql', '8.0.36', false],
            ['mysql', '8.4.3', false],
            ['mariadb', '10.6.12-MariaDB-log', true],
            ['mariadb', '10.11.6-MariaDB', false],
            ['mariadb', '11.4.2-MariaDB', false],
            ['sqlsrv', '12.0.6024.0', true],
            ['sqlsrv', '15.0.2000.5', false],
        ] as [$type, $version, $old]) {
            $item = $this->at('8.3', '2026-11-25', ['type' => $type, 'version' => $version]);
            $this->assertSame($old ? SystemRequireResult::WARNING : SystemRequireResult::OK, $item->checkResult(), "$type $version");
            $this->assertSame($old ? 1 : 0, count($item->findings()), "$type $version");
        }
    }

    public function testWithoutADatabaseOnlyPhpIsAsked(): void
    {
        $item = $this->at('8.3', '2026-11-25', false);
        $this->assertSame(SystemRequireResult::OK, $item->checkResult());
        $this->assertStringStartsWith('PHP 8.3', strval($item->getResultText()));
    }

    public function testOnTheSecurityCheckAndAtTheSetup(): void
    {
        $this->assertContains(SupportedVersionSecurity::class, SecurityCheck::ITEMS);
        $this->assertContains('supported_versions', SecurityCheck::INSTALL_ITEMS);
        foreach (['label', 'explain', 'php_until', 'php_ended', 'php_ending', 'database_old', 'warning', 'fix'] as $key) {
            foreach (['en', 'ja', 'de', 'es', 'ko', 'vi', 'zh_CN'] as $locale) {
                $this->assertStringStartsNotWith('exment::', strval(trans('exment::exment.security_check.item.supported_versions.' . $key, [], $locale)), "$key in $locale");
            }
        }
    }
}
