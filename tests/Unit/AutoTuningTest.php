<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\SystemRequire\Tuning\AutoTuning;

/**
 * The tuning Exment can do by itself (the setup and the tuning page): only what is
 * chosen, only what is needed, and one failure does not stop the others.
 */
class AutoTuningTest extends UnitTestBase
{
    /** @var array<string> */
    protected $tempFiles = [];

    /** @var array<int, array<string, string>> */
    protected $envWrites = [];

    /** @var array<int, string> */
    protected $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        // nothing of the test machine is changed: the result goes to a temp file, .env and
        // the artisan commands are recorded, and Redis answers as the test says
        AutoTuning::$resultPath = $this->tempFiles[] = sys_get_temp_dir() . '/exment_auto_tuning_result_' . uniqid('', true) . '.json';
        AutoTuning::$envWriter = function (array $values) {
            $this->envWrites[] = $values;
        };
        AutoTuning::$artisan = function (string $command) {
            $this->commands[] = $command;
        };
        AutoTuning::$redisAnswers = fn () => false;
        config(['exment.features_locked' => '', 'exment.tuning_server_actions' => true, 'cache.default' => 'file', 'session.driver' => 'file']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        AutoTuning::$resultPath = null;
        AutoTuning::$envWriter = null;
        AutoTuning::$artisan = null;
        AutoTuning::$redisAnswers = null;
        config(['exment.features_locked' => '']);
        parent::tearDown();
    }

    public function testOnlyTheChosenItemsThatAreNeeded(): void
    {
        $auto = new AutoTuningRecorder();
        $auto->need = ['exment_cache' => true, 'assets' => true, 'file_permission' => false];

        $this->assertSame(['done' => [], 'failed' => []], $auto->apply([]));
        $this->assertSame([], $auto->calls);

        $result = $auto->apply(['exment_cache', 'file_permission', 'unknown']);
        $this->assertSame(['exment_cache'], $result['done']);
        $this->assertSame(['exment_cache'], $auto->calls);
    }

    public function testOneFailureDoesNotStopTheOthers(): void
    {
        $auto = new AutoTuningRecorder();
        $auto->need = ['exment_cache' => true, 'assets' => true, 'file_permission' => true];
        $auto->fail = ['exment_cache'];

        $result = $auto->apply(AutoTuning::ITEMS);
        $this->assertSame(['exment_cache'], $result['failed']);
        $this->assertSame(['assets', 'file_permission'], $result['done']);
    }

    public function testTheListShowsWhatIsNeeded(): void
    {
        $auto = new AutoTuningRecorder();
        $auto->need = ['exment_cache' => false, 'assets' => true, 'file_permission' => false];
        $this->assertSame(['assets'], array_keys($auto->available()));
        $this->assertNotSame('', $auto->available()['assets']);
    }

    public function testTheExmentCacheIsNeededOnlyWhenOff(): void
    {
        $auto = new AutoTuning();
        config(['exment.use_cache' => true]);
        $this->assertArrayNotHasKey('exment_cache', $auto->available());
    }

    public function testOnlyTheFilesOfThisUserThatOthersMayRead(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || !function_exists('posix_geteuid')) {
            $this->markTestSkipped('no POSIX permissions here');
        }
        $open = $this->tempFile(0644);
        $closed = $this->tempFile(0640);

        $auto = new AutoTuning();
        $auto->files = [$open, $closed, '/no/such/file'];
        $this->assertSame([$open], $auto->ownFiles());

        $result = $auto->apply(['file_permission']);
        $this->assertSame(['file_permission'], $result['done']);
        clearstatcache(true, $open);
        $this->assertSame('640', substr(sprintf('%o', fileperms($open)), -3));
        $this->assertSame([], $auto->ownFiles());
    }

    public function testRedisMovesTheCacheAndTheSessionOnlyOutsideTheSetup(): void
    {
        $auto = new AutoTuning();
        $this->assertArrayNotHasKey('cache_driver', $auto->available(), 'no Redis: not offered');
        $this->assertArrayNotHasKey('session_driver', $auto->available());

        AutoTuning::$redisAnswers = fn () => true;
        $this->assertArrayHasKey('cache_driver', $auto->available());
        $this->assertArrayHasKey('session_driver', $auto->available());

        // at the setup: everyone (and the new administrator) would sign in again
        $setup = new AutoTuning(true);
        $this->assertArrayNotHasKey('session_driver', $setup->available());
        $result = $setup->apply(['cache_driver', 'session_driver']);
        $this->assertSame(['cache_driver'], $result['done']);
        $this->assertSame([['CACHE_DRIVER' => 'redis', 'CACHE_STORE' => 'redis']], $this->envWrites);

        $this->envWrites = [];
        $result = $auto->apply(['session_driver']);
        $this->assertSame(['session_driver'], $result['done']);
        $this->assertSame([['SESSION_DRIVER' => 'redis']], $this->envWrites);

        // already fast: not offered
        config(['cache.default' => 'redis', 'session.driver' => 'redis']);
        $this->assertArrayNotHasKey('cache_driver', $auto->available());
        $this->assertArrayNotHasKey('session_driver', $auto->available());
    }

    public function testTheConfigCacheComesAfterTheItemsThatWriteEnv(): void
    {
        $auto = new AutoTuning();
        AutoTuning::$redisAnswers = fn () => true;
        $items = array_keys($auto->available());
        // offered (writing .env removes a config cache) and after them
        $this->assertContains('config_cache', $items);
        $this->assertGreaterThan(array_search('cache_driver', $items, true), array_search('config_cache', $items, true));
        $this->assertLessThan(array_search('file_permission', AutoTuning::ITEMS, true), array_search('config_cache', AutoTuning::ITEMS, true));

        $auto->apply(['config_cache']);
        $this->assertSame(['config:cache'], $this->commands);
        // never a route cache: the routes of the plugins are read on every request
        $this->assertNotContains('route:cache', $this->commands);

        // a server that keeps the server-wide buttons off (several sites on one code)
        $this->commands = [];
        config(['exment.tuning_server_actions' => false]);
        $this->assertArrayNotHasKey('config_cache', $auto->available());
        $auto->apply(['config_cache']);
        $this->assertSame([], $this->commands);
    }

    public function testNothingWhereTheServerKeepsTuning(): void
    {
        AutoTuning::$redisAnswers = fn () => true;
        config(['exment.features_locked' => 'tuning']);
        $auto = new AutoTuning(true);
        $this->assertSame([], $auto->available());
        $this->assertSame(['done' => [], 'failed' => []], $auto->apply(AutoTuning::ITEMS));
        $this->assertSame([], $this->envWrites);
        $this->assertSame([], $this->commands);
    }

    public function testTheLastResultIsKept(): void
    {
        $this->assertNull(AutoTuning::last());
        AutoTuning::$artisan = function (string $command) {
            throw new \RuntimeException('cannot write bootstrap/cache');
        };
        $auto = new AutoTuningRecorder(true);
        $auto->need = ['exment_cache' => true, 'config_cache' => true];
        $auto->apply(['exment_cache', 'assets', 'config_cache']);

        $last = AutoTuning::last();
        $this->assertNotNull($last);
        $this->assertTrue($last['setup']);
        $this->assertSame([
            ['key' => 'exment_cache', 'status' => AutoTuning::DONE, 'message' => ''],
            ['key' => 'assets', 'status' => AutoTuning::ALREADY, 'message' => ''],
            ['key' => 'config_cache', 'status' => AutoTuning::FAILED, 'message' => 'cannot write bootstrap/cache'],
        ], $last['results']);
    }

    public function testACommandInARequestKeepsTheApplication(): void
    {
        // config:cache boots a second application, which becomes app(): the setup would then
        // log the new administrator in to it and lose the login
        AutoTuning::$artisan = null;
        $app = app();
        \Artisan::command('exment-test:auto-tuning-swap', function () {
            \Illuminate\Container\Container::setInstance(new \Illuminate\Container\Container());
        });
        AutoTuning::artisan('exment-test:auto-tuning-swap');
        $this->assertSame($app, app());
        $this->assertSame($app, \Illuminate\Support\Facades\Facade::getFacadeApplication());
    }

    protected function tempFile(int $mode): string
    {
        $file = sys_get_temp_dir() . '/exment_auto_tuning_' . uniqid('', true);
        file_put_contents($file, "X=1\n");
        chmod($file, $mode);
        return $this->tempFiles[] = $file;
    }
}

/**
 * AutoTuning with the needs set by the test and the changes only recorded.
 */
class AutoTuningRecorder extends AutoTuning
{
    /** @var array<string, bool> */
    public $need = [];

    /** @var array<string> */
    public $fail = [];

    /** @var array<string> */
    public $calls = [];

    protected function needs(string $key): bool
    {
        return $this->need[$key] ?? false;
    }

    protected function applyExmentCache(): void
    {
        $this->record('exment_cache');
    }

    protected function applyAssets(): void
    {
        $this->record('assets');
    }

    protected function applyFilePermission(): void
    {
        $this->record('file_permission');
    }

    protected function record(string $key): void
    {
        if (in_array($key, $this->fail, true)) {
            throw new \RuntimeException('failed: ' . $key);
        }
        $this->calls[] = $key;
    }
}
