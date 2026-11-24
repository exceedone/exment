<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\ScheduledJobRun;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\PluginDistribution\AdminNotices;
use Exceedone\Exment\Services\ScheduledJobs\CronWords;
use Exceedone\Exment\Services\ScheduledJobs\Recorder;
use Exceedone\Exment\Services\ScheduledJobs\ScheduledJobs;
use Exceedone\Exment\Services\SystemRequire\Tuning\ScheduleStatus;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The page "Scheduled jobs" (ISS02832): only the system administrators, only when the
 * feature is used; the list, the runs kept, "Run now", the notice of a job that keeps failing.
 */
class ScheduledJobsTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    /** A job of Exment with withoutOverlapping, that runs on every site. */
    public const JOB = 'exment:file-server-purge';

    protected string $statusFile;

    /** @var string[] */
    protected array $launched = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        // no mail goes out from the notices of a job that keeps failing
        Notification::fake();
        config(['exment.features_locked' => '']);
        config(['exment.scheduled_jobs.keep_runs' => 20, 'exment.scheduled_jobs.alert_failures' => 3]);
        $this->statusFile = storage_path('framework/exment-schedule-status-test-' . getmypid() . '.json');
        @unlink($this->statusFile);
        ScheduleStatus::$path = $this->statusFile;
        $this->useFeature(true);
        System::scheduled_job_alerted([]);
        // (in the transaction of the test)
        ScheduledJobRun::query()->delete();
        $this->launched = [];
        ScheduledJobs::$launcher = function (string $line) {
            $this->launched[] = $line;
        };
        ScheduledJobs::$processAvailable = null;
        Recorder::reset();
        $this->freeLocks();
    }

    protected function tearDown(): void
    {
        $this->freeLocks();
        @unlink($this->statusFile);
        ScheduleStatus::$path = null;
        ScheduledJobs::$launcher = null;
        ScheduledJobs::$processAvailable = null;
        Recorder::reset();
        Features::reset();
        parent::tearDown();
    }

    /**
     * The mutex of the jobs is in the cache, not in the transaction of the test.
     */
    protected function freeLocks(): void
    {
        foreach (ScheduledJobs::schedule()->events() as $event) {
            $event->mutex->forget($event);
        }
    }

    protected function useFeature(bool $enabled): void
    {
        $settings = Features::settings();
        $settings[ScheduledJobs::FEATURE] = ['enabled' => $enabled, 'role_groups' => null, 'menu' => true];
        System::features($settings);
        System::clearRequestSession();
        Features::reset();
    }

    protected function login(string $loginId): LoginUser
    {
        System::clearRequestSession();
        $user = LoginUser::findOrFail($loginId);
        $this->be($user);
        return $user;
    }

    /**
     * A run of a job as the scheduler does it.
     */
    protected function scheduledRun(string $key, ?int $exitCode): void
    {
        $event = ScheduledJobs::event($key);
        $this->assertNotNull($event, $key);
        Recorder::starting($event);
        $event->exitCode = $exitCode;
        Recorder::finished($event, 0.25);
    }

    public function testTheFeatureIsOffByDefault(): void
    {
        $settings = Features::settings();
        unset($settings[ScheduledJobs::FEATURE]);
        System::features($settings);
        System::clearRequestSession();
        Features::reset();
        $this->assertFalse(Features::enabled(ScheduledJobs::FEATURE));
        $this->assertFalse(Recorder::recording());

        // off: its pages are not found, and nothing is kept
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('scheduled_jobs'))->assertNotFound();
        $this->post(admin_urls('scheduled_jobs', 'run'), ['job' => static::JOB])->assertNotFound();
        $this->assertSame([], $this->launched);
        $this->scheduledRun(static::JOB, 0);
        $this->assertSame(0, ScheduledJobRun::query()->count());
        $this->assertTrue(Features::hidesSystemMenu('scheduled_jobs'));
    }

    public function testTheListPage(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $page = $this->get(admin_url('scheduled_jobs'))->assertOk();
        $page->assertSee('scheduled-jobs-table', false);
        // the jobs of Exment, when they run in words, and the cron never seen
        $page->assertSee(ScheduledJobs::label('exment:schedule'));
        $page->assertSee(ScheduledJobs::label(static::JOB));
        $page->assertSee(ScheduledJobs::label('exment:maintenance tick'));
        $page->assertSee(CronWords::describe('0 * * * *'));
        $page->assertSee(CronWords::describe('30 3 * * *'));
        $page->assertSee(exmtrans('scheduled_jobs.cron_never'));

        // the safety check not used: its watch is disabled, with the reason
        $jobs = collect(ScheduledJobs::jobs())->keyBy('key');
        $this->assertFalse($jobs['exment:safetywatch']['enabled']);
        $this->assertTrue($jobs[static::JOB]['enabled']);
        $this->assertNotNull($jobs[static::JOB]['next']);

        // cron alive: no warning
        ScheduleStatus::record('exment:maintenance tick', 0.1, time() - 60);
        $this->get(admin_url('scheduled_jobs'))->assertOk()->assertDontSee('scheduled-jobs-cron-warning', false);
        // not for more than 10 minutes: the warning
        ScheduleStatus::record('exment:maintenance tick', 0.1, time() - 11 * 60);
        $this->get(admin_url('scheduled_jobs'))->assertOk()->assertSee('scheduled-jobs-cron-warning', false);

        // a run and a job that keeps failing (red)
        $this->scheduledRun('exment:schedule', 0);
        foreach (range(1, 3) as $i) {
            $this->scheduledRun(static::JOB, 2);
        }
        $page = $this->get(admin_url('scheduled_jobs'))->assertOk();
        $page->assertSee('scheduled-job-failing', false);
        $page->assertSee(exmtrans('scheduled_jobs.failing', ['count' => 3]));
        $page->assertSee(exmtrans('scheduled_jobs.result.success'));
        $page->assertSee(exmtrans('scheduled_jobs.triggered_system'));
    }

    public function testOnlyTheAdministrators(): void
    {
        // (ajax: admin-core answers 403; without it, it sends the page and stops)
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('scheduled_jobs'), $ajax)->assertForbidden();
        $this->post(admin_urls('scheduled_jobs', 'run'), ['job' => static::JOB], $ajax)->assertForbidden();
        $this->assertSame([], $this->launched);
        $this->assertSame(0, ScheduledJobRun::query()->count());

        // the roles not used: Auth\Permission lets everyone in, the page still does not
        System::permission_available(false);
        System::clearRequestSession();
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('scheduled_jobs'), $ajax)->assertForbidden();
        $this->post(admin_urls('scheduled_jobs', 'run'), ['job' => static::JOB], $ajax)->assertForbidden();
        $this->assertSame([], $this->launched);
    }

    public function testRunNow(): void
    {
        $admin = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->post(admin_urls('scheduled_jobs', 'run'), ['job' => static::JOB])->assertRedirect(admin_url('scheduled_jobs'));
        $this->assertCount(1, $this->launched);
        $run = ScheduledJobRun::query()->where('job_key', static::JOB)->orderByDesc('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(ScheduledJobRun::RESULT_RUNNING, $run->result);
        $this->assertSame(strval($admin->getUserId()), $run->triggered_by);
        $this->assertStringContainsString('artisan', $this->launched[0]);
        $this->assertStringContainsString(static::JOB, $this->launched[0]);
        $this->assertStringContainsString('exment:scheduled-job-finish ' . $run->id, $this->launched[0]);

        // while it runs: refused (the mutex of withoutOverlapping)
        $this->post(admin_urls('scheduled_jobs', 'run'), ['job' => static::JOB])->assertRedirect(admin_url('scheduled_jobs'));
        $this->assertCount(1, $this->launched);
        $this->assertSame(1, ScheduledJobRun::query()->where('job_key', static::JOB)->count());
        $page = $this->get(admin_url('scheduled_jobs'))->assertOk();
        $page->assertSee(exmtrans('scheduled_jobs.run_unavailable.running'));

        // its end (the command line calls exment:scheduled-job-finish), with its output
        file_put_contents(ScheduledJobs::runOutputPath($run), str_repeat('x', 5000) . "\nthe end");
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('exment:scheduled-job-finish', ['run' => $run->id, 'code' => 0]));
        $run->refresh();
        $this->assertSame(ScheduledJobRun::RESULT_SUCCESS, $run->result);
        $this->assertSame(0, $run->exit_code);
        $this->assertNotNull($run->finished_at);
        $this->assertLessThanOrEqual(Recorder::OUTPUT_BYTES, strlen(strval($run->output_tail)));
        $this->assertStringEndsWith('the end', strval($run->output_tail));
        $this->assertFileDoesNotExist(ScheduledJobs::runOutputPath($run));

        // free again
        $this->post(admin_urls('scheduled_jobs', 'run'), ['job' => static::JOB]);
        $this->assertCount(2, $this->launched);
    }

    public function testRunNowIsRefusedWhileTheSchedulerRunsIt(): void
    {
        // the scheduler holds the mutex of withoutOverlapping
        $event = ScheduledJobs::event(static::JOB);
        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->mutex->create($event));

        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->post(admin_urls('scheduled_jobs', 'run'), ['job' => static::JOB])->assertRedirect(admin_url('scheduled_jobs'));
        $this->assertSame([], $this->launched);
        $this->assertSame(0, ScheduledJobRun::query()->where('job_key', static::JOB)->count());
        $this->assertSame([false, 'running'], ScheduledJobs::runNow(static::JOB, 1));

        // and a job that is not there
        $this->assertSame([false, 'not_found'], ScheduledJobs::runNow('exment:nothing', 1));
    }

    public function testWithoutProcOpenTheButtonIsDisabled(): void
    {
        ScheduledJobs::$processAvailable = false;
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $page = $this->get(admin_url('scheduled_jobs'))->assertOk();
        $page->assertSee('scheduled-jobs-no-process', false);
        $page->assertSee(exmtrans('scheduled_jobs.run_unavailable.no_process'));
        $page->assertSee('scheduled-job-run-reason', false);
        $page->assertDontSee('btn btn-sm btn-primary scheduled-job-run', false);

        $this->post(admin_urls('scheduled_jobs', 'run'), ['job' => static::JOB])->assertRedirect(admin_url('scheduled_jobs'));
        $this->assertSame([], $this->launched);
        $this->assertSame(0, ScheduledJobRun::query()->count());
    }

    public function testNotInADemo(): void
    {
        // a public demo: no command is started from the page (as the backup)
        config(['exment.demo_mode' => true]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $page = $this->get(admin_url('scheduled_jobs'))->assertOk();
        $page->assertSee(\Exceedone\Exment\Services\DemoMode::message());
        $page->assertDontSee('btn btn-sm btn-primary scheduled-job-run', false);
        $this->post(admin_urls('scheduled_jobs', 'run'), ['job' => static::JOB]);
        $this->assertSame([], $this->launched);
        $this->assertSame(0, ScheduledJobRun::query()->count());
    }

    public function testOnlyTheLastRunsAreKept(): void
    {
        config(['exment.scheduled_jobs.keep_runs' => 20]);
        foreach (range(1, 25) as $i) {
            $this->scheduledRun(static::JOB, 0);
        }
        $this->scheduledRun('exment:schedule', 0);
        $this->assertSame(20, ScheduledJobRun::query()->where('job_key', static::JOB)->count());
        $this->assertSame(1, ScheduledJobRun::query()->where('job_key', 'exment:schedule')->count());
        // the last ones
        $ids = ScheduledJobRun::query()->where('job_key', static::JOB)->orderBy('id')->pluck('id')->all();
        $this->assertSame(range($ids[0], $ids[0] + 19), array_map('intval', $ids));

        config(['exment.scheduled_jobs.keep_runs' => 5]);
        $this->scheduledRun(static::JOB, 0);
        $this->assertSame(5, ScheduledJobRun::query()->where('job_key', static::JOB)->count());
    }

    public function testTheResultsOfTheScheduler(): void
    {
        $this->scheduledRun(static::JOB, 0);
        $this->scheduledRun(static::JOB, 3);
        // withoutOverlapping: it did not run (no exit code)
        $this->scheduledRun(static::JOB, null);
        $event = ScheduledJobs::event('exment:safetywatch');
        $this->assertNotNull($event);
        Recorder::skipped($event);
        // an exception of the scheduler before the end
        $event = ScheduledJobs::event('exment:schedule');
        $this->assertNotNull($event);
        Recorder::starting($event);
        Recorder::failed($event, new \Exception('could not start'));

        $results = ScheduledJobRun::query()->orderBy('id')->get()->map(function ($run) {
            return [$run->job_key, $run->result, $run->exit_code, $run->triggered_by];
        })->all();
        $this->assertSame([
            [static::JOB, 'success', 0, 'system'],
            [static::JOB, 'failed', 3, 'system'],
            [static::JOB, 'skipped', null, 'system'],
            ['exment:safetywatch', 'skipped', null, 'system'],
            ['exment:schedule', 'failed', null, 'system'],
        ], $results);
        $this->assertSame('could not start', ScheduledJobRun::query()->where('job_key', 'exment:schedule')->value('output_tail'));
        $this->assertSame(250, ScheduledJobRun::query()->where('job_key', static::JOB)->orderBy('id')->value('duration_ms'));
        // the skipped runs do not end a streak of failures
        $this->assertSame(1, Recorder::failureStreak(static::JOB));
    }

    public function testTheAdministratorsAreToldOncePerStreak(): void
    {
        Notification::fake();
        $admins = array_map(function ($user) {
            return intval($user->id);
        }, AdminNotices::administrators());
        $this->assertNotEmpty($admins);
        $subject = strval(exmtrans('scheduled_jobs.notice_subject', ['name' => ScheduledJobs::label(static::JOB)]));
        $told = function () use ($subject) {
            return NotifyNavbar::withoutGlobalScopes()->where('notify_subject', $subject)->count();
        };

        $this->scheduledRun(static::JOB, 1);
        $this->scheduledRun(static::JOB, 1);
        $this->assertSame(0, $told());
        $this->scheduledRun(static::JOB, 1);
        $this->assertSame(count($admins), $told(), 'the third failure in a row');
        $this->assertSame(count($admins), NotifyNavbar::withoutGlobalScopes()->where('notify_subject', $subject)->whereIn('target_user_id', $admins)->count());

        // once: the fourth failure is not told again
        $this->scheduledRun(static::JOB, 1);
        $this->assertSame(count($admins), $told());

        // a good run ends the streak; the next three failures are told again
        $this->scheduledRun(static::JOB, 0);
        $this->scheduledRun(static::JOB, 1);
        $this->scheduledRun(static::JOB, 1);
        $this->assertSame(count($admins), $told());
        $this->scheduledRun(static::JOB, 1);
        $this->assertSame(2 * count($admins), $told());
    }

    public function testTheMailOfTheNotice(): void
    {
        $fake = Notification::fake();
        $withMail = count(array_filter(AdminNotices::administrators(), function ($user) {
            return !is_null(\Exceedone\Exment\Services\NotifyService::getAddress($user));
        }));
        foreach (range(1, 4) as $i) {
            $this->scheduledRun(static::JOB, 1);
        }
        $fake->assertSentTimes(MailSendJob::class, $withMail);
    }

    public function testTheStatusCommand(): void
    {
        $this->scheduledRun(static::JOB, 1);
        $this->scheduledRun(static::JOB, 1);
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('exment:schedule-status', ['--json' => true]));
        $status = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
        $this->assertIsArray($status);
        $this->assertArrayHasKey('cron_alive', $status);
        $this->assertIsArray($status['jobs']);
        $jobs = [];
        foreach ($status['jobs'] as $job) {
            $jobs[$job['key']] = $job;
        }
        $this->assertSame(2, $jobs[static::JOB]['failures']);
        $this->assertSame('failed', $jobs[static::JOB]['last_run']['result']);
        $this->assertNull($jobs['exment:schedule']['last_run']);
        $this->assertSame(0, $jobs['exment:schedule']['failures']);
    }

    public function testTheListIsTheOneOfTheConsole(): void
    {
        // the console registers the same jobs (ExmentServiceProvider::bootSchedule), and the
        // skip in a maintenance on all but the tick
        $console = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(function ($event) {
            return ScheduledJobs::key($event) . ' ' . $event->getExpression();
        })->reject(function ($name) {
            return str_starts_with($name, 'closure') || str_starts_with($name, 'Exment schedule debug');
        })->values()->all();
        $page = collect(ScheduledJobs::jobs())->map(function ($job) {
            return $job['key'] . ' ' . $job['expression'];
        })->all();
        $this->assertSame($console, $page);
        $this->assertContains('exment:maintenance tick * * * * *', $page);
    }

    public function testTheMenuOfAnUpdatedSite(): void
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'scheduled_jobs')->delete();
        $migration = require exment_package_path('database/migrations/2026_12_01_000001_add_scheduled_jobs_menu_under_admin.php');
        $migration->up();
        $admin = DB::table($menuTable)->where('menu_name', 'admin')->where('menu_type', 'parent_node')->first();
        $row = DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'scheduled_jobs')->first();
        $this->assertNotNull($row);
        $this->assertSame(intval($admin->id), intval($row->parent_id));
        $names = DB::table($menuTable)->where('parent_id', $admin->id)->orderBy('order')->orderBy('id')->pluck('menu_name')->map(function ($name) {
            return strval($name);
        })->all();
        $security = array_search('security_check', $names, true);
        $this->assertIsInt($security);
        $this->assertSame($security + 1, array_search('scheduled_jobs', $names, true));
        // once
        $migration->up();
        $this->assertSame(1, DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'scheduled_jobs')->count());

        // shown to the administrators while the feature is used
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertFalse(Features::hidesSystemMenu('scheduled_jobs'));
        $this->useFeature(false);
        $this->assertTrue(Features::hidesSystemMenu('scheduled_jobs'));
    }
}
