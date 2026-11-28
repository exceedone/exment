<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Exment;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Cache;

/**
 * A newer version that "composer update" cannot reach with the ranges of the site's
 * composer.json (needs_manual): the screens and exment:update point to the steps of the manual.
 * Packagist and the composer.json are fakes (Exment::packagistJsonUsing(), rootComposerJsonUsing()): no network.
 */
class ManualUpdateNoticeTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /** the root composer.json of a 7.2 site */
    private const ROOT_72 = ['require' => ['exceedone/exment' => '^7.0', 'exceedone/exment-admin-core' => '^1.0']];

    private string $locale = 'en';

    /** @var array<string, string|null>|null the versions of composer.lock that Exment had (fakeInstalledVersion()) */
    private ?array $composerLockVersions = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->forgetVersionCache();
        $this->locale = app()->getLocale();
    }

    protected function tearDown(): void
    {
        Exment::packagistJsonUsing(null);
        Exment::rootComposerJsonUsing(null);
        if (!is_null($this->composerLockVersions)) {
            $property = new \ReflectionProperty(Exment::class, 'composerLockVersions');
            $property->setAccessible(true);
            $property->setValue(null, $this->composerLockVersions);
        }
        $this->forgetVersionCache();
        app()->setLocale($this->locale);
        System::clearCache();
        parent::tearDown();
    }

    // the decision ------------------------------------------------------------

    public function testAVersionThatNeedsANewerAdminCoreIsNotReachable(): void
    {
        $exment = new Exment();
        $this->assertTrue($exment->isUnreachableByComposerUpdate('v7.3.0', ['exceedone/exment-admin-core' => '^2.1'], self::ROOT_72));
    }

    public function testAVersionWithinTheRangesIsReachable(): void
    {
        $exment = new Exment();
        $this->assertFalse($exment->isUnreachableByComposerUpdate('v7.2.10', ['exceedone/exment-admin-core' => '^1.0.6'], self::ROOT_72));
        // the root that is already moved to admin-core 2
        $root73 = ['require' => ['exceedone/exment' => '^7.0', 'exceedone/exment-admin-core' => '^2.1']];
        $this->assertFalse($exment->isUnreachableByComposerUpdate('v7.3.0', ['exceedone/exment-admin-core' => '^2.1'], $root73));
        // a root that does not name admin-core: composer chooses it
        $this->assertFalse($exment->isUnreachableByComposerUpdate('v7.3.0', ['exceedone/exment-admin-core' => '^2.1'], ['require' => ['exceedone/exment' => '^7.0']]));
    }

    public function testARangeOfExmentThatDoesNotTakeTheVersionIsNotReachable(): void
    {
        $exment = new Exment();
        $root = ['require' => ['exceedone/exment' => '~7.2.0']];
        $this->assertTrue($exment->isUnreachableByComposerUpdate('v7.3.0', [], $root));
        $this->assertFalse($exment->isUnreachableByComposerUpdate('v7.2.10', [], $root));
    }

    public function testNothingIsToldWithoutTheComposerJsonOrARangeThatIsRead(): void
    {
        $exment = new Exment();
        $requires = ['exceedone/exment-admin-core' => '^2.1'];
        $this->assertFalse($exment->isUnreachableByComposerUpdate('v7.3.0', $requires, null));
        $this->assertFalse($exment->isUnreachableByComposerUpdate('v7.3.0', $requires, ['name' => 'no-require']));
        $this->assertFalse($exment->isUnreachableByComposerUpdate(null, $requires, self::ROOT_72));
        $this->assertFalse($exment->isUnreachableByComposerUpdate('v7.3.0', $requires, ['require' => ['exceedone/exment-admin-core' => 'not a range !!']]));
    }

    public function testTheRequiresOfAPackagistVersion(): void
    {
        $exment = new Exment();
        $this->assertSame(['exceedone/exment-admin-core' => '^2.1'], $exment->pickUpdateRequires([
            'version' => 'v7.3.0',
            'require' => ['php' => '^8.2', 'exceedone/exment-admin-core' => '^2.1', 'laravel/framework' => '^12.0'],
        ]));
        $this->assertSame([], $exment->pickUpdateRequires(null));
        $this->assertSame([], $exment->pickUpdateRequires(['version' => 'v7.3.0']));
    }

    // packagist (fake) -> the cache -> needs_manual ------------------------------

    public function testTheFlagIsKeptInTheCachedVersionInformation(): void
    {
        $this->fakeInstalledVersion('v7.2.9');
        System::outside_api(true);
        System::clearCache();

        Exment::packagistJsonUsing(function () {
            return $this->packagist('v99.1.0', '^99.0');
        });
        Exment::rootComposerJsonUsing(function () {
            return self::ROOT_72;
        });

        [$latest] = \Exment::getExmentVersion();
        $this->assertSame('v99.1.0', $latest);

        $cached = json_decode((string) Cache::get(Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION), true);
        $this->assertTrue($cached['needs_manual']);
        $this->assertSame(['exceedone/exment-admin-core' => '^99.0'], $cached['latest_requires']);
        $this->assertTrue(\Exment::needsManualUpdate());
    }

    public function testReachableUnreachableAndNoComposerJsonFromTheCache(): void
    {
        $this->putVersionCache('v7.3.0', 'v7.2.9', ['exceedone/exment-admin-core' => '^2.1']);

        Exment::rootComposerJsonUsing(function () {
            return self::ROOT_72;
        });
        $this->assertTrue(\Exment::needsManualUpdate(false));

        // the composer.json is changed for 7.3: read again, not the flag of the cache
        Exment::rootComposerJsonUsing(function () {
            return ['require' => ['exceedone/exment' => '^7.3', 'exceedone/exment-admin-core' => '^2.1']];
        });
        $this->assertFalse(\Exment::needsManualUpdate(false));

        // no composer.json (or one that is not read)
        Exment::rootComposerJsonUsing(function () {
            return null;
        });
        $this->assertFalse(\Exment::needsManualUpdate(false));
    }

    public function testNothingIsToldWithoutTheVersionInformation(): void
    {
        System::outside_api(false);
        System::clearCache();
        Exment::rootComposerJsonUsing(function () {
            return self::ROOT_72;
        });
        Exment::packagistJsonUsing(function () {
            $this->fail('packagist is not asked when outside_api is off');
        });

        $this->assertFalse(\Exment::needsManualUpdate());
        $this->assertSame([], \Exment::getManualUpdateNoticeLines());
    }

    // the notice --------------------------------------------------------------

    public function testTheNoticeAndThePageOfTheManual(): void
    {
        app()->setLocale('ja');
        $this->assertSame('7.3.0 は composer update だけでは上がりません。手順のページを見てください。', \Exment::getManualUpdateNotice('v7.3.0'));
        app()->setLocale('en');
        $this->assertSame("7.3.0 can't be installed with composer update alone. See the steps on the manual page.", \Exment::getManualUpdateNotice('v7.3.0'));

        $this->assertSame('update/v7_3_0', \Exment::getManualUpdatePath('v7.3.0'));
        $this->assertSame('update/v7_3_0', \Exment::getManualUpdatePath('7.3.2'));
        $this->assertSame('update/v7_4_0', \Exment::getManualUpdatePath('v7.4.1'));
        $this->assertSame('update', \Exment::getManualUpdatePath(null));
    }

    public function testExmentUpdateIsToldTheNotice(): void
    {
        System::outside_api(false);
        System::clearCache();
        $this->putVersionCache('v99.1.0', 'v7.2.9', ['exceedone/exment-admin-core' => '^99.0']);
        Exment::rootComposerJsonUsing(function () {
            return self::ROOT_72;
        });

        $lines = \Exment::getManualUpdateNoticeLines();
        $this->assertSame([
            \Exment::getManualUpdateNotice('v99.1.0'),
            \Exment::getManualUrl('update/v99_1_0'),
        ], $lines);

        // reachable: nothing
        Exment::rootComposerJsonUsing(function () {
            return ['require' => ['exceedone/exment' => '*', 'exceedone/exment-admin-core' => '^99.0']];
        });
        $this->assertSame([], \Exment::getManualUpdateNoticeLines());
    }

    public function testTheDashboardLinksToTheStepsOfTheManual(): void
    {
        $this->putVersionCache('v7.3.0', 'v7.2.9', ['exceedone/exment-admin-core' => '^2.1']);
        Exment::rootComposerJsonUsing(function () {
            return self::ROOT_72;
        });
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        config(['exment.disable_latest_version_dashboard' => false]);

        $content = (string) $this->get(admin_url(''))->assertOk()->getContent();
        $this->assertStringContainsString(esc_html(\Exment::getManualUpdateNotice('v7.3.0')), $content);
        $this->assertStringContainsString(esc_html(\Exment::getManualUrl('update/v7_3_0')), $content);
    }

    public function testTheDashboardKeepsTheUpdateLinkWhenComposerUpdateIsEnough(): void
    {
        $this->putVersionCache('v7.2.10', 'v7.2.9', ['exceedone/exment-admin-core' => '^1.0.6']);
        Exment::rootComposerJsonUsing(function () {
            return self::ROOT_72;
        });
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        config(['exment.disable_latest_version_dashboard' => false]);

        $content = (string) $this->get(admin_url(''))->assertOk()->getContent();
        $this->assertStringContainsString(esc_html(exmtrans('system.update_guide')), $content);
        $this->assertStringNotContainsString(esc_html(\Exment::getManualUpdateNotice('v7.2.10')), $content);
    }

    public function testTheSystemPageLinksToTheStepsOfTheManual(): void
    {
        System::outside_api(true);
        System::clearCache();
        $this->putVersionCache('v7.3.0', 'v7.2.9', ['exceedone/exment-admin-core' => '^2.1']);
        Exment::rootComposerJsonUsing(function () {
            return self::ROOT_72;
        });
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $content = (string) $this->get(admin_url('system'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString(esc_html(\Exment::getManualUpdateNotice('v7.3.0')), $content);
        $this->assertStringContainsString(esc_html(\Exment::getManualUrl('update/v7_3_0')), $content);
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $latestRequires
     */
    private function putVersionCache(string $latest, string $current, array $latestRequires): void
    {
        Cache::put(Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION, json_encode([
            'latest' => $latest,
            'current' => $current,
            'latest_requires' => $latestRequires,
            'needs_manual' => false,
        ]), Define::CACHE_CLEAR_MINUTE);
    }

    /**
     * The version composer.lock gives (Exment::getVersionFromComposerLock()), for this test only:
     * the installed exment of a test site is often a branch (a dev version).
     */
    private function fakeInstalledVersion(string $version): void
    {
        $lock = base_path('composer.lock');
        if (!\File::exists($lock)) {
            $this->markTestSkipped('the site has no composer.lock');
        }
        $property = new \ReflectionProperty(Exment::class, 'composerLockVersions');
        $property->setAccessible(true);
        $this->composerLockVersions = $property->getValue();
        $property->setValue(null, [$lock . ':' . @filemtime($lock) => $version]);
    }

    private function forgetVersionCache(): void
    {
        Cache::forget(Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION);
        Cache::forget(Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION_EXECUTE);
    }

    /**
     * A packagist "package.versions" with $latest (requiring admin-core $adminCore) and older entries.
     *
     * @return array<string, mixed>
     */
    private function packagist(string $latest, string $adminCore): array
    {
        $normalized = ltrim($latest, 'v') . '.0';
        return ['package' => ['versions' => [
            'dev-master' => ['version' => 'dev-master', 'version_normalized' => '9999999-dev', 'require' => []],
            $latest => ['version' => $latest, 'version_normalized' => $normalized, 'require' => ['exceedone/exment-admin-core' => $adminCore]],
            'v7.2.9' => ['version' => 'v7.2.9', 'version_normalized' => '7.2.9.0', 'require' => ['exceedone/exment-admin-core' => '^1.0.6']],
        ]]];
    }
}
