<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Controllers\BackupController;
use Exceedone\Exment\Controllers\BackupRunStateController;
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
        /** @var \Illuminate\Support\Collection<int, BackupRun> $runs */
        $runs = BackupRun::query()->get();
        foreach ($runs as $run) {
            foreach ([BackupRuns::logFullPath($run), BackupRuns::statusPath($run->uuid), BackupRuns::consolePath($run), BackupRuns::tokenPath($run->uuid)] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
        SecretMasker::flush();
        System::clearCache();
        parent::tearDown();
    }

    public function testThePageShowsTheRunGoingOnAndTheHistory(): void
    {
        $done = $this->finishedRun();
        BackupRuns::ensureLogDirectory();
        file_put_contents(BackupRuns::logFullPath($done), "log
");
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

    public function testEveryRouteOfTheRunsIsForTheSystemAdministratorsOnly(): void
    {
        $run = $this->finishedRun();
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(function ($route) {
            return str_contains($route->uri(), 'backup/runs');
        })->values();
        $this->assertGreaterThanOrEqual(4, $routes->count(), 'the routes of the runs');

        foreach ($routes as $route) {
            /** @var \Illuminate\Routing\Route $route */
            // the check of the routes of the admin pages, as the other pages
            if (str_ends_with($route->uri(), '/state')) {
                // the state of a restore: no session, no database; its token instead (testTheStateOfARestore...)
                $this->assertSame('BackupRunStateController@show', class_basename(strval($route->getActionName())), $route->uri());
                continue;
            }
            $middleware = app('router')->gatherRouteMiddleware($route);
            $this->assertContains(\ExmentAdminCore\Admin\Middleware\Permission::class, $middleware, $route->uri() . ' keeps the permission check');
            // and the action checks it again
            $action = $route->getActionName();
            $this->assertStringContainsString('BackupController@', $action, $route->uri());
            $method = new \ReflectionMethod(BackupController::class, explode('@', $action)[1]);
            $lines = array_slice(file(strval($method->getFileName())) ?: [], $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
            $this->assertStringContainsString('static::denyUnlessSystem();', implode('', $lines), $route->uri() . ' checks the system administrator');
        }

        // a user who is not a system administrator: 403 on each, with the roles used and not
        foreach ([true, false] as $roles) {
            System::permission_available($roles);
            System::clearRequestSession();
            $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
            foreach ($routes as $route) {
                if (str_ends_with($route->uri(), '/state')) {
                    continue;
                }
                $url = url(str_replace('{uuid}', $run->uuid, $route->uri()));
                $this->get($url, self::AJAX)->assertForbidden();
            }
        }

        // a system administrator still gets in
        System::permission_available(true);
        System::clearRequestSession();
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('backup/runs/current'), self::AJAX)->assertOk();
        $this->get(admin_urls('backup', 'runs', $run->uuid, 'status'), self::AJAX)->assertOk();
        $this->get(admin_urls('backup', 'runs', $run->uuid))->assertOk();
    }

    public function testTheStateOfARestoreNeedsItsTokenAndNoSessionNorDatabase(): void
    {
        $password = 'Claude-State-Pw-' . Str::random(8);
        config(['database.connections.mysql.password' => $password]);
        SecretMasker::flush();
        [$run, $progress, $token, $url] = $this->restoreWithState();
        $progress->warn("a file of the backup cannot be read: storage/app/admin/claude/secret.txt --password={$password}");
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame('0600', substr(sprintf('%o', fileperms(BackupRuns::tokenPath($run->uuid))), -4));
        }
        $header = [BackupRunStateController::HEADER => $token];

        // no session, no login: the token only
        \Auth::guard('admin')->logout();
        $this->flushSession();
        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });
        $response = $this->get($url, $header)->assertOk();
        $this->assertSame(0, $queries, 'the state reads no database');
        $this->assertNull($response->headers->get('Set-Cookie'), 'no session');

        // the progress only: no text but the fixed labels, no id, no kind, no message, no path
        $response->assertJson(['run' => ['stage' => 'load', 'stage_done' => 3, 'stage_total' => 10, 'active' => true, 'status' => 'running', 'warnings' => 1]]);
        $state = (array) $response->json('run');
        $this->assertSame([], array_values(array_diff(array_keys($state), [
            'status', 'active', 'stage', 'stage_label', 'progress', 'stage_done', 'stage_total', 'started_at',
            'finished_at', 'elapsed_seconds', 'remaining_seconds', 'warnings', 'errors', 'stages',
        ])), 'only the fields of the progress');
        foreach ((array) $state['stages'] as $stage) {
            $this->assertSame([], array_values(array_diff(array_keys((array) $stage), ['name', 'label', 'state', 'seconds', 'count'])));
        }
        $body = strval($response->getContent());
        foreach (['claude/secret.txt', 'storage', $password, $token, $run->uuid, 'claudestate'] as $text) {
            $this->assertStringNotContainsString($text, $body);
        }

        // a token missing, wrong, or of another run: 404
        $this->get($url)->assertNotFound();
        $this->get($url, [BackupRunStateController::HEADER => str_repeat('0', 64)])->assertNotFound();
        $this->get($url, [BackupRunStateController::HEADER => 'abc'])->assertNotFound();
        $this->get(admin_urls('backup', 'runs', (string) Str::uuid(), 'state'), $header)->assertNotFound();
        $progress->releaseLock();
    }

    public function testTheEndOfARestoreIsGivenOnceThenTheTokenIsGone(): void
    {
        [$run, $progress, $token, $url] = $this->restoreWithState();
        $header = [BackupRunStateController::HEADER => $token];
        $progress->finish(BackupRun::STATUS_SUCCEEDED, 'a message with storage/app/admin/claude.txt');
        BackupRuns::expireStateToken($run->uuid);

        // its status and its percent, nothing else
        $response = $this->get($url, $header)->assertOk();
        $this->assertSame(['status' => 'succeeded', 'progress' => 100], $response->json('run'));
        $this->assertFileDoesNotExist(BackupRuns::tokenPath($run->uuid), 'the token is used up');
        $this->get($url, $header)->assertNotFound();

        // a failed one: its status and the percent it reached
        [$run, $progress, $token, $url] = $this->restoreWithState();
        $progress->finish(BackupRun::STATUS_FAILED, 'why');
        $reached = intval($run->progress);
        $this->assertSame(['status' => 'failed', 'progress' => $reached], $this->get($url, [BackupRunStateController::HEADER => $token])->assertOk()->json('run'));

        // a token not read after the end expires all the same
        [$run, $progress, $token, $url] = $this->restoreWithState();
        $data = (array) json_decode(strval(file_get_contents(BackupRuns::tokenPath($run->uuid))), true);
        $data['expires'] = time() - 1;
        file_put_contents(BackupRuns::tokenPath($run->uuid), strval(json_encode($data)));
        $this->get($url, [BackupRunStateController::HEADER => $token])->assertNotFound();
        $this->assertFileDoesNotExist(BackupRuns::tokenPath($run->uuid));
        $progress->releaseLock();
    }

    public function testTheReadsOfTheStateAreLimited(): void
    {
        config(['exment.backup.state_per_minute' => 3]);
        [$run, $progress, $token, $url] = $this->restoreWithState();
        $header = [BackupRunStateController::HEADER => $token];
        foreach (range(1, 3) as $i) {
            $this->get($url, $header)->assertOk();
        }
        $this->get($url, $header)->assertStatus(429);
        // a wrong token counts too
        $this->get($url)->assertStatus(429);
        $progress->releaseLock();
    }

    public function testTheTokenGoesToThePageAfterTheHashOnly(): void
    {
        [$run, $progress] = $this->restoreWithState();
        $method = new \ReflectionMethod(BackupController::class, 'restorePageUrl');
        $url = strval($method->invoke(null, $run));

        // a path (the screen opens it as a whole page), the token after "#" (never sent to a server)
        $this->assertStringStartsWith('/', $url);
        $this->assertMatchesRegularExpression('#/backup/runs/' . $run->uuid . '\#t=[0-9a-f]{64}\z#', $url);
        $token = substr($url, strpos($url, '#t=') + 3);
        $this->assertTrue(BackupRuns::checkStateToken($run->uuid, $token));
        $this->assertNotContains($token, array_map('strval', array_filter(session()->all(), 'is_string')), 'not in the session');
        $progress->releaseLock();
    }

    public function testThePagesOfTheBackupAnswerDuringARestoreWithoutTheDatabase(): void
    {
        [$run, $progress] = $this->restoreWithState();

        \Auth::guard('admin')->logout();
        $this->flushSession();
        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });
        foreach ([admin_urls('backup', 'runs', $run->uuid), admin_url('backup')] as $url) {
            $page = $this->get($url)->assertOk();
            $page->assertSee(exmtrans('backup.run.screen.restoring_title'));
            $page->assertSee('X-Exment-Run-Token', false);
            $this->assertStringNotContainsString(storage_path(), strval($page->getContent()));
        }
        $this->assertSame(0, $queries, 'the pages read no database');
        $this->assertNull($this->get(admin_url('backup'))->headers->get('Set-Cookie'), 'no session');

        // the restore ended: the pages are as before
        $progress->finish(BackupRun::STATUS_SUCCEEDED);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('backup'))->assertOk()->assertSee('id="backup-history"', false);
    }

    public function testTheDownOfARestoreLetsThePagesOfTheBackupThroughAndAPlainDownDoesNot(): void
    {
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        $base = $prefix === '' ? '' : $prefix . '/';
        $excluded = function (): array {
            return (new \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance(app()))->getExcludedPaths();
        };
        $during = null;
        $method = new \ReflectionMethod(\Exceedone\Exment\Services\BackupRestore\Restore::class, 'passBackupPagesThroughMaintenance');
        $method->invoke(null, function () use (&$during, $excluded) {
            // what "down" writes in the file of the maintenance
            $during = $excluded();
        });
        $this->assertContains($base . 'backup', (array) $during);
        $this->assertContains($base . 'backup/runs/*', (array) $during);

        // after it, and for a plain "down": no exception of the backup
        foreach ($excluded() as $path) {
            $this->assertStringNotContainsString('backup', strval($path));
        }
    }

    public function testTheGuardNeedsARestoreWithItsTokenAndNotTheDemo(): void
    {
        [$run, $progress] = $this->restoreWithState();
        \Auth::guard('admin')->logout();
        $this->flushSession();
        $this->get(admin_url('backup'))->assertOk()->assertSee(exmtrans('backup.run.screen.restoring_title'));

        // the demo: never
        config(['exment.demo_mode' => true]);
        $this->assertStringNotContainsString(strval(exmtrans('backup.run.screen.restoring_title')), strval($this->get(admin_url('backup'))->getContent()));
        config(['exment.demo_mode' => false]);

        // a status file of a restore alone (no token): the pages as they are
        BackupRuns::forgetStateToken($run->uuid);
        $this->assertStringNotContainsString(strval(exmtrans('backup.run.screen.restoring_title')), strval($this->get(admin_url('backup'))->getContent()));
        $progress->finish(BackupRun::STATUS_SUCCEEDED);
    }

    public function testAStateOfARunWithoutTokenWritesNothing(): void
    {
        $before = glob(path_join(storage_path(BackupRuns::LOG_DIR), '*')) ?: [];
        foreach (range(1, 5) as $i) {
            $this->get(admin_urls('backup', 'runs', (string) Str::uuid(), 'state'), [BackupRunStateController::HEADER => str_repeat('a', 64)])->assertNotFound();
        }
        $after = glob(path_join(storage_path(BackupRuns::LOG_DIR), '*')) ?: [];
        $this->assertSame([], array_values(array_diff($after, $before)), 'no file is made');
    }

    /**
     * A restore going on with a token of its state.
     *
     * @return array{0: BackupRun, 1: RunProgress, 2: string, 3: string}
     */
    private function restoreWithState(): array
    {
        \DB::table(BackupRun::TABLE)->whereIn('status', BackupRun::ACTIVE)->update(['status' => BackupRun::STATUS_SUCCEEDED]);
        $run = BackupRuns::start(BackupRun::KIND_RESTORE, BackupRun::TRIGGER_SCREEN, ['file_name' => 'claudestate', 'targets' => [BackupRuns::SOURCE_STORED, 'claudestate']]);
        $progress = new RunProgress($run);
        $progress->begin();
        $progress->stage('verify');
        $progress->stage('extract');
        $progress->stage('load', 10);
        $progress->advance(3);
        $progress->releaseLock();
        $token = BackupRuns::issueStateToken($run);

        return [$run, $progress, $token, admin_urls('backup', 'runs', $run->uuid, 'state')];
    }

    public function testTheOutputOfTheRestoreApartIsNotReadableByOthers(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('the permissions of a file on Windows');
        }
        $run = BackupRuns::start(BackupRun::KIND_RESTORE, BackupRun::TRIGGER_SCREEN, [], true);
        $line = BackupRuns::restoreCommandLine($run);
        $this->assertStringStartsWith('umask 077; nohup ', $line);
        $this->assertSame('0600', substr(sprintf('%o', fileperms(BackupRuns::consolePath($run))), -4));
    }

    public function testTheOtherAdminPagesStillNeedTheirPermissions(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        foreach (['system', 'role_group', 'loginuser', 'backup'] as $page) {
            $this->get(admin_url($page), self::AJAX)->assertForbidden();
        }
    }

    public function testTheRunsAnswerDuringTheMaintenanceOfARestore(): void
    {
        // no exception of the maintenance: the guard comes first (only while a restore goes on)
        $property = new \ReflectionProperty(\Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class, 'neverPrevent');
        foreach ((array) $property->getValue() as $except) {
            $this->assertStringNotContainsString('backup', strval($except));
        }
        $this->assertSame('exment::backup.restoring', \Exceedone\Exment\Services\BackupRestore\Restore::downOptions()['--render']);
        $this->assertStringContainsString(e(exmtrans('backup.run.screen.restoring_text')), view('exment::backup.restoring')->render());

        [$run, $progress, $token, $url] = $this->restoreWithState();
        $this->app->maintenanceMode()->activate(['except' => [], 'redirect' => null, 'time' => time(), 'retry' => null, 'refresh' => null, 'secret' => null, 'status' => 503, 'template' => null]);
        try {
            // a restore: the page of the backup, the page of the run and the state answer
            $this->get(admin_url('backup'))->assertOk()->assertSee(exmtrans('backup.run.screen.restoring_title'));
            $this->get(admin_urls('backup', 'runs', $run->uuid))->assertOk()->assertSee('X-Exment-Run-Token', false);
            $this->get($url, [BackupRunStateController::HEADER => $token])->assertOk()->assertJson(['run' => ['active' => true]]);
            $this->get($url)->assertNotFound();
            // the other pages: the maintenance
            $this->get(admin_url('system'))->assertStatus(503);
            $this->get(admin_urls('backup', 'runs', $run->uuid, 'status'))->assertStatus(503);

            // the restore ended: an ordinary maintenance, as before
            $progress->finish(BackupRun::STATUS_SUCCEEDED);
            $this->get(admin_url('backup'))->assertStatus(503);
            $this->get(admin_urls('backup', 'runs', $run->uuid))->assertStatus(503);
            $this->get(admin_urls('backup', 'runs', $run->uuid, 'status'))->assertStatus(503);
            $this->get($url, [BackupRunStateController::HEADER => $token])->assertStatus(503);
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    public function testARestoreOfTheScreenRunsApart(): void
    {
        $run = BackupRuns::start(BackupRun::KIND_RESTORE, BackupRun::TRIGGER_SCREEN, [
            'file_name' => 'claudeapart',
            'targets' => [BackupRuns::SOURCE_STORED, 'claudeapart'],
        ], true);
        $this->assertSame(BackupRun::STATUS_QUEUED, $run->status);

        // the command line: the artisan of the site and the uuid of the run only
        $line = BackupRuns::restoreCommandLine($run);
        $this->assertStringContainsString('exment:restore --run=' . $run->uuid . ' --yes', $line);
        $this->assertStringContainsString(base_path('artisan'), $line);
        $password = strval(config('database.connections.' . config('database.default') . '.password'));
        if ($password !== '') {
            $this->assertStringNotContainsString($password, $line);
        }

        // the command takes it: the request answers at once
        $lines = [];
        $this->assertTrue(BackupRuns::launchRestore($run, function (string $line) use (&$lines, $run) {
            $lines[] = $line;
            $this->assertNotNull(BackupRuns::takeQueued($run->uuid, BackupRun::KIND_RESTORE));
        }));
        $this->assertCount(1, $lines);
        $this->assertSame(BackupRun::STATUS_RUNNING, $run->refresh()->status);
        // taken once only
        $this->assertNull(BackupRuns::takeQueued($run->uuid, BackupRun::KIND_RESTORE));
    }

    public function testARestoreNotTakenApartIsRunHere(): void
    {
        config(['exment.backup.detach_wait_seconds' => 0]);
        $run = BackupRuns::start(BackupRun::KIND_RESTORE, BackupRun::TRIGGER_SCREEN, ['targets' => [BackupRuns::SOURCE_UPLOAD, 'claude.zip']], true);

        $this->assertFalse(BackupRuns::launchRestore($run, function (string $line) {
            // the process did not start
        }));
        $this->assertSame(BackupRun::STATUS_RUNNING, $run->status, 'taken back by the request');
        $this->assertNull(BackupRuns::takeQueued($run->uuid, BackupRun::KIND_RESTORE), 'a process that starts late does not run it again');

        $this->runArtisan('exment:restore', ['--run' => $run->uuid, '--yes' => true])->assertExitCode(1)->run();
        $this->runArtisan('exment:restore', ['--run' => 'not-a-uuid', '--yes' => true])->assertExitCode(1)->run();
    }

    public function testTheStatusFileIsReadWhileTheTableIsReplaced(): void
    {
        $run = BackupRuns::start(BackupRun::KIND_RESTORE, BackupRun::TRIGGER_SCREEN);
        $progress = new RunProgress($run);
        $progress->begin();
        $progress->stage('verify');
        $progress->databaseReplacing();
        $progress->stage('load', 10);
        $progress->advance(4);

        // the restore replaced the table: the run is not in it
        \DB::table(BackupRun::TABLE)->where('uuid', $run->uuid)->delete();
        $status = $this->get(admin_urls('backup', 'runs', $run->uuid, 'status'), self::AJAX)->assertOk();
        $status->assertJson(['run' => ['uuid' => $run->uuid, 'stage' => 'load', 'active' => true, 'stage_done' => 4]]);
        $this->get(admin_url('backup/runs/current'), self::AJAX)->assertOk()->assertJson(['run' => ['uuid' => $run->uuid]]);

        $progress->databaseReplaced();
        $progress->finish(BackupRun::STATUS_SUCCEEDED);
        $this->get(admin_urls('backup', 'runs', $run->uuid, 'status'), self::AJAX)->assertOk()->assertJson(['run' => ['status' => 'succeeded', 'active' => false]]);
        @unlink(BackupRuns::statusPath($run->uuid));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runArtisan(string $command, array $parameters = []): \Illuminate\Testing\PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(\Illuminate\Testing\PendingCommand::class, $pending);
        return $pending;
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
