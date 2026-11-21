<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\MaintenanceMode;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use ExmentAdminCore\Admin\Auth\Database\OperationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Maintenance mode (ISS01396): the system administrators get in, the others
 * get the maintenance page (503); the API, the public forms, the schedule and
 * the queue stop as chosen; a planned start and end switch by themselves; the
 * operator's maintenance (a file) cannot be ended from the site.
 */
class MaintenanceModeTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected string $operatorFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['exment.features_locked' => '']);
        $this->operatorFile = storage_path('framework/exment-maintenance-test-' . getmypid() . '.json');
        config(['exment.maintenance_operator_file' => $this->operatorFile]);
        @unlink($this->operatorFile);
        $this->useFeature(true);
        System::maintenance([]);
        MaintenanceMode::reset();
    }

    protected function tearDown(): void
    {
        @unlink($this->operatorFile);
        Carbon::setTestNow();
        MaintenanceMode::reset();
        Features::reset();
        parent::tearDown();
    }

    protected function useFeature(bool $enabled): void
    {
        $settings = Features::settings();
        $settings[MaintenanceMode::FEATURE] = ['enabled' => $enabled, 'role_groups' => null, 'menu' => true];
        System::features($settings);
        System::clearRequestSession();
        Features::reset();
        MaintenanceMode::reset();
    }

    protected function user(string $loginId): LoginUser
    {
        return LoginUser::findOrFail($loginId);
    }

    protected function request(string $ip = '10.1.2.3'): Request
    {
        return Request::create('/admin', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function state(): array
    {
        MaintenanceMode::reset();
        $state = MaintenanceMode::current();
        $this->assertNotNull($state, 'in maintenance');
        return $state;
    }

    protected function logged(string $action): bool
    {
        return OperationLog::query()->where('path', 'maintenance/' . $action)->exists();
    }

    public function testOffUntilTheFeatureIsUsed(): void
    {
        $this->useFeature(false);
        // even a setting that says "active" does nothing while the feature is not used
        System::maintenance(['active' => true]);
        MaintenanceMode::reset();
        $this->assertNull(MaintenanceMode::current());
        $this->assertFalse(MaintenanceMode::stopsSchedule());
        $this->assertFalse(MaintenanceMode::stopsQueue());
        $this->assertTrue(Features::exists(MaintenanceMode::FEATURE));
        $this->assertFalse(Features::enabled(MaintenanceMode::FEATURE), 'off by default');

        $this->useFeature(true);
        $this->assertNotNull(MaintenanceMode::current());
    }

    public function testAdministratorsGetInOthersGet503(): void
    {
        $admin = $this->user(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user1 = $this->user(TestDefine::TESTDATA_USER_LOGINID_USER1);
        MaintenanceMode::start(['messages' => ['en' => 'Moving the data.', 'ja' => 'データの移行中です。']], $admin);
        $this->assertTrue($this->logged('start'));

        $state = $this->state();
        $this->assertSame(MaintenanceMode::SOURCE_SITE, $state['source']);
        $this->assertTrue(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_WEB, $admin));
        $this->assertFalse(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_WEB, $user1));
        // the login page stays, so that the administrators can log in
        $this->assertTrue(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_ANONYMOUS));

        // the pages: the administrator gets in, user1 gets the maintenance page
        $this->be($admin);
        $this->assertNotSame(503, $this->get(admin_url(''))->getStatusCode());
        $this->be($user1);
        $page = $this->get(admin_url(''));
        $page->assertStatus(503);
        $page->assertHeader(MaintenanceMode::HEADER, '1');
        $this->assertSame(strval(MaintenanceMode::RETRY_AFTER_DEFAULT), $page->headers->get('Retry-After'));
        $this->assertStringContainsString(e(MaintenanceMode::message($state)), strval($page->getContent()));
        // user1 was logged out: the login does not stay for after
        $this->assertNull(\Auth::guard('admin')->user());

        // ajax gets JSON
        $this->be($user1);
        $json = $this->get(admin_url(''), ['X-Requested-With' => 'XMLHttpRequest']);
        $json->assertStatus(503)->assertJson(['code' => 'maintenance']);
    }

    public function testTheMessageInTheLanguageOfTheRequest(): void
    {
        MaintenanceMode::start(['messages' => ['en' => 'Moving the data.', 'ja' => 'データの移行中です。']]);
        $state = $this->state();
        app()->setLocale('ja');
        $this->assertSame('データの移行中です。', MaintenanceMode::message($state));
        app()->setLocale('en');
        $this->assertSame('Moving the data.', MaintenanceMode::message($state));
        // another language: the first one there
        app()->setLocale('vi');
        $this->assertSame('Moving the data.', MaintenanceMode::message($state));
        app()->setLocale('en');

        MaintenanceMode::save(['messages' => []]);
        $this->assertSame(exmtrans('maintenance.default_message'), MaintenanceMode::message($this->state()));
    }

    public function testWhoStartedItAndTheAllowedIps(): void
    {
        $user1 = $this->user(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = $this->user(TestDefine::TESTDATA_USER_LOGINID_USER2);
        // who started it always gets in (an administrator who is no longer one, say)
        MaintenanceMode::start(['allow_ips' => "192.168.10.0/24\n203.0.113.5, not-an-ip"], $user1);
        $state = $this->state();
        $this->assertSame(['192.168.10.0/24', '203.0.113.5'], $state['allow_ips']);
        $this->assertTrue(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_WEB, $user1));
        $this->assertFalse(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_WEB, $user2));
        $this->assertTrue(MaintenanceMode::passes($state, $this->request('192.168.10.20'), MaintenanceMode::KIND_WEB, $user2));
        $this->assertTrue(MaintenanceMode::passes($state, $this->request('203.0.113.5'), MaintenanceMode::KIND_PUBLIC));
        $this->assertFalse(MaintenanceMode::passes($state, $this->request('203.0.113.6'), MaintenanceMode::KIND_PUBLIC));
    }

    public function testTheApiThePublicFormsTheScheduleAndTheQueue(): void
    {
        $admin = $this->user(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user1 = $this->user(TestDefine::TESTDATA_USER_LOGINID_USER1);

        // the defaults: the API for the system administrators, the rest stopped
        MaintenanceMode::start();
        $state = $this->state();
        $this->assertSame(MaintenanceMode::API_ADMIN, $state['api']);
        $this->assertTrue(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_API, $admin));
        $this->assertFalse(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_API, $user1));
        $this->assertTrue(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_API_ANONYMOUS));
        $this->assertFalse(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_PUBLIC));
        $this->assertTrue(MaintenanceMode::stopsSchedule());
        $this->assertTrue(MaintenanceMode::stopsQueue());
        $api = MaintenanceMode::response($state, $this->request(), MaintenanceMode::KIND_API);
        $this->assertSame(503, $api->getStatusCode());
        $this->assertSame('maintenance', json_decode(strval($api->getContent()), true)['code']);

        // stopped for everyone (getting a token too)
        MaintenanceMode::save(['api' => MaintenanceMode::API_STOP]);
        $state = $this->state();
        $this->assertFalse(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_API, $admin));
        $this->assertFalse(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_API_ANONYMOUS));

        // open, and the public forms, the schedule and the queue kept
        MaintenanceMode::save(['api' => MaintenanceMode::API_OPEN, 'stop_public' => false, 'stop_schedule' => false, 'stop_queue' => false]);
        $state = $this->state();
        $this->assertTrue(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_API, $user1));
        $this->assertTrue(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_PUBLIC));
        $this->assertFalse(MaintenanceMode::stopsSchedule());
        $this->assertFalse(MaintenanceMode::stopsQueue());

        // the scheduled commands of Exment are skipped in maintenance (not the tick itself)
        MaintenanceMode::save(['stop_schedule' => true]);
        MaintenanceMode::reset();
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $events = collect($schedule->events());
        $tick = $events->first(function ($e) {
            return str_contains(strval($e->command), 'exment:maintenance tick');
        });
        $other = $events->first(function ($e) {
            return str_contains(strval($e->command), 'exment:schedule');
        });
        if (isset($tick, $other)) {
            $this->assertTrue($tick->filtersPass(app()));
            $this->assertFalse($other->filtersPass(app()));
        }
    }

    public function testAnEndThatHasPassedEndsIt(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00'));
        MaintenanceMode::start(['end_at' => '2026-12-01 12:00:00']);
        $state = $this->state();
        $this->assertSame(7200, MaintenanceMode::retryAfter($state));

        Carbon::setTestNow(Carbon::parse('2026-12-01 12:00:01'));
        MaintenanceMode::reset();
        $this->assertNull(MaintenanceMode::current());
        $this->assertFalse(MaintenanceMode::settings()['active']);
        $this->assertNull(MaintenanceMode::settings()['end_at']);
        $this->assertTrue($this->logged('auto_end'));
    }

    public function testAPlannedStartAndEnd(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00'));
        MaintenanceMode::save(['start_at' => '2026-12-01 10:00:00', 'end_at' => '2026-12-01 11:00:00']);
        MaintenanceMode::reset();
        $this->assertNull(MaintenanceMode::current());
        $this->assertNotNull(MaintenanceMode::upcoming(), 'told before it starts');

        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00'));
        Artisan::call('exment:maintenance', ['action' => 'tick']);
        $this->assertTrue(MaintenanceMode::settings()['active']);
        $this->assertTrue($this->logged('scheduled_start'));
        $this->assertNull(MaintenanceMode::upcoming());
        $this->assertNotNull(MaintenanceMode::current());

        Carbon::setTestNow(Carbon::parse('2026-12-01 11:00:00'));
        Artisan::call('exment:maintenance', ['action' => 'tick']);
        $this->assertFalse(MaintenanceMode::settings()['active']);
        MaintenanceMode::reset();
        $this->assertNull(MaintenanceMode::current());

        // a plan that passed while nothing ran is only cleared
        Carbon::setTestNow(Carbon::parse('2026-12-02 09:00:00'));
        MaintenanceMode::save(['start_at' => '2026-12-02 10:00:00', 'end_at' => '2026-12-02 11:00:00']);
        Carbon::setTestNow(Carbon::parse('2026-12-02 12:00:00'));
        MaintenanceMode::reset();
        $this->assertNull(MaintenanceMode::current());
        $this->assertNull(MaintenanceMode::settings()['start_at']);
        $this->assertTrue($this->logged('schedule_passed'));
    }

    public function testTheOperatorsMaintenance(): void
    {
        $admin = $this->user(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        // the operator's works without the feature
        $this->useFeature(false);
        Artisan::call('exment:maintenance', ['action' => 'on', '--operator' => true, '--allow-ip' => ['198.51.100.7'], '--message' => 'Server update']);
        $this->assertFileExists($this->operatorFile);
        $state = $this->state();
        $this->assertSame(MaintenanceMode::SOURCE_OPERATOR, $state['source']);
        $this->assertSame('Server update', MaintenanceMode::message($state));
        // even the system administrators stay out (but for --allow-admins), the API and the public forms stop
        $this->assertFalse(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_WEB, $admin));
        $this->assertFalse(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_ANONYMOUS));
        $this->assertFalse(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_API, $admin));
        $this->assertFalse(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_PUBLIC));
        $this->assertTrue(MaintenanceMode::passes($state, $this->request('198.51.100.7'), MaintenanceMode::KIND_WEB));
        $this->assertTrue(MaintenanceMode::stopsSchedule());
        $this->assertTrue(MaintenanceMode::stopsQueue());

        // the site cannot end it
        $this->useFeature(true);
        MaintenanceMode::end($admin);
        $this->assertSame(MaintenanceMode::SOURCE_OPERATOR, $this->state()['source']);
        $this->be($admin);
        $this->get(admin_url(''))->assertStatus(503);

        // --allow-admins
        Artisan::call('exment:maintenance', ['action' => 'on', '--operator' => true, '--allow-admins' => true]);
        $state = $this->state();
        $this->assertTrue(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_WEB, $admin));
        $this->assertTrue(MaintenanceMode::passes($state, $this->request(), MaintenanceMode::KIND_ANONYMOUS));

        Artisan::call('exment:maintenance', ['action' => 'off', '--operator' => true]);
        $this->assertFileDoesNotExist($this->operatorFile);
        MaintenanceMode::reset();
        $this->assertNull(MaintenanceMode::current());
        $this->assertTrue($this->logged('operator_start'));
        $this->assertTrue($this->logged('operator_end'));
    }

    public function testAFileOfAllTheSites(): void
    {
        $all = storage_path('framework/exment-maintenance-all-' . getmypid() . '.json');
        config(['exment.maintenance_operator_file' => $this->operatorFile . ',' . $all]);
        try {
            $this->assertNull(MaintenanceMode::current());
            file_put_contents($all, json_encode(['messages' => ['en' => 'All sites']]));
            $state = $this->state();
            $this->assertSame(MaintenanceMode::SOURCE_OPERATOR, $state['source']);
            $this->assertSame($all, $state['file']);
            $this->assertSame('All sites', MaintenanceMode::message($state));
            // the site's own file first
            file_put_contents($this->operatorFile, json_encode(['messages' => ['en' => 'This site']]));
            $this->assertSame('This site', MaintenanceMode::message($this->state()));
            // a file that cannot be read is still a maintenance (the safe side)
            file_put_contents($this->operatorFile, '{not json');
            $this->assertSame(MaintenanceMode::SOURCE_OPERATOR, $this->state()['source']);
            // the command removes only the first
            Artisan::call('exment:maintenance', ['action' => 'off', '--operator' => true]);
            $this->assertFileDoesNotExist($this->operatorFile);
            $this->assertSame($all, $this->state()['file']);
        } finally {
            @unlink($all);
        }
    }

    public function testAnExpiredFileDoesNotHideTheNextOne(): void
    {
        $all = storage_path('framework/exment-maintenance-all2-' . getmypid() . '.json');
        config(['exment.maintenance_operator_file' => $this->operatorFile . ',' . $all]);
        try {
            Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00'));
            file_put_contents($this->operatorFile, json_encode(['end_at' => '2026-12-01 09:00:00', 'messages' => ['en' => 'Old']]));
            file_put_contents($all, json_encode(['end_at' => '2026-12-01 11:00:00', 'messages' => ['en' => 'All sites']]));
            $state = $this->state();
            $this->assertSame($all, $state['file']);
            $this->assertSame('All sites', MaintenanceMode::message($state));
            // a directory in the place of a file is not a maintenance
            @unlink($this->operatorFile);
            @mkdir($this->operatorFile);
            $this->assertSame($all, $this->state()['file']);
            @rmdir($this->operatorFile);
        } finally {
            @unlink($all);
        }
    }

    public function testTheQueueWaits(): void
    {
        $looping = new \Illuminate\Queue\Events\Looping('sync', 'default');
        $this->assertNotFalse(app('events')->until($looping));
        MaintenanceMode::start();
        MaintenanceMode::reset();
        $this->assertFalse(app('events')->until($looping), 'a worker waits');
        MaintenanceMode::save(['stop_queue' => false]);
        MaintenanceMode::reset();
        $this->assertNotFalse(app('events')->until($looping));
    }

    public function testTheOperatorsIsCheckedBeforeTheLogin(): void
    {
        MaintenanceMode::startOperator([]);
        // not logged in: the maintenance page, not the redirect to the login
        $this->get(admin_url(''))->assertStatus(503);
        $this->get(admin_urls('auth', 'login'))->assertStatus(503);
        // the API without a token: 503, not 401
        $this->getJson(admin_urls('api', 'table'))->assertStatus(503)->assertJson(['code' => 'maintenance']);
        // from outside
        $this->postJson(admin_urls('line', 'webhook'), [])->assertStatus(503);
        $this->get(admin_urls('safety', 'answer'))->assertStatus(503);
        // an allowed IP address goes on
        MaintenanceMode::startOperator(['allow_ips' => ['127.0.0.1']]);
        $this->assertNotSame(503, $this->get(admin_urls('auth', 'login'))->getStatusCode());
    }

    public function testTheLoginPageTellsIt(): void
    {
        MaintenanceMode::start(['messages' => ['en' => 'Moving the data.', 'ja' => 'Moving the data.'], 'end_at' => Carbon::now()->addHour()->toIso8601String()]);
        $page = $this->get(admin_urls('auth', 'login'));
        $page->assertOk();
        $page->assertSee('Moving the data.');
        $page->assertSee(exmtrans('maintenance.login_admins_only'));

        MaintenanceMode::end();
        MaintenanceMode::save(['start_at' => Carbon::now()->addDay()->toIso8601String()]);
        MaintenanceMode::reset();
        $this->get(admin_urls('auth', 'login'))->assertOk()->assertSee('exment-maintenance-login', false);
    }

    public function testASkippedScheduleRunsAfter(): void
    {
        MaintenanceMode::start();
        MaintenanceMode::reset();
        $this->assertTrue(MaintenanceMode::skipsScheduled("'/usr/bin/php' 'artisan' exment:schedule"));
        $this->assertTrue(boolval(System::maintenance_missed_schedule()));
        // not while it goes on
        $this->assertFalse(MaintenanceMode::runMissedSchedule());
        MaintenanceMode::end();
        MaintenanceMode::reset();
        $this->assertFalse(MaintenanceMode::skipsScheduled('exment:schedule'));
        Artisan::call('exment:maintenance', ['action' => 'tick']);
        System::clearRequestSession();
        $this->assertFalse(boolval(System::query()->where('system_name', 'maintenance_missed_schedule')->value('system_value')));
        $this->assertTrue($this->logged('missed_schedule'));
    }

    public function testTurningTheFeatureOffEndsIt(): void
    {
        MaintenanceMode::start();
        Features::save([MaintenanceMode::FEATURE => ['enabled' => false]]);
        $this->assertFalse(MaintenanceMode::settings()['active']);
        $this->assertTrue($this->logged('feature_off'));
        // turned on again: it does not come back
        Features::save([MaintenanceMode::FEATURE => ['enabled' => true]]);
        MaintenanceMode::reset();
        $this->assertNull(MaintenanceMode::current());
    }

    public function testEveryAddressIsNotAnAllowList(): void
    {
        $this->assertFalse(MaintenanceMode::validIp('0.0.0.0/0'));
        $this->assertFalse(MaintenanceMode::validIp('::/0'));
        $this->assertTrue(MaintenanceMode::validIp('10.0.0.0/8'));
        $this->assertSame(1, Artisan::call('exment:maintenance', ['action' => 'on', '--operator' => true, '--allow-ip' => ['0.0.0.0/0']]));
    }

    public function testAnEndIsLoggedOnce(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00'));
        MaintenanceMode::start(['end_at' => '2026-12-01 10:30:00']);
        Carbon::setTestNow(Carbon::parse('2026-12-01 11:00:00'));
        MaintenanceMode::sync();
        MaintenanceMode::sync();
        MaintenanceMode::reset();
        MaintenanceMode::current();
        $this->assertSame(1, OperationLog::query()->where('path', 'maintenance/auto_end')->count());
    }

    public function testTheCommand(): void
    {
        $this->useFeature(false);
        $this->assertSame(1, Artisan::call('exment:maintenance', ['action' => 'on']), 'the site\'s needs the feature');
        $this->useFeature(true);
        $this->assertSame(1, Artisan::call('exment:maintenance', ['action' => 'on', '--allow-ip' => ['nope']]));
        $this->assertSame(1, Artisan::call('exment:maintenance', ['action' => 'on', '--until' => '2000-01-01 00:00']));
        $this->assertSame(0, Artisan::call('exment:maintenance', ['action' => 'on', '--api' => 'stop', '--keep-public' => true]));
        $settings = MaintenanceMode::settings();
        $this->assertTrue($settings['active']);
        $this->assertSame(MaintenanceMode::API_STOP, $settings['api']);
        $this->assertFalse($settings['stop_public']);
        $this->assertSame(0, Artisan::call('exment:maintenance', ['action' => 'status']));
        $this->assertStringContainsString('"in_maintenance": true', Artisan::output());
        $this->assertSame(0, Artisan::call('exment:maintenance', ['action' => 'off']));
        $this->assertFalse(MaintenanceMode::settings()['active']);
        $this->assertSame(1, Artisan::call('exment:maintenance', ['action' => 'nope']));
    }

    public function testNotInMaintenanceNothingChanges(): void
    {
        $user1 = $this->user(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertNull(MaintenanceMode::current());
        $this->be($user1);
        $this->assertNotSame(503, $this->get(admin_url(''))->getStatusCode());
        $this->assertFalse(MaintenanceMode::stopsSchedule());
    }
}
