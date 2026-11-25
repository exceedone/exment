<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Jobs\WriteLogsJob;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\LogManagement\LogCatalog;
use Exceedone\Exment\Services\LogManagement\LogWriter;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Auth\Database\OperationLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

/**
 * The records of the logs written after the response (ISS02903): the same rows as before, only
 * later; by the queue; a record that cannot be written is kept in a file.
 */
class LogWriterTest extends UnitTestBase
{
    /** @var int the size of the file of the records not written, before the test */
    protected $fallbackSize = 0;

    protected function setUp(): void
    {
        parent::setUp();
        LogCatalog::reset();
        LoginLog::resetTableExists();
        LogWriter::reset();
        $path = LogWriter::fallbackPath();
        $this->fallbackSize = is_file($path) ? intval(filesize($path)) : 0;
        \DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        LogWriter::reset();
        \DB::rollBack();
        $path = LogWriter::fallbackPath();
        if (is_file($path)) {
            if ($this->fallbackSize === 0) {
                @unlink($path);
            } else {
                $handle = fopen($path, 'r+');
                if ($handle !== false) {
                    ftruncate($handle, max(0, $this->fallbackSize));
                    fclose($handle);
                }
            }
        }
        config(['exment.log_write_mode' => LogWriter::MODE_AFTER_RESPONSE]);
        System::clearCache();
        parent::tearDown();
    }

    /**
     * The logins of a request, as they are written.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function logins(): array
    {
        $request = Request::create('/admin/auth/login', 'POST', ['username' => 'nobody'], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'TestAgent/2.0']);
        $this->app->instance('request', $request);
        $user = LoginUser::whereHas('base_user', function ($query) {
            $query->where('value->user_code', TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        })->first() ?? LoginUser::first();
        $this->assertNotNull($user);
        $last = intval(LoginLog::query()->max('id'));

        event(new Failed('admin', null, ['username' => 'nobody', 'password' => 'secret']));
        event(new Failed('admin', $user, ['username' => 'admin', 'password' => 'wrong', 'login_type' => 'pure']));
        event(new Lockout($request));
        event(new Login('admin', $user, false));
        LogWriter::end();

        return LoginLog::query()->where('id', '>', $last)->orderBy('id')->get()->map(function (LoginLog $row) {
            return array_diff_key($row->getAttributes(), ['id' => true, 'created_at' => true]);
        })->all();
    }

    public function testTheSameRowsAreWrittenAfterTheResponse(): void
    {
        config(['exment.log_write_mode' => LogWriter::MODE_SYNC]);
        LogWriter::begin();
        $sync = $this->logins();
        $this->assertCount(4, $sync);

        config(['exment.log_write_mode' => LogWriter::MODE_AFTER_RESPONSE]);
        LogWriter::begin();
        $before = LoginLog::query()->count();
        event(new Failed('admin', null, ['username' => 'nobody', 'password' => 'secret']));
        $this->assertSame($before, LoginLog::query()->count(), 'not written before the end of the request');
        $this->assertSame(1, LogWriter::pendingCount());
        LogWriter::reset();

        LogWriter::begin();
        $after = $this->logins();
        $this->assertSame($sync, $after);
        $this->assertSame(0, LogWriter::pendingCount());
    }

    public function testOutsideARequestARecordIsWrittenAtOnce(): void
    {
        $before = OperationLog::query()->count();
        LogWriter::insert(OperationLog::class, ['user_id' => 0, 'path' => 'admin/test', 'method' => 'GET', 'ip' => '203.0.113.9', 'input' => '[]']);
        $this->assertSame($before + 1, OperationLog::query()->count());
        $row = OperationLog::query()->orderByDesc('id')->firstOrFail();
        $this->assertNotNull($row->created_at, 'the timestamps of the model');
        $this->assertNotNull($row->updated_at);
    }

    public function testTheOtherWritesWaitToo(): void
    {
        $done = [];
        LogWriter::begin();
        LogWriter::defer('a test', function () use (&$done) {
            $done[] = 'written';
        });
        $this->assertSame([], $done);
        LogWriter::end();
        $this->assertSame(['written'], $done);

        // a write that fails does not stop the others
        LogWriter::begin();
        LogWriter::defer('a test', function () {
            throw new \RuntimeException('no');
        });
        LogWriter::defer('a test', function () use (&$done) {
            $done[] = 'again';
        });
        LogWriter::end();
        $this->assertSame(['written', 'again'], $done);
    }

    public function testALongRequestWritesOnTheWay(): void
    {
        $before = OperationLog::query()->count();
        LogWriter::begin();
        for ($i = 0; $i < LogWriter::MAX_PENDING; $i++) {
            LogWriter::insert(OperationLog::class, ['user_id' => 0, 'path' => 'admin/test/' . $i, 'method' => 'GET', 'ip' => '203.0.113.9', 'input' => '[]']);
        }
        $this->assertSame(0, LogWriter::pendingCount());
        $this->assertSame($before + LogWriter::MAX_PENDING, OperationLog::query()->count());
    }

    public function testByTheQueue(): void
    {
        Queue::fake();
        config(['exment.log_write_mode' => LogWriter::MODE_QUEUE, 'queue.default' => 'database']);
        $before = OperationLog::query()->count();
        LogWriter::begin();
        LogWriter::insert(OperationLog::class, ['user_id' => 0, 'path' => 'admin/queued', 'method' => 'GET', 'ip' => '203.0.113.9', 'input' => '[]']);
        LogWriter::end();
        $this->assertSame($before, OperationLog::query()->count(), 'sent to the queue');

        $jobs = Queue::pushed(WriteLogsJob::class);
        $this->assertCount(1, $jobs);
        /** @var WriteLogsJob $job */
        $job = $jobs->first();
        $job->handle();
        $this->assertSame($before + 1, OperationLog::query()->count());
        $this->assertSame('admin/queued', OperationLog::query()->orderByDesc('id')->value('path'));

        // without a queue: after the response
        config(['queue.default' => 'sync']);
        LogWriter::begin();
        $this->assertSame(LogWriter::MODE_AFTER_RESPONSE, LogWriter::mode());
        LogWriter::end();
        $this->assertSame(LogWriter::MODE_SYNC, LogWriter::mode(), 'outside a request');
    }

    public function testARecordNotWrittenIsKeptInTheFile(): void
    {
        $before = OperationLog::query()->count();
        $good = ['user_id' => 0, 'path' => 'admin/good', 'method' => 'GET', 'ip' => '203.0.113.9', 'input' => '[]', 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()];
        $bad = ['user_id' => 0, 'path' => 'admin/bad', 'no_such_column' => 'x'];
        LogWriter::writeRows(null, (new OperationLog())->getTable(), [$good, $bad, $good]);
        $this->assertSame($before + 2, OperationLog::query()->count(), 'the good rows of the INSERT are written');

        $lines = $this->fallbackLines();
        $this->assertCount(1, $lines);
        $this->assertSame((new OperationLog())->getTable(), $lines[0]['table']);
        $this->assertSame('admin/bad', $lines[0]['row']['path']);
        $this->assertArrayHasKey('error', $lines[0]);
        if (DIRECTORY_SEPARATOR === '/' && $this->fallbackSize === 0) {
            $this->assertSame('600', substr(sprintf('%o', fileperms(LogWriter::fallbackPath())), -3));
        }

        // a table that is not there: no error, the row in the file
        LogWriter::writeRows(null, 'no_such_log_table', [$good]);
        $this->assertCount(2, $this->fallbackLines());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fallbackLines(): array
    {
        $path = LogWriter::fallbackPath();
        if (!is_file($path)) {
            return [];
        }
        $text = substr(strval(file_get_contents($path)), $this->fallbackSize);
        return array_values(array_map(function ($line) {
            return (array) json_decode($line, true);
        }, array_filter(explode("\n", $text))));
    }
}
