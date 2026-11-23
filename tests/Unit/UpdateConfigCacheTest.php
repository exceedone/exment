<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Console\UpdateCommand;
use Exceedone\Exment\Services\SystemRequire\Tuning\AutoTuning;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * exment:update clears the config cache: a site that had one gets it again (0640), a site
 * without one gets none.
 */
class UpdateConfigCacheTest extends UnitTestBase
{
    /** @var string */
    private $cachePath;

    /** @var array<int, string> */
    private $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir() . '/exment_update_config_cache_' . uniqid('', true) . '.php';
        $_SERVER['APP_CONFIG_CACHE'] = $_ENV['APP_CONFIG_CACHE'] = $this->cachePath;
        putenv('APP_CONFIG_CACHE=' . $this->cachePath);
        $path = $this->cachePath;
        AutoTuning::$artisan = function (string $command) use ($path) {
            $this->commands[] = $command;
            file_put_contents($path, '<?php return [];');
            @chmod($path, 0666);
        };
    }

    protected function tearDown(): void
    {
        AutoTuning::$artisan = null;
        @unlink($this->cachePath);
        unset($_SERVER['APP_CONFIG_CACHE'], $_ENV['APP_CONFIG_CACHE']);
        putenv('APP_CONFIG_CACHE');
        parent::tearDown();
    }

    private function command(): UpdateCommand
    {
        $command = app(UpdateCommand::class);
        $command->setLaravel(app());
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        return $command;
    }

    public function testASiteWithAConfigCacheGetsItAgain(): void
    {
        file_put_contents($this->cachePath, '<?php return [];');
        $command = $this->command();
        $this->assertSame($this->cachePath, app()->getCachedConfigPath());
        $this->assertTrue($command->configCached());

        @unlink($this->cachePath); // what config:clear does in the update
        $command->restoreConfigCache(true);

        $this->assertSame(['config:cache'], $this->commands);
        $this->assertFileExists($this->cachePath);
        if (DIRECTORY_SEPARATOR !== '\\') {
            clearstatcache();
            $this->assertSame('640', substr(sprintf('%o', fileperms($this->cachePath)), -3));
        }
    }

    public function testASiteWithoutAConfigCacheGetsNone(): void
    {
        $command = $this->command();
        $this->assertFalse($command->configCached());

        $command->restoreConfigCache(false);

        $this->assertSame([], $this->commands);
        $this->assertFileDoesNotExist($this->cachePath);
    }
}
