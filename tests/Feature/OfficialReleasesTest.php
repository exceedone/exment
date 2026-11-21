<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Services\PluginDistribution\BundledPlugins;
use Exceedone\Exment\Services\PluginDistribution\ReleaseVerifier;

/**
 * The free official plugins bundled with Exment 7.2 (ISS00419), as they are
 * released: the records of exceedone/exment-plugins-release 7.2.0 are checked
 * with the keys Exment trusts (no key of the tests).
 *
 * The zips are checked too where the releases are found: the package in
 * vendor (composer), or the folder EXMENT_TEST_OFFICIAL_RELEASES (CI checks
 * out exment-plugins-release there).
 */
class OfficialReleasesTest extends FeatureTestBase
{
    /**
     * The releases of exment-plugins-release 7.2.0.
     */
    public const RELEASES = [
        'ExmentPM' => ['version' => '1.7.1', 'uuid' => '00da3e93-1a24-4f44-b993-d61367e43649'],
        'ExmentITSupport' => ['version' => '1.0.6', 'uuid' => '6b1f2c0e-7a4d-4e8b-9c35-1d2e8f4a7b90'],
        'ExmentGroupware' => ['version' => '0.4.1', 'uuid' => '9eef3996-557e-4a1e-990d-9d5302fa35a8'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        ReleaseVerifier::resetTestKeys();
    }

    protected function tearDown(): void
    {
        config(['exment.bundled_plugins_path' => null]);
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    protected function record(string $name): array
    {
        $file = __DIR__ . '/../data/plugin_distribution/official-7.2.0/' . $name . '-' . static::RELEASES[$name]['version'] . '.json';
        $record = json_decode(strval(file_get_contents($file)), true);
        $this->assertIsArray($record, $file);
        return $record;
    }

    public function testTheRecordsAreSignedWithTheTrustedKey(): void
    {
        foreach (static::RELEASES as $name => $release) {
            $record = $this->record($name);
            $this->assertSame($name, $record['name']);
            $this->assertSame($release['version'], $record['version']);
            $this->assertSame($release['uuid'], $record['uuid']);
            $this->assertSame('release-2026-1', $record['key_id']);
            $this->assertNotNull(ReleaseVerifier::publicKey(strval($record['key_id'])));
            ReleaseVerifier::verifyRecord($record);
            $this->assertTrue(ReleaseVerifier::fits($record, '7.2.0'), $name . ' fits 7.2.0');
            $this->assertFalse(ReleaseVerifier::fits($record, '7.3.0'), $name . ' is not for 7.3.0');
        }
    }

    public function testAChangedRecordIsRefused(): void
    {
        $record = $this->record('ExmentITSupport');
        $record['exment_requires'] = '>=7.0.0';
        $this->expectException(\Exceedone\Exment\Services\PluginDistribution\ReleaseException::class);
        ReleaseVerifier::verifyRecord($record);
    }

    public function testThePackageInVendorIsFound(): void
    {
        if (!class_exists(\Composer\InstalledVersions::class) || !\Composer\InstalledVersions::isInstalled(BundledPlugins::PACKAGE)) {
            $this->markTestSkipped(BundledPlugins::PACKAGE . ' is not installed by composer here');
        }
        config(['exment.bundled_plugins_path' => null]);
        $path = BundledPlugins::path();
        $this->assertNotNull($path);
        $this->assertStringEndsWith('releases', str_replace('\\', '/', strval($path)));
        $this->assertTheThreeReleases();
    }

    public function testTheReleasesCheckedOut(): void
    {
        $dir = getenv('EXMENT_TEST_OFFICIAL_RELEASES');
        if (!is_string($dir) || $dir === '' || !is_dir($dir)) {
            $this->markTestSkipped('EXMENT_TEST_OFFICIAL_RELEASES is not set');
        }
        config(['exment.bundled_plugins_path' => $dir]);
        $this->assertTheThreeReleases();
    }

    /**
     * The three are found, and their zips match their records (sha256, size,
     * and config.json of the zip).
     */
    protected function assertTheThreeReleases(): void
    {
        $releases = BundledPlugins::releases();
        foreach (static::RELEASES as $name => $release) {
            $this->assertArrayHasKey($release['uuid'], $releases, $name . ' is found and trusted');
            $found = $releases[$release['uuid']];
            $this->assertSame($release['version'], $found['record']['version']);
            // again without the cache of the check
            ReleaseVerifier::verify($found['record'], $found['zip']);
            $this->assertSame($this->record($name), $found['record'], $name . ': the record is the one of 7.2.0');
        }
    }
}
