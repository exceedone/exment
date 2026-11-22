<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\SystemRequire\Tuning;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The checks of the tuning page that come from what was found slow on a server (ISS02823):
 * the scheduler, PHP-FPM, the route cache, the permissions of the secret files, OPcache
 * being full, the redis extension, the files of the screens, and the database.
 */
class TuningServerChecksTest extends UnitTestBase
{
    /** @var array<string> */
    protected $tempFiles = [];

    /**
     * @return void
     */
    protected function init()
    {
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        Tuning\ScheduleStatus::$path = null;
        parent::tearDown();
    }

    protected function tempFile(string $contents = ''): string
    {
        $file = sys_get_temp_dir() . '/exment_tuning_' . uniqid('', true);
        file_put_contents($file, $contents);
        return $this->tempFiles[] = $file;
    }

    /**
     * Nothing recorded, or nothing for 10 minutes: cron does not run the scheduler.
     *
     * @return void
     */
    public function testScheduleWithoutCron()
    {
        Tuning\ScheduleStatus::$path = $this->tempFile();
        unlink(Tuning\ScheduleStatus::$path);

        $check = new Tuning\ScheduleTuning();
        $this->assertFalse($check->alive());
        $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
        $this->assertSame(exmtrans('tuning.item.schedule.never'), $check->getResultText());
        $this->assertSame(exmtrans('tuning.item.schedule.warning'), $check->getMessage());
        $this->assertStringContainsString('nice -n 15 php artisan schedule:run', (string) $check->getFixText());

        $now = time();
        Tuning\ScheduleStatus::record('exment:maintenance tick', 0.5, $now - Tuning\ScheduleTuning::STALE_SECONDS - 60);
        $check = new Tuning\ScheduleTuning();
        $check->now = $now;
        $this->assertFalse($check->alive());
        $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
    }

    /**
     * Light tasks every minute: OK. A heavy task: listed, and a warning.
     *
     * @return void
     */
    public function testScheduleHeavyTasks()
    {
        Tuning\ScheduleStatus::$path = $this->tempFile();
        $now = 1800000000;
        Tuning\ScheduleStatus::record('exment:maintenance tick', 0.4, $now - 120);
        Tuning\ScheduleStatus::record('exment:maintenance tick', 0.6, $now - 60);
        Tuning\ScheduleStatus::record('exment:schedule', 1.0, $now - 60);

        $check = new Tuning\ScheduleTuning();
        $check->now = $now;
        $this->assertTrue($check->alive());
        $this->assertSame([], $check->heavyTasks());
        $this->assertSame(1.6, $check->slowestMinute(), 'the tasks of one minute together');
        $this->assertSame(SystemRequireResult::OK, $check->checkResult());

        Tuning\ScheduleStatus::record('exment:safetywatch', 8.0, $now);
        $check = new Tuning\ScheduleTuning();
        $check->now = $now;
        $this->assertSame(['exment:safetywatch' => 8.0], $check->heavyTasks());
        $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
        $this->assertSame(exmtrans('tuning.item.schedule.warning_heavy'), $check->getMessage());
        $this->assertStringContainsString('exment:safetywatch (8s)', (string) $check->getResultText());

        // only the last hour is kept for the minutes
        Tuning\ScheduleStatus::record('exment:schedule', 0.1, $now + 3600 * 2);
        $minutes = Tuning\ScheduleStatus::read()['minutes'];
        $this->assertCount(1, $minutes);
    }

    /**
     * The name of a task is the command, without PHP and artisan.
     *
     * @return void
     */
    public function testScheduleTaskName()
    {
        $schedule = new Schedule();
        $event = $schedule->command('exment:safetywatch');
        $this->assertSame('exment:safetywatch', Tuning\ScheduleStatus::taskName($event));
        $event = $schedule->command('exment:maintenance tick');
        $this->assertSame('exment:maintenance tick', Tuning\ScheduleStatus::taskName($event));
    }

    /**
     * The safety check's watch is not started every minute when the safety check is not used.
     *
     * @return void
     */
    public function testSafetyWatchOnlyWhenUsed()
    {
        try {
            config(['exment.features_locked' => 'safety_check']);
            $this->assertFalse(\Exceedone\Exment\ExmentServiceProvider::safetyWatchUsed());

            $events = collect(app(Schedule::class)->events())->filter(function ($event) {
                return strpos((string) $event->command, 'exment:safetywatch') !== false;
            });
            foreach ($events as $event) {
                $this->assertFalse($event->filtersPass(app()), 'skipped while the safety check is off');
            }
        } finally {
            config(['exment.features_locked' => '']);
        }
    }

    /**
     * @return void
     */
    public function testPhpFpmPools()
    {
        $this->assertSame(10, Tuning\PhpFpmTuning::seconds('10s'));
        $this->assertSame(300, Tuning\PhpFpmTuning::seconds('300'));
        $this->assertSame(3600, Tuning\PhpFpmTuning::seconds('60m'));
        $this->assertSame(3600, Tuning\PhpFpmTuning::seconds('1h'));

        // no PHP-FPM: nothing to advise
        $check = new Tuning\PhpFpmTuning();
        $check->files = [];
        $this->assertSame(SystemRequireResult::OK, $check->checkResult());
        $this->assertSame(exmtrans('tuning.item.php_fpm.not_found'), $check->getResultText());

        // ondemand with FPM's default idle (10s): the first request after a quiet time waits
        $check = new Tuning\PhpFpmTuning();
        $check->files = [$this->tempFile("[global]\npid = run/php-fpm.pid\n[tenant1]\npm = ondemand\npm.max_children = 6\n")];
        $check->memoryMb = 4000;
        $this->assertSame(['tenant1' => 10], $check->shortIdlePools());
        $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
        $this->assertStringContainsString('tenant1', (string) $check->getMessage());

        // a longer idle, enough memory
        $check = new Tuning\PhpFpmTuning();
        $check->files = [$this->tempFile("[www]\npm = ondemand\npm.max_children = 10\npm.process_idle_timeout = 3600s\n")];
        $check->memoryMb = 4000;
        $this->assertSame([], $check->shortIdlePools());
        $this->assertSame(SystemRequireResult::OK, $check->checkResult());
        $this->assertStringContainsString('www: pm=ondemand, max_children=10, idle=3600s', (string) $check->getResultText());

        // too many for the memory, and too few
        $check = new Tuning\PhpFpmTuning();
        $check->files = [$this->tempFile("[www]\npm = dynamic\npm.max_children = 50\n")];
        $check->memoryMb = 1024;
        $this->assertTrue($check->tooManyChildren());
        $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());

        $check = new Tuning\PhpFpmTuning();
        $check->files = [$this->tempFile("[www]\npm = static\npm.max_children = 2\n")];
        $check->memoryMb = 4000;
        $this->assertTrue($check->tooFewChildren());
        $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());

        // php_admin_value[...] lines (an array in the ini): read the rest, no error (SaaS)
        $check = new Tuning\PhpFpmTuning();
        $check->files = [$this->tempFile("[tenant11]\npm = ondemand\npm.max_children = 6\npm.process_idle_timeout = 3600s\nphp_admin_value[memory_limit] = 1024M\nphp_admin_value[post_max_size] = 1088M\n")];
        $check->memoryMb = 4000;
        $this->assertSame('ondemand', $check->pools()['tenant11']['pm'] ?? null);
        $this->assertArrayNotHasKey('php_admin_value', $check->pools()['tenant11']);
        $this->assertSame(SystemRequireResult::OK, $check->checkResult());
        $this->assertStringContainsString('tenant11: pm=ondemand, max_children=6', (string) $check->getResultText());
    }

    /**
     * The route cache must not be there (the routes of the plugins come from the database).
     *
     * @return void
     */
    public function testRouteCache()
    {
        $path = sys_get_temp_dir() . '/exment_tuning_test_routes_' . getmypid() . '.php';
        $this->tempFiles[] = $path;
        $before = $_SERVER['APP_ROUTES_CACHE'] ?? null;
        $_SERVER['APP_ROUTES_CACHE'] = $path;
        try {
            $this->assertSame($path, app()->getCachedRoutesPath());
            $this->assertSame(SystemRequireResult::OK, (new Tuning\RouteCacheTuning())->checkResult());
            file_put_contents($path, '<?php');
            $this->assertSame(SystemRequireResult::WARNING, (new Tuning\RouteCacheTuning())->checkResult());
            $this->assertSame('php artisan route:clear', (new Tuning\RouteCacheTuning())->getFixText());
        } finally {
            if (is_null($before)) {
                unset($_SERVER['APP_ROUTES_CACHE']);
            } else {
                $_SERVER['APP_ROUTES_CACHE'] = $before;
            }
        }
    }

    /**
     * A secret file readable by every user of the server (644) is a warning; 640 is not.
     *
     * @return void
     */
    public function testFilePermission()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows has no such permissions');
        }
        $file = $this->tempFile('APP_KEY=secret');

        chmod($file, 0644);
        $check = new Tuning\FilePermissionTuning();
        $check->files = [$file];
        $this->assertSame([$file => '644'], $check->openFiles());
        $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
        $this->assertStringContainsString('chmod 640 ' . $file, (string) $check->getFixText());
        $this->assertStringNotContainsString('secret', (string) $check->getResultText(), 'never what is in the file');

        chmod($file, 0640);
        $check = new Tuning\FilePermissionTuning();
        $check->files = [$file];
        $this->assertSame([], $check->openFiles());
        $this->assertSame(SystemRequireResult::OK, $check->checkResult());
    }

    /**
     * How full OPcache is, from opcache_get_status.
     *
     * @return void
     */
    public function testOpcacheFull()
    {
        $status = function ($used, $free, $keys, $maxKeys, $oom = 0) {
            return [
                'memory_usage' => ['used_memory' => $used * 1048576, 'free_memory' => $free * 1048576, 'wasted_memory' => 0],
                'opcache_statistics' => ['num_cached_scripts' => $keys, 'num_cached_keys' => $keys, 'max_cached_keys' => $maxKeys, 'oom_restarts' => $oom],
            ];
        };

        $check = new Tuning\OpcacheTuning();
        $check->status = $status(100, 156, 9000, 32531);
        $this->assertFalse($check->full());
        $this->assertSame(100, $check->usage()['used_mb']);
        $this->assertSame(256, $check->usage()['total_mb']);

        $check = new Tuning\OpcacheTuning();
        $check->status = $status(250, 6, 9000, 32531);
        $this->assertTrue($check->full(), 'memory');

        $check = new Tuning\OpcacheTuning();
        $check->status = $status(100, 156, 16000, 16229);
        $this->assertTrue($check->full(), 'files');

        $check = new Tuning\OpcacheTuning();
        $check->status = $status(100, 156, 9000, 32531, 3);
        $this->assertTrue($check->full(), 'restarted for lack of memory');

        // unknown (restricted, or off): not judged
        $check = new Tuning\OpcacheTuning();
        $check->status = false;
        $this->assertNull($check->usage());
        $this->assertFalse($check->full());
    }

    /**
     * Redis without the redis extension of PHP is a warning of its own.
     *
     * @return void
     */
    public function testRedisExtension()
    {
        $before = [config('cache.default'), config('session.driver'), config('database.redis.client')];
        try {
            config(['cache.default' => 'redis', 'session.driver' => 'redis', 'database.redis.client' => 'phpredis']);
            $check = new Tuning\CacheDriverTuning();
            $check->redisExtension = false;
            $this->assertTrue($check->redisWithoutExtension());
            $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
            $this->assertSame(exmtrans('tuning.item.cache_driver.warning_extension'), $check->getMessage());

            $check = new Tuning\CacheDriverTuning();
            $check->redisExtension = true;
            $this->assertSame(SystemRequireResult::OK, $check->checkResult());

            config(['cache.default' => 'file', 'session.driver' => 'file']);
            $check = new Tuning\CacheDriverTuning();
            $check->redisExtension = false;
            $this->assertFalse($check->redisWithoutExtension());
            $this->assertSame(SystemRequireResult::WARNING, $check->checkResult(), 'files: Redis is recommended');
            $this->assertStringContainsString(exmtrans('tuning.item.cache_driver.extension_no'), (string) $check->getResultText());
        } finally {
            config(['cache.default' => $before[0], 'session.driver' => $before[1], 'database.redis.client' => $before[2]]);
        }
    }

    /**
     * The files of the screens: a commented-out file is not loaded, so it is not checked
     * (the login page had a commented-out jQuery-2.1.4 that tools read as a 404).
     *
     * @return void
     */
    public function testAssets()
    {
        $source = "<!-- <script src=\"{{ admin_asset(\"/vendor/x/jQuery-2.1.4.min.js\") }}\"></script> -->\n"
            . "{{-- <link href=\"{{ asset('old.css') }}\"> --}}\n"
            . "<script src=\"{{ admin_asset ('/vendor/x/jQuery-3.7.1.min.js') }}\"></script>\n"
            . "<link href=\"{{ admin_asset(\"vendor/exment/css/common.css\") }}\">\n";
        $this->assertSame(['/vendor/x/jQuery-3.7.1.min.js', '/vendor/exment/css/common.css'], Tuning\AssetsTuning::assetPaths($source));

        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/auth/layout.blade.php');
        $this->assertStringNotContainsString('jQuery-2.1.4', $layout);

        $check = new Tuning\AssetsTuning();
        $paths = $check->paths();
        $this->assertContains('/vendor/open-admin/AdminLTE/plugins/jQuery/jQuery-3.7.1.min.js', $paths);

        $check = new Tuning\AssetsTuning();
        $check->views = [$this->tempFile("<script src=\"{{ admin_asset('/vendor/exment/no-such-file.js') }}\"></script>")];
        $check->headers = false;
        $this->assertSame(['/vendor/exment/no-such-file.js'], $check->missing());
        $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
        $this->assertSame('php artisan exment:publish', $check->getFixText());

        // the browser cache, from the headers of a JS file
        $check = new Tuning\AssetsTuning();
        $check->views = [];
        $check->headers = ['cache-control' => 'max-age=604800, public'];
        $this->assertTrue($check->browserCache());
        $this->assertSame(SystemRequireResult::OK, $check->checkResult());

        $check = new Tuning\AssetsTuning();
        $check->views = [];
        $check->headers = ['cache-control' => 'no-cache'];
        $this->assertFalse($check->browserCache());
        $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
        $this->assertSame(exmtrans('tuning.item.assets.warning_cache'), $check->getMessage());

        $check = new Tuning\AssetsTuning();
        $check->views = [];
        $check->headers = false;
        $this->assertNull($check->browserCache(), 'not reachable: not judged');
        $this->assertSame(SystemRequireResult::OK, $check->checkResult());
    }

    /**
     * @return void
     */
    public function testSlowQueryLog()
    {
        $check = new Tuning\SlowQueryLogTuning();
        $check->variables = ['slow_query_log' => 'ON', 'long_query_time' => '1.000000'];
        $this->assertSame(SystemRequireResult::OK, $check->checkResult());

        $check = new Tuning\SlowQueryLogTuning();
        $check->variables = ['slow_query_log' => 'OFF', 'long_query_time' => '10.000000'];
        $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
        $this->assertStringContainsString('slow_query_log = 1', (string) $check->getFixText());

        $check = new Tuning\SlowQueryLogTuning();
        $check->variables = ['slow_query_log' => 'ON', 'long_query_time' => '10.000000'];
        $this->assertSame(SystemRequireResult::WARNING, $check->checkResult(), 'only the queries over 10 seconds');

        $check = new Tuning\SlowQueryLogTuning();
        $check->variables = false;
        $this->assertSame(SystemRequireResult::OK, $check->checkResult());
        $this->assertSame(exmtrans('tuning.item.slow_query_log.not_mysql'), $check->getResultText());

        // the real database answers (MySQL / MariaDB in the tests)
        $check = new Tuning\SlowQueryLogTuning();
        $this->assertContains($check->checkResult(), [SystemRequireResult::OK, SystemRequireResult::WARNING]);
    }

    /**
     * A search index column made by Exment has its index; one whose index is missing is listed.
     *
     * @return void
     */
    public function testIndexMissing()
    {
        // a search index column of the test data, made with its index
        $indexed = \Exceedone\Exment\Model\CustomColumn::indexEnabled()->get()->first(function ($column) {
            $table = $column->custom_table_cache;
            if (is_null($table) || !hasTable(getDBTableName($table))) {
                return false;
            }
            $dbColumn = $column->getIndexColumnName(false);
            return hasColumn(getDBTableName($table), $dbColumn)
                && \Schema::hasCustomIndex(getDBTableName($table), $dbColumn, "index_{$dbColumn}");
        });
        $this->assertNotNull($indexed, 'the test data has a search index column');
        $table = $indexed->custom_table_cache;
        $label = $table->table_view_name . ' / ' . $indexed->column_view_name;

        $check = new Tuning\IndexMissingTuning();
        $this->assertNotContains($label, $check->missing());
        $this->assertFalse($check->isServerWide());

        // a column that says "search index" but has no index (inserted bare, as after a failed change)
        $columnId = \DB::table('custom_columns')->insertGetId([
            'suuid' => short_uuid(), 'custom_table_id' => $table->id, 'column_name' => 'tuning_no_index_' . uniqid(),
            'column_view_name' => 'tuning no index', 'column_type' => 'text', 'options' => '{"index_enabled":"1"}',
        ]);
        try {
            $check = new Tuning\IndexMissingTuning();
            $this->assertContains($table->table_view_name . ' / tuning no index', $check->missing());
            $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
            $this->assertSame('php artisan exment:patchdata alter_index_all', $check->getFixText());
        } finally {
            \DB::table('custom_columns')->where('id', $columnId)->delete();
        }
    }

    /**
     * "php artisan exment:tuning" prints the same report and changes nothing.
     *
     * @return void
     */
    public function testCommand()
    {
        $configCached = Tuning\ConfigCacheTuning::cached();

        $this->assertSame(0, \Artisan::call('exment:tuning'));
        $output = \Artisan::output();
        $this->assertStringContainsString(exmtrans('tuning.command_header'), $output);
        $this->assertStringContainsString(exmtrans('tuning.item.schedule.label'), $output);
        $this->assertStringContainsString(exmtrans('tuning.item.php_fpm.label'), $output);

        $this->assertSame(0, \Artisan::call('exment:tuning', ['--only-warning' => true]));
        $this->assertSame($configCached, Tuning\ConfigCacheTuning::cached(), 'nothing is changed');
    }
}
