<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\PluginDistribution\BundledPlugins;
use Exceedone\Exment\Services\PluginDistribution\ReleaseException;
use Exceedone\Exment\Services\PluginDistribution\ReleaseVerifier;
use Exceedone\Exment\Services\PluginDistribution\SiteId;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * The plugins bundled with Exment (ISS00419) and the check of the signed
 * releases (ISS00421 "the rules of the signatures"). The sample and the test
 * key are those of ~/work/release-sign-sample (test-release-1: never trusted
 * out of the tests).
 */
class BundledPluginsTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    public const SAMPLE_UUID = '2f3c8d0e-6a51-4b7e-9c2d-5e8f1a0b3c47';
    public const TEST_KEY_ID = 'test-release-1';
    public const TEST_PUBLIC_KEY = 'XosHheXT+5cF3fcUZ16LVVzeOiwih3D/BvbgxYFDsYM=';

    protected string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Storage::fake(Define::DISKNAME_ADMIN);
        ReleaseVerifier::resetTestKeys();
        ReleaseVerifier::trustForTests(static::TEST_KEY_ID, static::TEST_PUBLIC_KEY);
        $this->dir = sys_get_temp_dir() . '/exment_bundled_' . uniqid();
        File::makeDirectory($this->dir, 0777, true);
        config(['exment.bundled_plugins_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        ReleaseVerifier::resetTestKeys();
        File::deleteDirectory($this->dir);
        // the files of the sample plugin, when it was installed
        foreach ([Define::DISKNAME_PLUGIN_LOCAL, Define::DISKNAME_PLUGIN_SYNC] as $disk) {
            try {
                Storage::disk($disk)->deleteDirectory('ExmentSignSample');
            } catch (\Throwable $e) {
                // not there
            }
        }
        parent::tearDown();
    }

    protected function sample(string $name): string
    {
        return __DIR__ . '/../data/plugin_distribution/' . $name;
    }

    /**
     * @return array<string, mixed>
     */
    protected function sampleRecord(): array
    {
        return json_decode(strval(file_get_contents($this->sample('ExmentSignSample-1.0.0.json'))), true);
    }

    /**
     * @return non-empty-string
     */
    protected function testSecretKey(): string
    {
        $pair = sodium_crypto_sign_seed_keypair(hash('sha256', 'exment test release key 1 - do not trust', true));
        $secret = sodium_crypto_sign_secretkey($pair);
        assert($secret !== '');
        return $secret;
    }

    /**
     * A release of the sample plugin of another version (or uuid), signed with the test key.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function makeRelease(string $version, string $uuid = self::SAMPLE_UUID, string $name = 'ExmentSignSample', ?string $dir = null): array
    {
        $dir = $dir ?? $this->dir;
        $zipPath = $dir . '/' . $name . '-' . $version . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('Plugin.php', "<?php\nnamespace App\\Plugins\\{$name};\n\nuse Exceedone\\Exment\\Services\\Plugin\\PluginPageBase;\n\nclass Plugin extends PluginPageBase\n{\n}\n");
        $zip->addFromString('config.json', strval(json_encode([
            'plugin_name' => $name, 'uuid' => $uuid, 'plugin_view_name' => 'Exment Sign Sample', 'description' => 'sample',
            'author' => 'Exceed One', 'version' => $version, 'plugin_type' => 'page', 'uri' => 'exment_sign_sample',
        ])));
        $zip->close();
        $record = [
            'format' => ReleaseVerifier::FORMAT,
            'uuid' => $uuid,
            'name' => $name,
            'version' => $version,
            'exment_requires' => '>=6.2',
            'php_requires' => '>=8.1',
            'sha256' => strval(hash_file('sha256', $zipPath)),
            'size' => intval(filesize($zipPath)),
            'released_at' => '2026-11-21T07:00:00Z',
            'key_id' => static::TEST_KEY_ID,
        ];
        $record['signature'] = base64_encode(sodium_crypto_sign_detached(ReleaseVerifier::canonical($record), $this->testSecretKey()));
        file_put_contents(substr($zipPath, 0, -4) . '.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return [$record, $zipPath];
    }

    public function testTheSampleOfTheRules(): void
    {
        $record = $this->sampleRecord();
        $zip = $this->sample('ExmentSignSample-1.0.0.zip');
        // the text signed is that of the rules
        $this->assertSame(
            '{"exment_requires":">=6.2 <8.0","format":"exment-plugin-release/1","key_id":"test-release-1","name":"ExmentSignSample","php_requires":">=8.1","released_at":"2026-11-21T07:00:00Z","sha256":"79a1767fba65406c753d1ae49f922a3ea6a82ff8ba387a98a70d65e03d139e04","size":512,"uuid":"2f3c8d0e-6a51-4b7e-9c2d-5e8f1a0b3c47","version":"1.0.0"}',
            ReleaseVerifier::canonical($record)
        );
        ReleaseVerifier::verify($record, $zip, '7.1.2');
        // the order of the keys does not matter
        ReleaseVerifier::verifyRecord(array_reverse($record, true));
        // a field not known is signed too, and passes when it was signed
        $this->assertTrue(ReleaseVerifier::fits($record, '7.1.2'));
        $this->assertFalse(ReleaseVerifier::fits($record, '8.0.0'));
        $this->assertTrue(ReleaseVerifier::fits($record, 'dev-master'), 'a version of the development is not checked');
    }

    /**
     * @return array<string, array{0: \Closure(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function badRecords(): array
    {
        return [
            'changed version' => [function ($r) {
                $r['version'] = '1.0.1';
                return $r;
            }, 'signature'],
            'unknown key' => [function ($r) {
                $r['key_id'] = 'release-2099-9';
                return $r;
            }, 'key'],
            'a key of another role' => [function ($r) {
                $r['key_id'] = 'catalog-2026-1';
                return $r;
            }, 'key'],
            'other format' => [function ($r) {
                $r['format'] = 'exment-catalog/1';
                return $r;
            }, 'format'],
            'a float' => [function ($r) {
                $r['size'] = 512.0;
                return $r;
            }, 'field'],
            'nested' => [function ($r) {
                $r['extra'] = ['a' => 1];
                return $r;
            }, 'value'],
            'no signature' => [function ($r) {
                unset($r['signature']);
                return $r;
            }, 'field'],
            'upper case uuid' => [function ($r) {
                $r['uuid'] = strtoupper($r['uuid']);
                return $r;
            }, 'field'],
        ];
    }

    /**
     * @dataProvider badRecords
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     */
    public function testBadRecords(\Closure $change, string $reason): void
    {
        try {
            ReleaseVerifier::verifyRecord($change($this->sampleRecord()));
            $this->fail('a bad record passed');
        } catch (ReleaseException $e) {
            $this->assertSame($reason, $e->reason());
        }
    }

    public function testTheZipMustMatch(): void
    {
        $record = $this->sampleRecord();
        $copy = $this->dir . '/copy.zip';
        copy($this->sample('ExmentSignSample-1.0.0.zip'), $copy);
        file_put_contents($copy, 'x', FILE_APPEND);
        try {
            ReleaseVerifier::verify($record, $copy, '7.1.2');
            $this->fail('a changed zip passed');
        } catch (ReleaseException $e) {
            $this->assertSame('zip', $e->reason());
        }
        // a test key is not trusted when it was not given (and never out of the tests)
        ReleaseVerifier::resetTestKeys();
        $this->assertNull(ReleaseVerifier::publicKey(static::TEST_KEY_ID));
        ReleaseVerifier::trustForTests('release-2026-1', static::TEST_PUBLIC_KEY);
        $this->assertNull(ReleaseVerifier::publicKey('release-2026-1'), 'a real key name is not taken from the tests');
    }

    public function testNothingIsInstalledUntilChosen(): void
    {
        $this->makeRelease('1.0.0');
        // a release that cannot be trusted is not listed
        file_put_contents($this->dir . '/Broken-1.0.0.json', '{"format": "exment-plugin-release/1"}');
        file_put_contents($this->dir . '/Broken-1.0.0.zip', 'x');

        $rows = BundledPlugins::rows();
        $this->assertCount(1, $rows);
        $this->assertSame(BundledPlugins::STATUS_NOT_INSTALLED, $rows[0]['status']);
        $this->assertNull(BundledPlugins::installed(static::SAMPLE_UUID), 'not installed by itself');

        // the page shows it, for the system administrators
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->get(admin_url('feature'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee(exmtrans('plugin_distribution.header'))->assertSee('Exment Sign Sample');

        // chosen: installed and active
        $this->post(admin_url('feature/bundled/' . static::SAMPLE_UUID . '/install'))->assertRedirect(admin_url('feature'));
        $plugin = BundledPlugins::installed(static::SAMPLE_UUID);
        $this->assertNotNull($plugin);
        $this->assertSame('1.0.0', $plugin->version);
        $this->assertTrue(boolval($plugin->active_flg));
        $this->assertSame(BundledPlugins::STATUS_INSTALLED, BundledPlugins::rows()[0]['status']);
    }

    public function testUpdateAndGoBack(): void
    {
        $this->makeRelease('1.0.0');
        BundledPlugins::install(static::SAMPLE_UUID);
        $plugin = BundledPlugins::installed(static::SAMPLE_UUID);
        $this->assertNotNull($plugin);
        // settings of the site stay over an update
        $plugin->setOption('all_user_enabled', '1');
        $plugin->save();
        $id = $plugin->id;

        // a newer bundled version: only shown, not installed by itself
        File::cleanDirectory($this->dir);
        $this->makeRelease('1.1.0');
        $row = BundledPlugins::rows()[0];
        $this->assertSame(BundledPlugins::STATUS_UPDATE, $row['status']);
        $this->assertSame('1.0.0', BundledPlugins::installed(static::SAMPLE_UUID)->version ?? null);

        BundledPlugins::install(static::SAMPLE_UUID);
        $plugin = BundledPlugins::installed(static::SAMPLE_UUID);
        $this->assertSame('1.1.0', $plugin->version ?? null);
        $this->assertSame($id, $plugin->id ?? null, 'the same plugin, updated in place');
        $this->assertSame('1.0.0', BundledPlugins::rows()[0]['previous']);

        // back to the version before
        BundledPlugins::rollback(static::SAMPLE_UUID);
        $this->assertSame('1.0.0', BundledPlugins::installed(static::SAMPLE_UUID)->version ?? null);
        $this->assertSame(BundledPlugins::STATUS_UPDATE, BundledPlugins::rows()[0]['status']);

        // a newer one put in by hand: no update shown
        File::cleanDirectory($this->dir);
        $this->makeRelease('0.9.0');
        $this->assertSame(BundledPlugins::STATUS_NEWER, BundledPlugins::rows()[0]['status']);
    }

    public function testAnotherPluginOfTheSameName(): void
    {
        $this->makeRelease('1.0.0');
        BundledPlugins::install(static::SAMPLE_UUID);
        // another uuid, the same name: not touched
        File::cleanDirectory($this->dir);
        $this->makeRelease('2.0.0', '11111111-2222-4333-8444-555555555555');
        try {
            BundledPlugins::install('11111111-2222-4333-8444-555555555555');
            $this->fail('another plugin of the same name was installed over');
        } catch (ReleaseException $e) {
            $this->assertSame('same_name', $e->reason());
        }
        $this->assertSame('1.0.0', BundledPlugins::installed(static::SAMPLE_UUID)->version ?? null);
    }

    public function testTheIdOfTheSite(): void
    {
        $migration = require base_path('vendor/exceedone/exment/database/migrations/2026_11_24_000000_add_site_id.php');
        \DB::table('systems')->where('system_name', 'site_id')->delete();
        $migration->up();
        System::clearRequestSession();
        $id = SiteId::get();
        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', strval($id));
        // made once
        $migration->up();
        System::clearRequestSession();
        $this->assertSame($id, SiteId::get());
        // a SaaS gives its own
        config(['exment.site_id' => 'AAAAAAAA-0000-4000-8000-000000000001']);
        $this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', SiteId::get());
    }
}
