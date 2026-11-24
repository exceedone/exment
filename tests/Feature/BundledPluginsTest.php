<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\PluginDistribution\AdminNotices;
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
        return sodium_crypto_sign_secretkey($pair);
    }

    /**
     * A release of the sample plugin of another version (or uuid), signed with the test key.
     *
     * @param array<string, mixed> $extra more fields of the record
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function makeRelease(string $version, string $uuid = self::SAMPLE_UUID, string $name = 'ExmentSignSample', ?string $dir = null, array $extra = []): array
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
        ] + $extra;
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
        ReleaseVerifier::trustForTests('release-2099-9', static::TEST_PUBLIC_KEY);
        $this->assertNull(ReleaseVerifier::publicKey('release-2099-9'), 'a real key name is not taken from the tests');
        // the keys of the releases (ISS00430): two, each a public key of Ed25519
        foreach (['release-2026-1', 'release-2026-2'] as $keyId) {
            $key = ReleaseVerifier::publicKey($keyId);
            $this->assertNotNull($key, $keyId);
            $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen(strval(base64_decode(strval($key), true))), $keyId);
            $this->assertNotSame(static::TEST_PUBLIC_KEY, $key, 'not the key of the tests');
        }
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

    public function testAReleaseDownloadedByHand(): void
    {
        // downloaded from where the releases are published (not in the bundle)
        $downloads = sys_get_temp_dir() . '/exment_downloaded_' . uniqid();
        File::makeDirectory($downloads, 0777, true);
        try {
            [, $zip100] = $this->makeRelease('1.0.0', self::SAMPLE_UUID, 'ExmentSignSample', $downloads);
            [, $zip110] = $this->makeRelease('1.1.0', self::SAMPLE_UUID, 'ExmentSignSample', $downloads);
            $json = function (string $zip) {
                return substr($zip, 0, -4) . '.json';
            };

            $plugin = BundledPlugins::installUploaded($zip100, $json($zip100));
            $this->assertSame('1.0.0', strval($plugin->version));
            $this->assertTrue(boolval($plugin->active_flg));

            // a newer one: updated in place, the version before kept to go back to
            $updated = BundledPlugins::installUploaded($zip110, $json($zip110));
            $this->assertSame('1.1.0', strval($updated->version));
            $this->assertSame($plugin->id, $updated->id);
            $this->assertSame('1.0.0', BundledPlugins::previousVersion(self::SAMPLE_UUID, '1.1.0'));

            // the same or an older one: not by an upload
            try {
                BundledPlugins::installUploaded($zip100, $json($zip100));
                $this->fail('an older version was installed by an upload');
            } catch (ReleaseException $e) {
                $this->assertSame('not_newer', $e->reason());
            }

            // a zip that does not match its signed record: refused
            [, $zip120] = $this->makeRelease('1.2.0', self::SAMPLE_UUID, 'ExmentSignSample', $downloads);
            file_put_contents($zip120, 'changed', FILE_APPEND);
            try {
                BundledPlugins::installUploaded($zip120, $json($zip120));
                $this->fail('a changed zip was installed');
            } catch (ReleaseException $e) {
                $this->assertSame('zip', $e->reason());
            }
            $this->assertSame('1.1.0', BundledPlugins::installed(self::SAMPLE_UUID)->version ?? null);

            // the page: both files are needed, and a good pair is installed
            [, $zip130] = $this->makeRelease('1.3.0', self::SAMPLE_UUID, 'ExmentSignSample', $downloads);
            System::clearRequestSession();
            $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
            $upload = function (string $path) {
                return new \Illuminate\Http\UploadedFile($path, basename($path), null, null, true);
            };
            $this->post(admin_url('feature/bundled_upload'), ['bundled_zip' => $upload($zip130)])->assertRedirect(admin_url('feature'));
            $this->assertSame('1.1.0', BundledPlugins::installed(self::SAMPLE_UUID)->version ?? null);
            $this->post(admin_url('feature/bundled_upload'), [
                'bundled_zip' => $upload($zip130),
                'bundled_json' => $upload($json($zip130)),
            ])->assertRedirect(admin_url('feature'));
            $this->assertSame('1.3.0', BundledPlugins::installed(self::SAMPLE_UUID)->version ?? null);
        } finally {
            File::deleteDirectory($downloads);
        }
    }

    public function testADownloadedReleaseOnASiteThatKeepsTheVersionsToItsProvider(): void
    {
        config(['exment.bundled_plugins_updates' => false]);
        [, $zip] = $this->makeRelease('1.0.0');
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->post(admin_url('feature/bundled_upload'), [
            'bundled_zip' => new \Illuminate\Http\UploadedFile($zip, basename($zip), null, null, true),
            'bundled_json' => new \Illuminate\Http\UploadedFile(substr($zip, 0, -4) . '.json', 'r.json', null, null, true),
        ])->assertRedirect(admin_url('feature'));
        $this->assertNull(BundledPlugins::installed(self::SAMPLE_UUID));
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

    public function testTheCopyIsWhatIsCheckedAndInstalled(): void
    {
        $this->makeRelease('1.0.0');
        // the copy differs from the file checked when listed: refused
        try {
            ChangedCopyBundledPlugins::install(static::SAMPLE_UUID);
            $this->fail('a copy that does not match was installed');
        } catch (ReleaseException $e) {
            $this->assertSame('zip', $e->reason());
        }
        $this->assertFalse(Plugin::query()->where('uuid', static::SAMPLE_UUID)->exists(), 'not installed');

        // a good copy: installed, and the copy is the one kept for going back
        $plugin = BundledPlugins::install(static::SAMPLE_UUID);
        $this->assertSame('1.0.0', strval($plugin->version));
        $kept = Storage::disk(Define::DISKNAME_ADMIN)->get(BundledPlugins::PREVIOUS_DIR . '/' . static::SAMPLE_UUID . '/1.0.0.zip');
        $this->assertSame(hash_file('sha256', $this->dir . '/ExmentSignSample-1.0.0.zip'), hash('sha256', strval($kept)));
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

    public function testTheSampleWithAFixForTheSafety(): void
    {
        $record = json_decode(strval(file_get_contents($this->sample('ExmentSignSample-1.0.1.json'))), true);
        ReleaseVerifier::verify($record, $this->sample('ExmentSignSample-1.0.1.zip'), '7.1.2');
        $record['security'] = false;
        try {
            ReleaseVerifier::verifyRecord($record);
            $this->fail('the mark of the fix was changed and passed');
        } catch (ReleaseException $e) {
            $this->assertSame('signature', $e->reason());
        }
    }

    public function testTheAdministratorsAreToldOfAFixForTheSafety(): void
    {
        copy($this->sample('ExmentSignSample-1.0.0.zip'), $this->dir . '/ExmentSignSample-1.0.0.zip');
        copy($this->sample('ExmentSignSample-1.0.0.json'), $this->dir . '/ExmentSignSample-1.0.0.json');
        BundledPlugins::install(static::SAMPLE_UUID);
        $this->assertSame([], AdminNotices::security(), 'nothing while the newest is installed');

        // 1.0.1 has a fix: the row is marked, the administrators are told
        File::cleanDirectory($this->dir);
        copy($this->sample('ExmentSignSample-1.0.1.zip'), $this->dir . '/ExmentSignSample-1.0.1.zip');
        copy($this->sample('ExmentSignSample-1.0.1.json'), $this->dir . '/ExmentSignSample-1.0.1.json');
        $row = BundledPlugins::rows()[0];
        $this->assertTrue($row['security']);
        $this->assertStringContainsString('添付ファイル', strval($row['security_note']));
        $this->assertCount(1, AdminNotices::security());
        $this->assertSame('1.0.0', BundledPlugins::installed(static::SAMPLE_UUID)->version ?? null, 'not installed by itself');

        $admins = array_map(function ($user) {
            return intval($user->id);
        }, AdminNotices::administrators());
        $this->assertNotEmpty($admins);
        $count = function () use ($admins) {
            return NotifyNavbar::withoutGlobalScopes()->whereIn('target_user_id', $admins)->count();
        };
        $before = $count();
        // a short subject (the bell, the mail); the sentence in the body
        $notice = AdminNotices::pending()['security:' . static::SAMPLE_UUID . ':1.0.1'] ?? null;
        $this->assertNotNull($notice);
        $this->assertSame(strval(exmtrans('plugin_distribution.notice_security_subject', ['name' => 'Exment Sign Sample'])), $notice['subject']);
        $this->assertStringContainsString(strval(exmtrans('plugin_distribution.notice_security', ['name' => 'Exment Sign Sample', 'version' => '1.0.1'])), $notice['body']);
        $this->assertGreaterThanOrEqual(1, AdminNotices::deliver());
        $this->assertGreaterThan($before, $count());
        // once: not told again
        $told = $count();
        $this->assertSame(0, AdminNotices::deliver());
        $this->assertSame($told, $count());
        // the others are not told
        $others = NotifyNavbar::withoutGlobalScopes()->whereNotIn('target_user_id', $admins)->where('notify_subject', 'like', '%ExmentSignSample%')->count()
            + NotifyNavbar::withoutGlobalScopes()->whereNotIn('target_user_id', $admins)->where('notify_subject', 'like', '%Exment Sign Sample%')->count();
        $this->assertSame(0, $others);

        // taken: no more mark
        BundledPlugins::install(static::SAMPLE_UUID);
        $this->assertFalse(BundledPlugins::rows()[0]['security']);
        $this->assertSame([], AdminNotices::security());
    }

    public function testTheFirstSafeVersionCarriesOver(): void
    {
        $this->makeRelease('1.0.0');
        BundledPlugins::install(static::SAMPLE_UUID);
        // 1.0.2 has no fix of its own, but 1.0.1 had: the site on 1.0.0 is still told
        File::cleanDirectory($this->dir);
        $this->makeRelease('1.0.2', static::SAMPLE_UUID, 'ExmentSignSample', null, ['security' => false, 'security_min' => '1.0.1']);
        $this->assertTrue(BundledPlugins::rows()[0]['security']);
        // a version with no word of the safety: no mark
        File::cleanDirectory($this->dir);
        $this->makeRelease('1.0.3');
        $this->assertFalse(BundledPlugins::rows()[0]['security']);
    }

    public function testTheHomeOfTheAdministratorsOnly(): void
    {
        copy($this->sample('ExmentSignSample-1.0.0.zip'), $this->dir . '/ExmentSignSample-1.0.0.zip');
        copy($this->sample('ExmentSignSample-1.0.0.json'), $this->dir . '/ExmentSignSample-1.0.0.json');
        BundledPlugins::install(static::SAMPLE_UUID);
        File::cleanDirectory($this->dir);
        copy($this->sample('ExmentSignSample-1.0.1.zip'), $this->dir . '/ExmentSignSample-1.0.1.zip');
        copy($this->sample('ExmentSignSample-1.0.1.json'), $this->dir . '/ExmentSignSample-1.0.1.json');
        $text = exmtrans('plugin_distribution.notice_security', ['name' => 'Exment Sign Sample', 'version' => '1.0.1']);

        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $home = $this->followingRedirects()->get(admin_url(''));
        $home->assertSee($text);
        // a warning with a short title, not a red error box with the whole sentence as its title
        $subject = strval(exmtrans('plugin_distribution.notice_security_subject', ['name' => 'Exment Sign Sample']));
        $home->assertSee($subject);
        $html = strval($home->getContent());
        $this->assertMatchesRegularExpression('/alert-warning.{0,400}' . preg_quote(e($subject), '/') . '/s', $html);
        $this->assertDoesNotMatchRegularExpression('/alert-danger.{0,400}' . preg_quote(e($subject), '/') . '/s', $html);

        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $page = $this->followingRedirects()->get(admin_url(''));
        $page->assertDontSee($text);
        $page->assertDontSee(exmtrans('plugin_distribution.security_badge'));
    }

    public function testTheNewFeaturesAfterAnUpdate(): void
    {
        System::features(['_since' => now()->subDay()->toIso8601String(), 'studio' => ['enabled' => true, 'role_groups' => null, 'menu' => true]]);
        System::clearRequestSession();
        \Exceedone\Exment\Services\Features::reset();
        \Exceedone\Exment\Services\Features::register('exment_test.new', ['label' => 'Brand New']);
        $this->assertContains('Brand New', AdminNotices::newFeatures());
        $pending = AdminNotices::pending();
        $features = array_filter($pending, function ($key) {
            return str_starts_with($key, 'features:');
        }, ARRAY_FILTER_USE_KEY);
        $this->assertNotEmpty($features);
        // the bell and the home only, no mail (a mail is for a security fix)
        foreach ($features as $notice) {
            $this->assertFalse($notice['mail']);
        }

        // a site installed with Feature management: the features it had then are not new to it
        System::features(['_since' => now()->subDay()->toIso8601String(), '_known' => ['exment_test.new']]);
        System::clearRequestSession();
        $this->assertNotContains('Brand New', AdminNotices::newFeatures());
        // a feature that came with a later update is
        \Exceedone\Exment\Services\Features::reset();
        \Exceedone\Exment\Services\Features::register('exment_test.new', ['label' => 'Brand New']);
        \Exceedone\Exment\Services\Features::register('exment_test.later', ['label' => 'Came Later']);
        $this->assertContains('Came Later', AdminNotices::newFeatures());
        // a new site (no "_since"): not told
        System::features(['studio' => ['enabled' => true, 'role_groups' => null, 'menu' => true]]);
        System::clearRequestSession();
        $this->assertSame([], AdminNotices::newFeatures());
        \Exceedone\Exment\Services\Features::reset();
    }

    public function testANewFeatureAlreadyOnAndTheCloseOfTheNotice(): void
    {
        System::features(['_since' => now()->subDay()->toIso8601String(), 'studio' => ['enabled' => true, 'role_groups' => null, 'menu' => true]]);
        System::clearRequestSession();
        \Exceedone\Exment\Services\Features::reset();
        // on already (a feature a plugin had before): not told, the notice says it starts off
        \Exceedone\Exment\Services\Features::register('exment_test.on', ['label' => 'Already On', 'existing' => true]);
        \Exceedone\Exment\Services\Features::register('exment_test.off', ['label' => 'Starts Off']);
        $this->assertTrue(\Exceedone\Exment\Services\Features::enabled('exment_test.on'));
        $this->assertSame(['Starts Off'], array_values(array_intersect(['Already On', 'Starts Off'], AdminNotices::newFeatures())));

        // the × of the notice: not told again
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->post(admin_url('feature/notices_known'), [], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        System::clearRequestSession();
        $this->assertNotContains('Starts Off', AdminNotices::newFeatures());
        $this->assertFalse(\Exceedone\Exment\Services\Features::enabled('exment_test.off'), 'known, still off');

        // opening Feature management does the same
        \Exceedone\Exment\Services\Features::register('exment_test.later', ['label' => 'Came Later']);
        $this->assertContains('Came Later', AdminNotices::newFeatures());
        $this->get(admin_url('feature'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        System::clearRequestSession();
        $this->assertNotContains('Came Later', AdminNotices::newFeatures());

        // only the system administrators may close it for the site
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->post(admin_url('feature/notices_known'), [], ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(403);
        \Exceedone\Exment\Services\Features::reset();
    }

    public function testGoingBackBelowTheFirstSafeVersionIsMarked(): void
    {
        $this->makeRelease('1.0.0');
        BundledPlugins::install(static::SAMPLE_UUID);
        File::cleanDirectory($this->dir);
        $this->makeRelease('1.0.1', static::SAMPLE_UUID, 'ExmentSignSample', null, ['security' => true, 'security_min' => '1.0.1']);
        BundledPlugins::install(static::SAMPLE_UUID);
        $row = BundledPlugins::rows()[0];
        $this->assertSame('1.0.0', $row['previous']);
        $this->assertTrue($row['previous_unsafe'], 'going back takes the fix away');
        $this->assertFalse($row['by_hand']);
    }

    public function testAVersionInstalledByHand(): void
    {
        // put in as an uploaded plugin, not from the bundle
        [, $zip] = $this->makeRelease('1.0.0');
        session()->forget('errorMess');
        \Exceedone\Exment\Services\Plugin\PluginInstaller::uploadPlugin(new \Illuminate\Http\UploadedFile($zip, basename($zip), 'application/zip', null, true));
        File::cleanDirectory($this->dir);
        $this->makeRelease('1.1.0');
        $row = BundledPlugins::rows()[0];
        $this->assertSame(BundledPlugins::STATUS_UPDATE, $row['status']);
        $this->assertTrue($row['by_hand']);
    }

    public function testASiteThatKeepsTheVersionsToItsProvider(): void
    {
        config(['exment.bundled_plugins_updates' => false]);
        $this->makeRelease('1.0.0');
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        // installing stays
        $this->post(admin_url('feature/bundled/' . static::SAMPLE_UUID . '/install'))->assertRedirect(admin_url('feature'));
        $this->assertSame('1.0.0', BundledPlugins::installed(static::SAMPLE_UUID)->version ?? null);
        // a newer one: not by this page
        File::cleanDirectory($this->dir);
        $this->makeRelease('1.1.0');
        $this->post(admin_url('feature/bundled/' . static::SAMPLE_UUID . '/install'))->assertRedirect(admin_url('feature'));
        $this->assertSame('1.0.0', BundledPlugins::installed(static::SAMPLE_UUID)->version ?? null);
        $this->get(admin_url('feature'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee(exmtrans('plugin_distribution.updates_off'))->assertDontSee(exmtrans('plugin_distribution.update'));
    }

    public function testUseTurnsOnTheMainFeaturesOfThePlugin(): void
    {
        \Exceedone\Exment\Services\Features::reset();
        \Exceedone\Exment\Services\Features::register('exment_sign_sample.main', ['label' => 'Main', 'existing' => true]);
        \Exceedone\Exment\Services\Features::register('exment_sign_sample.extra', ['label' => 'Extra']);
        $this->makeRelease('1.0.0');
        BundledPlugins::install(static::SAMPLE_UUID);
        System::clearRequestSession();
        $this->assertFalse(\Exceedone\Exment\Services\Features::isNew('exment_sign_sample.main'));
        $this->assertTrue(\Exceedone\Exment\Services\Features::setting('exment_sign_sample.main')['enabled']);
        $this->assertTrue(\Exceedone\Exment\Services\Features::isNew('exment_sign_sample.extra'), 'not the others');
        \Exceedone\Exment\Services\Features::reset();
    }

    public function testTheIdOfTheSite(): void
    {
        $migration = require base_path('vendor/exceedone/exment/database/migrations/2026_11_24_000000_add_site_id.php');
        \Illuminate\Support\Facades\DB::table('systems')->where('system_name', 'site_id')->delete();
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

/**
 * The copy made before the install is changed (as if the file changed while it was copied).
 */
class ChangedCopyBundledPlugins extends BundledPlugins
{
    protected static function tempCopy(string $zip): string
    {
        $local = parent::tempCopy($zip);
        file_put_contents($local, 'changed', FILE_APPEND);
        return $local;
    }
}
