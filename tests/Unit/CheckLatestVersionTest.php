<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Exceedone\Exment\Exment;
use Exceedone\Exment\Enums\SystemVersion;

/**
 * Guard tests for the version-comparison logic in Exment::checkLatestVersion().
 *
 * Regression fixed by commit 8b87e0d: versions were compared / sorted as strings, so
 * "6.2.10" ranked BELOW "6.2.9" and an installed version newer than packagist raised a
 * false "update available" notice. These tests lock the semantic behaviour and the
 * hardening around null / dev / branch-alias / pre-release versions.
 */
class CheckLatestVersionTest extends TestCase
{
    private function exment(): Exment
    {
        return new Exment();
    }

    // ---- classifyVersion -------------------------------------------------

    /**
     * @return void
     */
    public function testCurrentEqualsLatestIsLatest()
    {
        $this->assertSame(SystemVersion::LATEST, $this->exment()->classifyVersion('6.2.10', '6.2.10'));
    }

    /**
     * @return void
     */
    public function testCurrentOlderThanLatestHasNext()
    {
        $this->assertSame(SystemVersion::HAS_NEXT, $this->exment()->classifyVersion('6.2.10', '6.2.9'));
    }

    /**
     * The exact regression: installed version is NEWER than packagist latest -> must be LATEST,
     * not a false "update available".
     *
     * @return void
     */
    public function testCurrentNewerThanLatestIsLatest()
    {
        $this->assertSame(SystemVersion::LATEST, $this->exment()->classifyVersion('6.2.9', '6.2.10'));
    }

    /**
     * @return void
     */
    public function testDoubleDigitOrdering()
    {
        // string compare would rank 6.2.10 below 6.2.9; semantic compare must not.
        $this->assertSame(SystemVersion::LATEST, $this->exment()->classifyVersion('6.2.9', '6.2.10'));
        $this->assertSame(SystemVersion::HAS_NEXT, $this->exment()->classifyVersion('6.2.10', '6.2.2'));
    }

    /**
     * @return void
     */
    public function testVPrefixIsIgnored()
    {
        $this->assertSame(SystemVersion::LATEST, $this->exment()->classifyVersion('v6.2.10', '6.2.10'));
        $this->assertSame(SystemVersion::LATEST, $this->exment()->classifyVersion('6.2.10', 'v6.2.10'));
    }

    /**
     * @dataProvider devCurrentProvider
     *
     * @return void
     */
    public function testDevAndBranchAliasCurrentsAreDev(string $current)
    {
        $this->assertSame(SystemVersion::DEV, $this->exment()->classifyVersion('6.2.10', $current));
    }

    /**
     * @return array<int, array{string}>
     */
    public static function devCurrentProvider(): array
    {
        return [
            ['dev-main'],
            ['dev-master'],
            ['6.x-dev'],
            ['6.2.x-dev'],
            ['9999999-dev'],
        ];
    }

    /**
     * null / empty must classify as ERROR and must NOT emit a deprecation
     * (regression: trim(null, 'v') deprecates on PHP 8.1+ when outside_api is off / offline).
     *
     * @dataProvider emptyVersionProvider
     *
     * @return void
     */
    public function testNullOrEmptyIsErrorWithoutDeprecation(?string $latest, ?string $current)
    {
        set_error_handler(function ($no, $str) {
            throw new \ErrorException($str, 0, $no);
        });
        try {
            $result = $this->exment()->classifyVersion($latest, $current);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(SystemVersion::ERROR, $result);
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function emptyVersionProvider(): array
    {
        return [
            'latest null (outside_api off)' => [null, '6.2.10'],
            'current null'                  => ['6.2.10', null],
            'both null'                     => [null, null],
            'latest empty string'           => ['', '6.2.10'],
        ];
    }

    // ---- isDevVersion ----------------------------------------------------

    /**
     * @return void
     */
    public function testIsDevVersion()
    {
        $ex = $this->exment();
        $this->assertTrue($ex->isDevVersion('dev-main'));
        $this->assertTrue($ex->isDevVersion('6.x-dev'));
        $this->assertTrue($ex->isDevVersion('6.2.x-dev'));
        $this->assertTrue($ex->isDevVersion('9999999-dev'));
        $this->assertFalse($ex->isDevVersion('6.2.10'));
        $this->assertFalse($ex->isDevVersion('v6.2.10'));
        $this->assertFalse($ex->isDevVersion(null));
    }

    // ---- pickLatestStableVersion -----------------------------------------

    /**
     * @return void
     */
    public function testPickLatestStableVersionSemanticOrder()
    {
        // "6.2.10" must win over "6.2.9" (a string sort would pick 6.2.9).
        $packages = [
            '6.2.8'  => ['version_normalized' => '6.2.8.0'],
            '6.2.10' => ['version_normalized' => '6.2.10.0'],
            '6.2.9'  => ['version_normalized' => '6.2.9.0'],
        ];
        $this->assertSame('6.2.10', $this->exment()->pickLatestStableVersion($packages));
    }

    /**
     * @return void
     */
    public function testPickLatestStableVersionSkipsDevAndPreRelease()
    {
        $packages = [
            'dev-master'  => ['version_normalized' => '9999999-dev'],
            '6.x-dev'     => ['version_normalized' => '6.9999999.9999999.9999999-dev'],
            '6.3.0-beta1' => ['version_normalized' => '6.3.0.0-beta1'],
            '6.3.0-RC1'   => ['version_normalized' => '6.3.0.0-RC1'],
            '6.2.10'      => ['version_normalized' => '6.2.10.0'],
            '6.2.9'       => ['version_normalized' => '6.2.9.0'],
        ];
        // highest STABLE is 6.2.10, even though 6.3.0 betas/RC sort higher.
        $this->assertSame('6.2.10', $this->exment()->pickLatestStableVersion($packages));
    }

    /**
     * "-patch" / "-pl" are STABLE in composer (not pre-releases), so a patch release that is
     * higher than the base release must be selectable (regression: a "strip any '-'" filter
     * would wrongly skip it and could yield null -> ERROR).
     *
     * @return void
     */
    public function testPickLatestStableVersionKeepsPatchRelease()
    {
        $packages = [
            '6.2.10'        => ['version_normalized' => '6.2.10.0'],
            '6.2.10-patch1' => ['version_normalized' => '6.2.10.0-patch1'],
        ];
        $this->assertSame('6.2.10-patch1', $this->exment()->pickLatestStableVersion($packages));

        // and if a patch release is the only entry, it must NOT collapse to null
        $this->assertSame('6.2.10-patch1', $this->exment()->pickLatestStableVersion([
            '6.2.10-patch1' => ['version_normalized' => '6.2.10.0-patch1'],
        ]));
    }

    /**
     * @return void
     */
    public function testPickLatestStableVersionAllNonStableReturnsNull()
    {
        $packages = [
            'dev-master'  => ['version_normalized' => '9999999-dev'],
            '6.3.0-beta1' => ['version_normalized' => '6.3.0.0-beta1'],
        ];
        $this->assertNull($this->exment()->pickLatestStableVersion($packages));
    }

    /**
     * A package entry without version_normalized must not emit a deprecation
     * (regression: version_compare(null, ...) deprecates on PHP 8.1+).
     *
     * @return void
     */
    public function testPickLatestStableVersionMissingNormalizedNoDeprecation()
    {
        set_error_handler(function ($no, $str) {
            throw new \ErrorException($str, 0, $no);
        });
        try {
            $packages = [
                '6.2.10' => ['version_normalized' => '6.2.10.0'],
                '6.2.9'  => [], // missing version_normalized
            ];
            $result = $this->exment()->pickLatestStableVersion($packages);
        } finally {
            restore_error_handler();
        }
        $this->assertSame('6.2.10', $result);
    }

    /**
     * @return void
     */
    public function testPickLatestStableVersionEmptyReturnsNull()
    {
        $this->assertNull($this->exment()->pickLatestStableVersion([]));
        $this->assertNull($this->exment()->pickLatestStableVersion(null));
    }
}
