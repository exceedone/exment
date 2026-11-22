<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\SystemRequire\Security;
use Exceedone\Exment\Services\SystemRequire\Tuning\AutoTuning;

/**
 * The config cache of the tuning page, the address the security check asks, the server
 * items when the server-wide buttons are off, and the html items of the studio app.
 */
class TuningHardeningTest extends UnitTestBase
{
    /** @var string|null */
    private $cachePath;

    protected function tearDown(): void
    {
        AutoTuning::$artisan = null;
        Security\SiteProbe::clear();
        if (isset($this->cachePath)) {
            @unlink($this->cachePath);
            unset($_SERVER['APP_CONFIG_CACHE'], $_ENV['APP_CONFIG_CACHE']);
            putenv('APP_CONFIG_CACHE');
        }
        parent::tearDown();
    }

    public function testTheButtonOfTheTuningPageUsesTheSameRoutine(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/TuningController.php');
        $this->assertStringContainsString('AutoTuning::cacheConfig()', $source);
        $this->assertStringNotContainsString("artisan('config:cache')", $source);
    }

    public function testTheConfigCacheEndsReadableByTheOwnerAndTheGroup(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('no file modes on Windows');
        }
        $this->cachePath = sys_get_temp_dir() . '/exment_config_cache_' . uniqid('', true) . '.php';
        $_SERVER['APP_CONFIG_CACHE'] = $_ENV['APP_CONFIG_CACHE'] = $this->cachePath;
        putenv('APP_CONFIG_CACHE=' . $this->cachePath);
        $this->assertSame($this->cachePath, app()->getCachedConfigPath());

        $path = $this->cachePath;
        AutoTuning::$artisan = function (string $command) use ($path) {
            $this->assertSame('config:cache', $command);
            file_put_contents($path, '<?php return [];');
            chmod($path, 0666);
        };
        AutoTuning::cacheConfig();
        clearstatcache();
        $this->assertSame('640', substr(sprintf('%o', fileperms($path)), -3));
    }

    public function testTheProbeNeverUsesTheAddressOfTheRequest(): void
    {
        config(['exment.site_probe_url' => '', 'app.url' => '']);
        $this->assertNull(Security\SiteProbe::base());
        $this->assertNull(Security\SiteProbe::get('admin/auth/login'));
        $this->assertSame(exmtrans('security_check.not_checked'), (new Security\HeadersSecurity())->getResultText());
        $this->assertSame(SystemRequireResult::WARNING, (new Security\ExposedPathSecurity())->checkResult());

        config(['app.url' => 'http://example.test/']);
        $this->assertSame('http://example.test', Security\SiteProbe::base());
    }

    public function testNoServerItemsWhenTheServerWideButtonsAreOff(): void
    {
        config(['exment.features_locked' => '', 'exment.tuning_server_actions' => true]);
        $this->assertTrue(Security\SecurityCheck::serverItemsShown());
        $this->assertContains('file_permission', array_keys((new Security\SecurityCheck())->items()));

        config(['exment.tuning_server_actions' => false]);
        $this->assertFalse(Security\SecurityCheck::serverItemsShown());
        $this->assertSame(['admin_password', 'two_factor'], array_keys((new Security\SecurityCheck())->items()));

        $server = new HardeningItem('app_debug', Security\SecurityBase::SCOPE_SERVER);
        $site = new HardeningItem('two_factor', Security\SecurityBase::SCOPE_SITE);
        $check = new Security\SecurityCheck([$server, $site]);
        $this->assertFalse($check->fix('app_debug'));
        $this->assertSame(0, $server->fixed);
        $this->assertTrue($check->fix('two_factor'));
        $this->assertSame(1, $site->fixed);
    }

    public function testTheStudioShowsHtmlItemsAsTextWithoutADomElement(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/studio/app.blade.php');
        $this->assertStringNotContainsString('.innerHTML = item', $source);
        $this->assertStringContainsString("new DOMParser().parseFromString(String(item.html || ''), 'text/html').body.textContent", $source);
    }
}

class HardeningItem extends Security\SecurityBase
{
    /** @var string */
    protected $name;

    /** @var string */
    protected $itemScope;

    /** @var int */
    public $fixed = 0;

    public function __construct(string $name, string $scope)
    {
        $this->name = $name;
        $this->itemScope = $scope;
    }

    protected function key(): string
    {
        return $this->name;
    }

    public function scope(): string
    {
        return $this->itemScope;
    }

    public function checkResult(): string
    {
        return SystemRequireResult::WARNING;
    }

    public function canFix(): bool
    {
        return true;
    }

    public function fix(): void
    {
        $this->fixed++;
    }
}
