<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\Define;
use PHPUnit\Framework\TestCase;

/**
 * Install screen PHP version range (Define::PHP_VERSION, used by DatabaseForm::checkPhpVersion).
 */
class InstallPhpVersionTest extends TestCase
{
    /**
     * Same comparison as DatabaseForm::checkPhpVersion.
     */
    protected function isAllowed(string $version): bool
    {
        return version_compare($version, Define::PHP_VERSION[0]) >= 0
            && version_compare($version, Define::PHP_VERSION[1]) < 0;
    }

    public function testSupportedVersionsAreAllowed(): void
    {
        foreach (['8.2.0', '8.2.12', '8.3.14', '8.4.0', '8.4.27'] as $version) {
            $this->assertTrue($this->isAllowed($version), "PHP {$version} should be allowed");
        }
    }

    public function testUnsupportedVersionsAreRejected(): void
    {
        foreach (['8.0.30', '8.1.34', '8.5.0'] as $version) {
            $this->assertFalse($this->isAllowed($version), "PHP {$version} should be rejected");
        }
    }

    public function testMinMatchesComposerRequirement(): void
    {
        $json = file_get_contents(__DIR__ . '/../../composer.json');
        $this->assertIsString($json);
        $composer = json_decode($json, true);
        if (preg_match('/^\^(\d+\.\d+)/', $composer['require']['php'], $m) !== 1) {
            $this->fail('composer.json must require php as ^X.Y, found ' . $composer['require']['php']);
        }

        $this->assertSame($m[1] . '.0', Define::PHP_VERSION[0]);
    }
}
