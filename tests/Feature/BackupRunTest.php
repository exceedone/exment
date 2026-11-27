<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Exceptions\BackupRunLockedException;
use Exceedone\Exment\Model\BackupRun;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\BackupRestore\BackupRuns;
use Exceedone\Exment\Services\BackupRestore\RunProgress;
use Exceedone\Exment\Services\BackupRestore\SecretMasker;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Services\LogManagement\LogWriter;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;

/**
 * The runs of the backup and the restore (ISS02943): the record, the lock, a run that stopped,
 * the progress and its log (no secret in it), the commands and the screen.
 *
 * The backups here hold the plugin folder only, as in BackupCoverageTest: the database part runs
 * mysqldump with table locks, which would wait on this test's open transaction. No restore runs.
 */
class BackupRunTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    private array $backupsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        // every change written at once (the record is written once a second at most)
        config(['exment.backup.save_interval' => 0]);
        Notification::fake();
        SecretMasker::flush();
        $this->backupsBefore = Storage::disk(Define::DISKNAME_BACKUP_SYNC)->files('list');
    }

    protected function tearDown(): void
    {
        $created = array_diff(Storage::disk(Define::DISKNAME_BACKUP_SYNC)->files('list'), $this->backupsBefore);
        if (count($created) > 0) {
            Storage::disk(Define::DISKNAME_BACKUP_SYNC)->delete(array_values($created));
        }
        // the files of the runs too (a status file of a restore left behind would answer the pages)
        /** @var \Illuminate\Support\Collection<int, BackupRun> $runs */
        $runs = BackupRun::query()->get();
        foreach ($runs as $run) {
            foreach ([BackupRuns::logFullPath($run), BackupRuns::statusPath($run->uuid), BackupRuns::consolePath($run), BackupRuns::tokenPath($run->uuid), storage_path(path_join(BackupRuns::LOG_DIR, $run->uuid . '.rate'))] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
        SecretMasker::flush();
        System::clearCache();
        parent::tearDown();
    }

    public function testAStartedRunHoldsTheLock(): void
    {
        $run = BackupRuns::start(BackupRun::KIND_BACKUP, BackupRun::TRIGGER_COMMAND, ['targets' => [BackupTarget::PLUGIN]]);

        $this->assertSame(BackupRun::STATUS_RUNNING, $run->status);
        $this->assertSame([BackupTarget::PLUGIN], $run->targets);
        $this->assertNotNull($run->started_at);
        $this->assertSame(36, strlen($run->uuid));

        try {
            BackupRuns::start(BackupRun::KIND_RESTORE, BackupRun::TRIGGER_SCREEN);
            $this->fail('a second run started while the first was running');
        } catch (BackupRunLockedException $e) {
            $this->assertSame(strval(exmtrans('backup.run.message.locked')), $e->getMessage());
            $this->assertSame($run->uuid, $e->active?->uuid);
        }

        // a queued run holds it too
        $run->status = BackupRun::STATUS_QUEUED;
        $run->save();
        $this->expectException(BackupRunLockedException::class);
        BackupRuns::start(BackupRun::KIND_BACKUP, BackupRun::TRIGGER_SCREEN);
    }

    public function testARunWithoutHeartbeatIsMarkedStalledAndAnotherMayStart(): void
    {
        $old = $this->makeRun(BackupRun::STATUS_RUNNING, Carbon::now()->subMinutes(BackupRuns::staleMinutes() + 5));
        $fresh = $this->makeRun(BackupRun::STATUS_SUCCEEDED, Carbon::now()->subMinutes(BackupRuns::staleMinutes() + 5));

        $run = BackupRuns::start(BackupRun::KIND_BACKUP, BackupRun::TRIGGER_COMMAND);

        $old->refresh();
        $this->assertSame(BackupRun::STATUS_STALLED, $old->status);
        $this->assertNotNull($old->finished_at);
        $this->assertSame(strval(exmtrans('backup.run.message.stalled', ['minutes' => BackupRuns::staleMinutes()])), $old->message);
        // a run that ended is not touched
        $this->assertSame(BackupRun::STATUS_SUCCEEDED, $fresh->refresh()->status);
        $this->assertSame(BackupRun::STATUS_RUNNING, $run->status);

        // the administrators are told
        $this->assertTrue(NotifyNavbar::withoutGlobalScopes()->where('notify_subject', 'like', '%' . exmtrans('backup.run.statuses.stalled') . '%')->exists());
    }

    public function testAQueuedRunThatNeverStartedIsStalledWithItsOwnReason(): void
    {
        $queued = $this->makeRun(BackupRun::STATUS_QUEUED, Carbon::now()->subMinutes(BackupRuns::staleMinutes() + 1));
        $queued->started_at = null;
        $queued->save();

        $this->assertSame(1, BackupRuns::markStalled());
        $this->assertSame(strval(exmtrans('backup.run.message.stalled_queued', ['minutes' => BackupRuns::staleMinutes()])), $queued->refresh()->message);
    }

    public function testARunOfThisServerIsNotStalledWhileItsProcessHoldsTheLock(): void
    {
        $old = $this->makeRun(BackupRun::STATUS_RUNNING, Carbon::now()->subHours(3));

        $lock = BackupRuns::acquireProcessLock();
        $this->assertNotNull($lock, 'the lock file can be held');
        try {
            $this->assertTrue(BackupRuns::processLocked());
            $this->assertSame(0, BackupRuns::markStalled());
            $this->assertSame(BackupRun::STATUS_RUNNING, $old->refresh()->status);

            $this->expectException(BackupRunLockedException::class);
            BackupRuns::start(BackupRun::KIND_BACKUP, BackupRun::TRIGGER_COMMAND);
        } finally {
            BackupRuns::releaseProcessLock($lock);
            $this->assertFalse(BackupRuns::processLocked());
        }
    }

    public function testTheProgressRecordsTheStagesThePercentAndTheLog(): void
    {
        $run = BackupRuns::start(BackupRun::KIND_BACKUP, BackupRun::TRIGGER_COMMAND, ['targets' => [BackupTarget::PLUGIN]]);
        $lines = [];
        $progress = new RunProgress($run, function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        $progress->begin();
        $progress->stage('prepare');
        $progress->stage('dump', 4);
        $progress->advance(1, 1, 100);
        $progress->advance(1, 1, 300);

        $run->refresh();
        $this->assertSame('dump', $run->stage);
        $this->assertSame(2, $run->stage_done);
        $this->assertSame(4, $run->stage_total);
        // prepare (2) done, half of dump (40 / 2) of 100
        $this->assertSame(22, $run->progress);
        $this->assertNotNull($run->heartbeat_at);
        $row = $run->stageRow('dump');
        $this->assertSame(2, $row['count'] ?? null);
        $this->assertSame(400, $row['bytes'] ?? null);
        $this->assertNotNull($run->stageRow('prepare')['finished_at'] ?? null);

        $status = $run->toStatusArray();
        $this->assertSame(['done', 'current', 'waiting', 'waiting', 'waiting'], array_column($status['stages'], 'state'));
        $this->assertSame(strval(exmtrans('backup.run.stages.dump')), $status['stage_label']);

        $progress->warn('a file was not copied', ['file' => 'a.txt']);
        $progress->stage('files', 0);
        $progress->finish(BackupRun::STATUS_SUCCEEDED);

        $run->refresh();
        $this->assertSame(BackupRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(100, $run->progress);
        $this->assertSame(1, $run->warnings);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(1, $run->stageRow('dump')['warnings'] ?? null);
        $this->assertNull($run->remainingSeconds());

        $log = strval(BackupRuns::readLog($run));
        $this->assertStringContainsString('[INFO] [prepare] stage start', $log);
        $this->assertStringContainsString('[INFO] [dump] stage end', $log);
        $this->assertStringContainsString('[WARNING] [dump] a file was not copied {"file":"a.txt"}', $log);
        $this->assertStringContainsString('[INFO] [files] finish', $log);
        $this->assertSame(BackupRuns::logRelativePath($run), $run->log_path);
        $this->assertStringNotContainsString(storage_path(), strval(json_encode($run->toStatusArray())), 'no path of the server is shown');

        // the command's output
        $this->assertContains('[2/5] ' . exmtrans('backup.run.stages.dump'), $lines);
        $this->assertFalse(BackupRuns::processLocked(), 'the lock of the process is released at the end');
    }

    public function testTheLogAndTheMessageNeverHoldASecret(): void
    {
        $password = 'Claude-Db-Pw-' . Str::random(12);
        $key = 'base64:' . base64_encode(random_bytes(32));
        config([
            'database.connections.mysql.password' => $password,
            'app.key' => $key,
        ]);
        SecretMasker::flush();

        $run = BackupRuns::start(BackupRun::KIND_BACKUP, BackupRun::TRIGGER_COMMAND);
        $progress = new RunProgress($run);
        $progress->begin();
        $progress->stage('dump', 1);
        $progress->error("mysqldump -h db -u exment --password='{$password}' failed\nmysql:host=db;port=3306;dbname=exment", [
            'password' => 'another-secret-value',
            'command' => "mysql --password={$password}",
            'url' => "redis://default:{$password}@redis:6379",
        ]);
        $progress->info('the key', ['value' => $key, 'raw' => substr($key, 7)]);
        $progress->finish(BackupRun::STATUS_FAILED, "PDOException: SQLSTATE[HY000] [1045] password={$password} api_key: abcdef123456");

        $log = strval(BackupRuns::readLog($run));
        $raw = strval(file_get_contents(BackupRuns::logFullPath($run)));
        $run->refresh();
        foreach ([$log, $raw, strval($run->message), strval(json_encode($run->toStatusArray()))] as $text) {
            $this->assertStringNotContainsString($password, $text);
            $this->assertStringNotContainsString(substr($key, 7), $text);
            $this->assertStringNotContainsString('another-secret-value', $text);
            $this->assertStringNotContainsString('dbname=exment', $text);
            $this->assertStringNotContainsString('abcdef123456', $text);
        }
        $this->assertStringContainsString(SecretMasker::MASK, $raw);
        $this->assertStringContainsString('redis://' . SecretMasker::MASK . '@redis:6379', $raw);
        // the file is not readable by others
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame('0600', substr(sprintf('%o', fileperms(BackupRuns::logFullPath($run))), -4));
        }
    }

    public function testTheMaskerHidesWhatLooksLikeASecret(): void
    {
        config(['database.connections.mysql.password' => 'pw-of-the-db-9876']);
        SecretMasker::flush();

        $cases = [
            'connect with pw-of-the-db-9876 now' => 'pw-of-the-db-9876',
            'mysqldump --password=hunter2hunter2 -u root' => 'hunter2hunter2',
            'mysqldump --password "quoted secret" -u root' => 'quoted secret',
            '{"secret":"s3cr3t-value"}' => 's3cr3t-value',
            'PASSWORD = plain-value-here' => 'plain-value-here',
            'token: tkn_0123456789' => 'tkn_0123456789',
            'https://user:p4ssw0rd@example.com/path' => 'p4ssw0rd',
            'sqlsrv:Server=db,1433;Database=exment' => 'Database=exment',
            'APP_KEY=base64:QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVo=' => 'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVo=',
        ];
        foreach ($cases as $text => $secret) {
            $masked = SecretMasker::mask($text);
            $this->assertStringNotContainsString($secret, $masked, $text);
            $this->assertStringContainsString(SecretMasker::MASK, $masked, $text);
        }

        // what is not a secret stays as it is
        $this->assertSame('table exm__abc: 120 rows, 4096 bytes', SecretMasker::mask('table exm__abc: 120 rows, 4096 bytes'));
        $this->assertSame(['password' => SecretMasker::MASK, 'rows' => 3, 'nested' => ['api_key' => SecretMasker::MASK, 'name' => 'a']], SecretMasker::maskArray(['password' => 'x', 'rows' => 3, 'nested' => ['api_key' => 'y', 'name' => 'a']]));
    }

    public function testTheBackupCommandRecordsTheRunAndPrintsItsProgress(): void
    {
        $this->runArtisan('exment:backup', ['--target' => BackupTarget::PLUGIN])
            ->expectsOutputToContain('[1/5] ' . exmtrans('backup.run.stages.prepare'))
            ->expectsOutputToContain('[5/5] ' . exmtrans('backup.run.stages.upload'))
            ->expectsOutputToContain(strval(exmtrans('backup.run.statuses.succeeded')))
            ->assertExitCode(0)
            ->run();

        /** @var BackupRun $run */
        $run = BackupRun::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(BackupRun::KIND_BACKUP, $run->kind);
        $this->assertSame(BackupRun::TRIGGER_COMMAND, $run->triggered_by);
        $this->assertSame(BackupRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(100, $run->progress);
        $this->assertSame([BackupTarget::PLUGIN], $run->targets);
        $this->assertNotNull($run->file_name);
        $this->assertTrue(Storage::disk(Define::DISKNAME_BACKUP_SYNC)->exists("list/{$run->file_name}.zip"));
        $this->assertGreaterThan(0, intval($run->file_size));
        $this->assertSame(['prepare', 'dump', 'files', 'archive', 'upload'], array_column($run->stages ?? [], 'name'));
        $this->assertGreaterThan(0, intval($run->stageRow('files')['count'] ?? 0), 'the plugin files are counted');

        // the administrators are told, and the operation log has its start and its end
        $this->assertTrue(NotifyNavbar::withoutGlobalScopes()->where('notify_subject', strval(exmtrans('backup.run.notify.subject', [
            'kind' => exmtrans('backup.run.kinds.backup'),
            'status' => exmtrans('backup.run.statuses.succeeded'),
        ])))->exists());
        LogWriter::end();
        foreach (['start', 'finish'] as $event) {
            $this->assertTrue(\DB::table('admin_operation_log')->where('path', 'like', "%backup/runs/{$run->uuid}/{$event}")->exists(), $event);
        }
    }

    public function testTheBackupCommandIsRefusedWhileAnotherRuns(): void
    {
        $this->makeRun(BackupRun::STATUS_RUNNING, Carbon::now());
        $before = BackupRun::query()->count();

        $this->runArtisan('exment:backup', ['--target' => BackupTarget::PLUGIN])
            ->expectsOutputToContain(strval(exmtrans('backup.run.message.locked')))
            ->assertExitCode(1)
            ->run();

        $this->assertSame($before, BackupRun::query()->count(), 'no run is recorded');
    }

    public function testTheSaveOfTheScreenRecordsARun(): void
    {
        System::backup_target([BackupTarget::PLUGIN]);
        System::clearCache();

        $response = $this->post(admin_url('backup/save'))
            ->assertOk()
            ->assertJson(['result' => true, 'message' => trans('admin.save_succeeded')]);

        /** @var BackupRun $run */
        $run = BackupRun::query()->where('uuid', strval($response->json('run')))->firstOrFail();
        $this->assertSame(BackupRun::TRIGGER_SCREEN, $run->triggered_by);
        $this->assertSame(BackupRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(intval(\Exment::user()?->id), $run->created_user_id);

        // a second one while a run goes on is refused
        $this->makeRun(BackupRun::STATUS_RUNNING, Carbon::now());
        $this->post(admin_url('backup/save'))
            ->assertOk()
            ->assertJson(['result' => false, 'message' => exmtrans('backup.run.message.locked')]);
    }

    public function testOnlyTheLastRunsAreKept(): void
    {
        // other tests (and a real site) may have left runs behind
        \DB::table(BackupRun::TABLE)->delete();
        config(['exment.backup.keep_runs' => 2]);
        $runs = [];
        foreach (range(1, 4) as $i) {
            $runs[] = $run = $this->makeRun(BackupRun::STATUS_SUCCEEDED, Carbon::now()->subMinutes(10 - $i));
            BackupRuns::ensureLogDirectory();
            file_put_contents(BackupRuns::logFullPath($run), "log\n");
        }
        $active = $this->makeRun(BackupRun::STATUS_RUNNING, Carbon::now());

        $this->assertSame(2, BackupRuns::keep());

        $this->assertFalse(BackupRun::query()->whereKey($runs[0]->id)->exists());
        $this->assertFalse(BackupRun::query()->whereKey($runs[1]->id)->exists());
        $this->assertFalse(is_file(BackupRuns::logFullPath($runs[0])));
        $this->assertTrue(BackupRun::query()->whereKey($runs[3]->id)->exists());
        $this->assertTrue(BackupRun::query()->whereKey($active->id)->exists(), 'a run not over is kept');
    }

    public function testTheRunsArePutBackAfterARestoreReplacedTheTable(): void
    {
        $before = $this->makeRun(BackupRun::STATUS_SUCCEEDED, Carbon::now()->subDay());
        $current = BackupRuns::start(BackupRun::KIND_RESTORE, BackupRun::TRIGGER_COMMAND);
        $snapshot = BackupRuns::snapshot();

        // the restore replaced the table: the rows of the day of the backup, one of them running then
        \DB::table(BackupRun::TABLE)->delete();
        $fromBackup = $this->makeRun(BackupRun::STATUS_RUNNING, Carbon::now()->subWeek());

        $current->status = BackupRun::STATUS_SUCCEEDED;
        BackupRuns::reinstate($snapshot, $current);

        $this->assertSame(BackupRun::STATUS_STALLED, $fromBackup->refresh()->status);
        $this->assertSame(strval(exmtrans('backup.run.message.stalled_restored')), $fromBackup->message);
        $this->assertTrue(BackupRun::query()->where('uuid', $before->uuid)->where('status', BackupRun::STATUS_SUCCEEDED)->exists());
        $this->assertSame(1, BackupRun::query()->where('uuid', $current->uuid)->count());
        $this->assertSame(BackupRun::STATUS_SUCCEEDED, BackupRun::query()->where('uuid', $current->uuid)->value('status'));
        $this->assertTrue($current->exists);
        $this->assertSame($current->id, intval(BackupRun::query()->where('uuid', $current->uuid)->value('id')));
    }

    private function makeRun(string $status, Carbon $heartbeat): BackupRun
    {
        $run = new BackupRun();
        $run->uuid = (string) Str::uuid();
        $run->kind = BackupRun::KIND_BACKUP;
        $run->triggered_by = BackupRun::TRIGGER_COMMAND;
        $run->status = $status;
        $run->heartbeat_at = $heartbeat;
        $run->started_at = $heartbeat->copy()->subMinute();
        $run->finished_at = in_array($status, BackupRun::ACTIVE, true) ? null : $heartbeat;
        $run->save();

        return $run;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
