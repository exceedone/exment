<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Controllers\BackupController;
use Exceedone\Exment\Model\BackupRun;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\BackupRestore\BackupRuns;
use Exceedone\Exment\Services\BackupRestore\RunProgress;
use Exceedone\Exment\Services\BackupRestore\SecretMasker;
use Exceedone\Exment\Services\LogManagement\LogWriter;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The screen of the runs of the backup and the restore (ISS02943): the progress that is polled,
 * the history, the log shown and downloaded (no secret in it), and only the system administrators.
 */
class BackupRunScreenTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['exment.backup.save_interval' => 0]);
        Notification::fake();
        SecretMasker::flush();
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
    }

    protected function tearDown(): void
    {
        foreach (BackupRun::query()->get() as $run) {
            /** @var BackupRun $run */
            $path = BackupRuns::logFullPath($run);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        SecretMasker::flush();
        System::clearCache();
        parent::tearDown();
    }

    public function testThePageShowsTheRunGoingOnAndTheHistory(): void
    {
        $done = $this->finishedRun();
        $active = BackupRuns::start(BackupRun::KIND_BACKUP, BackupRun::TRIGGER_SCREEN);
        $progress = new RunProgress($active);
        $progress->begin();
        $progress->stage('prepare');
        $progress->stage('dump', 10);
        $progress->advance(5);

        $page = $this->get(admin_url('backup'))->assertOk();
        $page->assertSee('id="backup-progress"', false);
        $page->assertSee('id="backup-history"', false);
        $page->assertSee(exmtrans('backup.run.screen.history_header'));
        $page->assertSee(admin_urls('backup', 'runs', $done->uuid), false);
        $page->assertSee(admin_urls('backup', 'runs', $done->uuid, 'log'), false);
        // the run going on is given to the script, which polls it
        $page->assertSee(strval(json_encode($active->uuid)), false);
        $page->assertSee(exmtrans('backup.run.statuses.succeeded'));
        $progress->releaseLock();
    }

    public function testTheStatusIsPolledAsJson(): void
    {
        $run = BackupRuns::start(BackupRun::KIND_BACKUP, BackupRun::TRIGGER_SCREEN);
        $progress = new RunProgress($run);
        $progress->begin();
        $progress->stage('prepare');
        $progress->stage('dump', 4);
        $progress->advance(2);

        $current = $this->get(admin_url('backup/runs/current'), self::AJAX)->assertOk();
        $this->assertSame($run->uuid, $current->json('run.uuid'));

        $status = $this->get(admin_urls('backup', 'runs', $run->uuid, 'status'), self::AJAX)->assertOk();
        $status->assertJson(['run' => [
            'uuid' => $run->uuid,
            'kind' => 'backup',
            'status' => 'running',
            'active' => true,
            'stage' => 'dump',
            'progress' => 22,
            'stage_done' => 2,
            'stage_total' => 4,
        ]]);
        $this->assertCount(5, (array) $status->json('run.stages'));
        $this->assertSame('current', $status->json('run.stages.1.state'));
        $this->assertArrayHasKey('remaining_seconds', (array) $status->json('run'));
        $this->assertArrayNotHasKey('log_path', (array) $status->json('run'));
        $progress->finish(BackupRun::STATUS_SUCCEEDED);

        $this->get(admin_url('backup/runs/current'), self::AJAX)->assertOk()->assertJson(['run' => null]);
        $this->get(admin_urls('backup', 'runs', (string) Str::uuid(), 'status'), self::AJAX)->assertNotFound();
    }

    public function testAStalledRunIsShownAsStopped(): void
    {
        $run = $this->finishedRun(BackupRun::STATUS_RUNNING);
        $run->heartbeat_at = Carbon::now()->subMinutes(BackupRuns::staleMinutes() + 1);
        $run->save();

        $this->get(admin_urls('backup', 'runs', $run->uuid, 'status'), self::AJAX)->assertOk()
            ->assertJson(['run' => ['status' => 'stalled', 'active' => false, 'status_label' => exmtrans('backup.run.statuses.stalled')]]);
    }

    public function testTheLogIsShownAndDownloadedWithoutSecrets(): void
    {
        $password = 'Claude-Screen-Pw-' . Str::random(10);
        config(['database.connections.mysql.password' => $password]);
        SecretMasker::flush();

        $run = $this->finishedRun();
        BackupRuns::ensureLogDirectory();
        // a line written before the setting was known as a secret: masked again when it is read
        file_put_contents(BackupRuns::logFullPath($run), "2026-01-01 00:00:00 [ERROR] [dump] mysql --password={$password} failed\n2026-01-01 00:00:01 [INFO] [dump] table {\"table\":\"exm__abc\"}\n");

        $page = $this->get(admin_urls('backup', 'runs', $run->uuid))->assertOk();
        $page->assertSee(exmtrans('backup.run.screen.log_header'));
        $page->assertSee('exm__abc');
        $page->assertDontSee($password);
        $page->assertSee(exmtrans('backup.run.stages.dump'));

        $download = $this->get(admin_urls('backup', 'runs', $run->uuid, 'log'))->assertOk();
        $this->assertStringStartsWith('text/plain', strval($download->headers->get('Content-Type')));
        $this->assertStringContainsString('attachment;', strval($download->headers->get('Content-Disposition')));
        $body = strval($download->getContent());
        $this->assertStringContainsString('exm__abc', $body);
        $this->assertStringNotContainsString($password, $body);
        $this->assertStringContainsString(SecretMasker::MASK, $body);

        // the download is in the operation log
        LogWriter::end();
        $this->assertTrue(\DB::table('admin_operation_log')->where('path', 'like', "%backup/runs/{$run->uuid}/download")->exists());

        // a run without a log
        $other = $this->finishedRun();
        $this->get(admin_urls('backup', 'runs', $other->uuid))->assertOk()->assertSee(exmtrans('backup.run.screen.log_none'));
        $this->get(admin_urls('backup', 'runs', $other->uuid, 'log'))->assertNotFound();
    }

    public function testTheScopeChosenOnTheScreen(): void
    {
        $settings = ['database', 'plugin', 'attachment'];
        $this->assertSame(['database', 'plugin', 'attachment'], BackupController::targetsOfScope('all', $settings));
        $this->assertSame(['database'], BackupController::targetsOfScope('database', $settings));
        $this->assertSame(['plugin', 'attachment'], BackupController::targetsOfScope('files', $settings));
        // the settings have no folder: all of them
        $this->assertSame(['plugin', 'attachment', 'log', 'config'], BackupController::targetsOfScope('files', ['database']));
        // a scope not known, or a target not known, is as the settings
        $this->assertSame(['plugin'], BackupController::targetsOfScope('foo', ['plugin', '../etc']));

        System::backup_target(['plugin']);
        System::clearCache();
        $response = $this->post(admin_url('backup/save'), ['scope' => 'files'])->assertOk()->assertJson(['result' => true]);
        /** @var BackupRun $run */
        $run = BackupRun::query()->where('uuid', strval($response->json('run')))->firstOrFail();
        $this->assertSame(['plugin'], $run->targets);
        Storage::disk(Define::DISKNAME_BACKUP_SYNC)->delete("list/{$run->file_name}.zip");
    }

    public function testOnlyTheSystemAdministrators(): void
    {
        $run = $this->finishedRun();
        BackupRuns::ensureLogDirectory();
        file_put_contents(BackupRuns::logFullPath($run), "log\n");

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertEveryRouteIsForbidden($run);

        // the roles not used: Auth\Permission lets everyone in, the controller still does not
        System::permission_available(false);
        System::clearRequestSession();
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertEveryRouteIsForbidden($run);

        $this->assertSame(0, BackupRun::query()->where('id', '<>', $run->id)->count(), 'no run was started');
    }

    private function assertEveryRouteIsForbidden(BackupRun $run): void
    {
        $this->get(admin_url('backup'), self::AJAX)->assertForbidden();
        $this->get(admin_url('backup/runs/current'), self::AJAX)->assertForbidden();
        $this->get(admin_urls('backup', 'runs', $run->uuid, 'status'), self::AJAX)->assertForbidden();
        $this->get(admin_urls('backup', 'runs', $run->uuid), self::AJAX)->assertForbidden();
        $this->get(admin_urls('backup', 'runs', $run->uuid, 'log'), self::AJAX)->assertForbidden();
        $this->post(admin_url('backup/save'), [], self::AJAX)->assertForbidden();
        $this->post(admin_url('backup/restore'), ['file' => '20240101000000'], self::AJAX)->assertForbidden();
        $this->post(admin_url('backup/import'), ['restore_zipfile' => '20240101000000'], self::AJAX)->assertForbidden();
        $this->delete(admin_url('backup/delete'), ['files' => '20240101000000'], self::AJAX)->assertForbidden();
        $this->post(admin_url('backup/editname'), ['file' => 'a', 'filename' => 'b'], self::AJAX)->assertForbidden();
        $this->post(admin_url('backup/setting'), ['backup_target' => ['plugin']], self::AJAX)->assertForbidden();
        $this->get(admin_url('backup/importModal'), self::AJAX)->assertForbidden();
        $this->get(admin_url('backup/download/20240101000000'), self::AJAX)->assertForbidden();
    }

    private function finishedRun(string $status = BackupRun::STATUS_SUCCEEDED): BackupRun
    {
        $run = new BackupRun();
        $run->uuid = (string) Str::uuid();
        $run->kind = BackupRun::KIND_BACKUP;
        $run->triggered_by = BackupRun::TRIGGER_COMMAND;
        $run->status = $status;
        $run->file_name = '20260101000000';
        $run->file_size = 2048;
        $run->started_at = Carbon::now()->subMinutes(3);
        $run->heartbeat_at = Carbon::now()->subMinutes(2);
        $run->finished_at = in_array($status, BackupRun::ACTIVE, true) ? null : Carbon::now()->subMinutes(2);
        $run->stages = [
            ['name' => 'prepare', 'started_at' => '2026-01-01 00:00:00', 'finished_at' => '2026-01-01 00:00:01', 'seconds' => 1, 'count' => 0, 'bytes' => 0, 'warnings' => 0, 'errors' => 0],
            ['name' => 'dump', 'started_at' => '2026-01-01 00:00:01', 'finished_at' => '2026-01-01 00:01:01', 'seconds' => 60, 'count' => 12, 'bytes' => 4096, 'warnings' => 0, 'errors' => 1],
        ];
        $run->save();

        return $run;
    }

    private function login(string $loginId): LoginUser
    {
        System::clearRequestSession();
        $user = LoginUser::findOrFail($loginId);
        $this->be($user);
        return $user;
    }
}
