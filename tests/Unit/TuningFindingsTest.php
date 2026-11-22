<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\SystemRequire\Security\SiteProbe;
use Exceedone\Exment\Services\SystemRequire\Tuning\CacheDriverTuning;
use Exceedone\Exment\Services\SystemRequire\Tuning\ComposerAutoloadTuning;
use Exceedone\Exment\Services\SystemRequire\Tuning\SlowQueryLogTuning;

/**
 * What a tuning and security check on a real site found wrong (7.2.1): the setup page linked
 * files that are not there, the slow query log was read with the wrong case of the keys,
 * the cache advice named a key the site does not read, and the security check could not
 * reach a site in a container.
 */
class TuningFindingsTest extends UnitTestBase
{
    /** @var array<string> */
    private $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        config(['exment.site_probe_url' => null]);
        parent::tearDown();
    }

    public function testTheSetupPageLinksOnlyFilesThatAreThere(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/initialize/index.blade.php');
        preg_match_all('/admin_asset\s*\(\s*"\/?([^"]+)"/', $view, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $path) {
            $this->assertFileExists(public_path($path), 'the setup page links ' . $path);
        }
        // a link without rel="stylesheet" is not loaded at all, and a wrong integrity blocks the file
        $this->assertDoesNotMatchRegularExpression('/<link (?![^>]*rel=)[^>]*href=/', $view);
        $this->assertStringNotContainsString('integrity=', $view);
    }

    public function testTheSlowQueryLogIsReadInAnyCaseOfTheKeys(): void
    {
        foreach ([
            ['variable_name', 'value'],
            ['Variable_name', 'Value'],
            ['VARIABLE_NAME', 'VALUE'],
        ] as list($name, $value)) {
            $check = new SlowQueryLogTuning();
            // the rows as PDO gives them, through the code that reads them
            $rows = [
                [$name => 'slow_query_log', $value => 'ON'],
                [$name => 'long_query_time', $value => '1.000000'],
            ];
            $method = new \ReflectionMethod(SlowQueryLogTuning::class, 'readRows');
            $method->setAccessible(true);
            $this->assertSame(['slow_query_log' => 'ON', 'long_query_time' => '1.000000'], $method->invoke($check, $rows), $name);
        }
    }

    public function testTheCacheAdviceNamesTheKeyTheSiteReads(): void
    {
        $this->assertSame(['CACHE_DRIVER'], CacheDriverTuning::cacheEnvKeys($this->tempConfig("'default' => env('CACHE_DRIVER', 'file'),")));
        $this->assertSame(['CACHE_STORE'], CacheDriverTuning::cacheEnvKeys($this->tempConfig("'default' => env('CACHE_STORE', 'database'),")));
        $this->assertSame(['CACHE_DRIVER', 'CACHE_STORE'], CacheDriverTuning::cacheEnvKeys('/no/such/cache.php'));

        // this site's config/cache.php
        $fix = (string) (new CacheDriverTuning())->getFixText();
        foreach (CacheDriverTuning::cacheEnvKeys() as $key) {
            $this->assertStringContainsString($key . '=redis', $fix);
        }
    }

    public function testTheComposerAdviceSaysWhoRunsIt(): void
    {
        $fix = (string) (new ComposerAutoloadTuning())->getFixText();
        $this->assertStringContainsString('composer dump-autoload -o', $fix);
        $this->assertStringContainsString('vendor/', $fix);
        $owner = ComposerAutoloadTuning::vendorOwner();
        if (!is_null($owner)) {
            $this->assertStringContainsString('(' . $owner . ')', $fix);
        }
    }

    public function testTheSecurityCheckCanAskAnotherAddress(): void
    {
        config(['exment.site_probe_url' => null, 'app.url' => 'http://localhost:8099']);
        $this->assertSame('http://localhost:8099', SiteProbe::base());

        config(['exment.site_probe_url' => 'http://web/']);
        $this->assertSame('http://web', SiteProbe::base());
    }

    private function tempConfig(string $line): string
    {
        $file = sys_get_temp_dir() . '/exment_cache_config_' . uniqid('', true) . '.php';
        file_put_contents($file, "<?php\nreturn [\n    " . $line . "\n];\n");
        return $this->tempFiles[] = $file;
    }
}
