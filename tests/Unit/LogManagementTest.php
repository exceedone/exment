<?php

namespace Exceedone\Exment\Tests\Unit;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\LogManagement\LogArchiver;
use Exceedone\Exment\Services\LogManagement\LogCatalog;
use Exceedone\Exment\Services\ScheduledJobs\ScheduledJobs;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * The log management (ISS02839), first part: the kinds of logs and how long they are kept, the
 * deleting of the old ones, the record of the logins, and the permission "Audit".
 */
class LogManagementTest extends UnitTestBase
{
    /** @var array<int, string> */
    protected $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        LogCatalog::reset();
        LoginLog::resetTableExists();
        \DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        \DB::rollBack();
        foreach ($this->files as $file) {
            @unlink($file);
        }
        LogCatalog::reset();
        System::clearCache();
        parent::tearDown();
    }

    public function testTheKindsOfLogs(): void
    {
        $types = LogCatalog::types();
        foreach (['operation_log', 'login_log', 'app_log'] as $key) {
            $this->assertArrayHasKey($key, $types);
            $this->assertSame(['key', 'label', 'location', 'retention_days', 'archive', 'compress', 'size_bytes', 'count', 'managed_by'], array_keys($types[$key]));
            foreach (['en', 'ja', 'de', 'es', 'ko', 'vi', 'zh_CN'] as $locale) {
                $this->assertStringStartsNotWith('exment::', strval(trans($types[$key]['label'], [], $locale)), "$key in $locale");
            }
        }
        $this->assertSame('exment:schedule', $types['operation_log']['managed_by'], 'the operation log is deleted by its own setting');
        $this->assertSame(LogCatalog::DEFAULT_LOGIN_LOG_DAYS, $types['login_log']['retention_days']);

        LogCatalog::register('permission_change', ['label' => 'x', 'retention' => function () {
            return 30;
        }]);
        $this->assertSame(30, LogCatalog::types()['permission_change']['retention_days'], 'a kind another feature adds');
    }

    public function testTheOldLoginRecordsAreDeleted(): void
    {
        $this->assertTrue(LoginLog::tableExists());
        // today: the records the other tests write now are within the days kept
        $now = Carbon::now();
        $old = LoginLog::create(['result' => LoginLog::RESULT_SUCCESS, 'created_at' => $now->copy()->subDays(11)]);
        $new = LoginLog::create(['result' => LoginLog::RESULT_SUCCESS, 'created_at' => $now->copy()->subDays(9)]);

        System::log_retention_login_log(10);
        System::clearCache();
        $this->assertGreaterThanOrEqual(1, LogCatalog::prune($now)['login_log'] ?? 0);
        $this->assertNull(LoginLog::find($old->id));
        $this->assertNotNull(LoginLog::find($new->id));

        // 0: kept until deleted by hand
        System::log_retention_login_log(0);
        System::clearCache();
        $this->assertNull(LogCatalog::types()['login_log']['retention_days']);
        $this->assertArrayNotHasKey('login_log', LogCatalog::prune($now->copy()->addYears(5)));
        $this->assertNotNull(LoginLog::find($new->id));
    }

    public function testTheOldDailyFilesOfTheApplicationLogAreDeleted(): void
    {
        $dir = storage_path('logs');
        @mkdir($dir, 0777, true);
        $old = $dir . '/logtest-2000-01-01.log';
        $new = $dir . '/logtest-' . Carbon::now()->format('Y-m-d') . '.log';
        $single = $dir . '/logtest.log';
        foreach ([$old, $new, $single] as $file) {
            file_put_contents($file, 'x');
            $this->files[] = $file;
        }
        $deleted = LogCatalog::prune()['app_log'] ?? 0;
        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new, 'a day within the days kept');
        $this->assertFileExists($single, 'the file being written is never deleted');
    }

    public function testTheLoginsAreRecorded(): void
    {
        $request = Request::create('/admin/auth/login', 'POST', ['username' => 'nobody'], [], [], ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'TestAgent/1.0']);
        $this->app->instance('request', $request);
        $user = LoginUser::whereHas('base_user', function ($query) {
            $query->where('value->user_code', TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        })->first() ?? LoginUser::first();
        $this->assertNotNull($user);

        event(new Failed('admin', null, ['username' => 'nobody', 'password' => 'secret']));
        event(new Failed('admin', $user, ['username' => 'admin', 'password' => 'wrong', 'login_type' => 'pure']));
        event(new Lockout($request));
        event(new Login('admin', $user, false));
        // the API's guard is not the pages'
        event(new Login('adminapi', $user, false));

        $rows = LoginLog::query()->orderByDesc('id')->limit(4)->get()->reverse()->values();
        $this->assertCount(4, $rows);
        $this->assertSame([LoginLog::RESULT_FAILURE, LoginLog::RESULT_FAILURE, LoginLog::RESULT_LOCKOUT, LoginLog::RESULT_SUCCESS], $rows->pluck('result')->all());
        $this->assertSame(LoginLog::REASON_UNKNOWN_USER, $rows[0]->failure_reason);
        // what was typed is not kept (part 4): it may be a password in the wrong field
        $this->assertNull($rows[0]->login_name);
        $this->assertNull($rows[0]->base_user_id);
        $this->assertSame(LoginLog::REASON_WRONG_PASSWORD, $rows[1]->failure_reason);
        $this->assertEquals($user->base_user_id, $rows[3]->base_user_id);
        $this->assertSame('pure', $rows[3]->provider);
        $this->assertSame('203.0.113.7', $rows[3]->ip);
        $this->assertSame('TestAgent/1.0', $rows[3]->user_agent);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('secret', strval(json_encode($row->toArray())), 'no password is kept');
        }
    }

    /**
     * Part 3: the old logs go in a gzip file a month before they are deleted, the file grows when
     * the month gets more, and the archives older than the years kept are deleted.
     */
    public function testTheArchive(): void
    {
        $root = storage_path('app/' . LogArchiver::ROOT);
        $before = glob($root . '/*/*.gz') ?: [];
        System::log_archive_enabled(true);
        System::log_archive_driver(LogArchiver::DRIVER_LOCAL);
        System::log_retention_login_log(10);
        System::clearCache();
        try {
            $now = Carbon::now();
            $first = $now->copy()->subDays(40);
            LoginLog::create(['result' => LoginLog::RESULT_FAILURE, 'login_name' => 'archive-test-one', 'created_at' => $first]);
            LogCatalog::prune($now);
            $path = $root . '/login_log/login_log-' . $first->format('Y-m') . '.csv.gz';
            $this->assertFileExists($path);
            $this->files[] = $path;
            if (DIRECTORY_SEPARATOR === '/') {
                $this->assertSame('600', substr(sprintf('%o', fileperms($path)), -3), 'readable by the owner only');
            }

            // the same month again: one more gzip member, read as one file
            LoginLog::create(['result' => LoginLog::RESULT_SUCCESS, 'login_name' => 'archive-test-two', 'created_at' => $first]);
            LogCatalog::prune($now);
            $text = implode('', gzfile($path) ?: []);
            $this->assertStringContainsString('archive-test-one', $text);
            $this->assertStringContainsString('archive-test-two', $text);
            $this->assertSame(1, substr_count($text, 'created_at,result'), 'the header once');
            $this->assertArrayHasKey(LogArchiver::ROOT . '/login_log/login_log-' . $first->format('Y-m') . '.csv.gz', LogArchiver::files());
            $this->assertTrue(LogCatalog::types()['login_log']['archive']);

            // the daily file of the application log: in the archive of its month, then deleted
            $log = storage_path('logs/archivetest-2001-02-03.log');
            file_put_contents($log, 'archived line');
            $this->files[] = $log;
            LogCatalog::prune($now);
            $this->assertFileDoesNotExist($log);
            $appArchive = $root . '/app_log/app_log-2001-02.log.gz';
            $this->files[] = $appArchive;
            $this->assertStringContainsString('archived line', implode('', gzfile($appArchive) ?: []));

            // kept 1 year: the archive of 2001 goes
            System::log_archive_years(1);
            System::clearCache();
            $this->assertGreaterThanOrEqual(1, LogArchiver::prune($now));
            $this->assertFileDoesNotExist($appArchive);
            $this->assertFileExists($path, 'a recent month stays');
        } finally {
            foreach (array_diff(glob($root . '/*/*.gz') ?: [], $before) as $made) {
                @unlink($made);
            }
        }
    }

    public function testThePermissionAuditAndTheJob(): void
    {
        $this->assertContains(Permission::AUDIT, Permission::SYSTEM_ROLE_PERMISSIONS);
        $admin = LoginUser::whereHas('base_user', function ($query) {
            $query->where('value->user_code', TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        })->first();
        if (isset($admin)) {
            $this->assertTrue(Permission::canAudit($admin), 'a system administrator');
        }
        $this->assertFalse(Permission::canAudit(null) && !\Exment::user(), 'nobody logged in');
        $this->assertArrayHasKey('exment:log-maintenance', ScheduledJobs::JOBS);
        foreach (['en', 'ja', 'de', 'es', 'ko', 'vi', 'zh_CN'] as $locale) {
            $this->assertStringStartsNotWith('exment::', strval(trans('exment::exment.scheduled_jobs.jobs.log_maintenance', [], $locale)), $locale);
            $this->assertStringStartsNotWith('exment::', strval(trans('exment::exment.role_group.role_type_option_system.audit.label', [], $locale)), $locale);
        }
    }
}
