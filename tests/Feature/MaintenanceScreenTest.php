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
use Illuminate\Support\Facades\DB;

/**
 * The page of the maintenance mode (ISS01396): only the system administrators,
 * only when the feature is used; start, end, plan; the banner.
 */
class MaintenanceScreenTest extends FeatureTestBase
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

    protected function login(string $loginId): LoginUser
    {
        System::clearRequestSession();
        MaintenanceMode::reset();
        $user = LoginUser::findOrFail($loginId);
        $this->be($user);
        return $user;
    }

    public function testOnlyTheAdministratorsWhenTheFeatureIsUsed(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('maintenance'))->assertOk()->assertSee('maintenance-form', false);

        // (ajax: admin-core answers 403; without it, it sends the page and stops)
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('maintenance'), $ajax)->assertForbidden();
        $this->post(admin_urls('maintenance', 'start'), [], $ajax)->assertForbidden();
        $this->assertFalse(MaintenanceMode::settings()['active'], 'user1 cannot start it');

        $this->useFeature(false);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('maintenance'))->assertNotFound();
    }

    public function testStartAndEnd(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00'));
        $admin = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->post(admin_urls('maintenance', 'start'), [
            'end_at' => '2026-12-01T12:00',
            'messages' => ['ja' => 'データの移行中です。', 'en' => 'Moving the data.'],
            'allow_ips' => "198.51.100.0/24\n",
            'api' => 'stop',
            'stop_public' => '1',
            'stop_schedule' => '0',
            'stop_queue' => '1',
        ])->assertRedirect(admin_url('maintenance'));
        $settings = MaintenanceMode::settings();
        $this->assertTrue($settings['active']);
        $this->assertSame($admin->id, $settings['started_by']);
        $this->assertSame(['198.51.100.0/24'], $settings['allow_ips']);
        $this->assertSame('stop', $settings['api']);
        $this->assertFalse($settings['stop_schedule']);
        $this->assertSame(Carbon::parse('2026-12-01 12:00', config('app.timezone'))->toIso8601String(), $settings['end_at']);

        // the administrator sees the banner, user1 the maintenance page
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url(''))->assertSee(exmtrans('maintenance.banner.on'));
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url(''))->assertStatus(503);

        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->post(admin_urls('maintenance', 'end'))->assertRedirect(admin_url('maintenance'));
        $this->assertFalse(MaintenanceMode::settings()['active']);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertNotSame(503, $this->get(admin_url(''))->getStatusCode());
    }

    public function testAPlanIsToldBeforeItStarts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00'));
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->post(admin_url('maintenance'), [
            'start_at' => '2026-12-01T22:00',
            'end_at' => '2026-12-01T23:30',
            'api' => 'admin',
            'stop_public' => '1',
            'stop_schedule' => '1',
            'stop_queue' => '1',
        ])->assertRedirect(admin_url('maintenance'));
        $settings = MaintenanceMode::settings();
        $this->assertFalse($settings['active']);
        $this->assertNotNull($settings['start_at']);

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $page = $this->get(admin_url(''));
        $this->assertNotSame(503, $page->getStatusCode());
        $page->assertSee('exment-maintenance-planned', false);
        $page->assertSee('2026-12-01 22:00');
    }

    public function testSavingInAPlannedMaintenance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00'));
        MaintenanceMode::save(['start_at' => '2026-12-01 10:00:00', 'end_at' => '2026-12-01 12:00:00']);
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:30:00'));
        MaintenanceMode::sync();
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        // the form sends back what it showed (an old start, too): saving works
        $this->post(admin_url('maintenance'), [
            'start_at' => '2026-12-01T10:00',
            'end_at' => '2026-12-01T13:00',
            'api' => 'admin',
        ])->assertSessionHasNoErrors()->assertRedirect(admin_url('maintenance'));
        $settings = MaintenanceMode::settings();
        $this->assertTrue($settings['active']);
        $this->assertSame(Carbon::parse('2026-12-01 13:00', config('app.timezone'))->toIso8601String(), $settings['end_at']);
        $this->assertNull($settings['start_at']);
    }

    public function testTheFormIsChecked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00'));
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $cases = [
            ['start_at' => '2026-12-01T12:00', 'end_at' => '2026-12-01T11:00'],
            ['end_at' => '2026-11-30T11:00'],
            ['start_at' => 'not a date'],
            ['allow_ips' => '999.1.1.1'],
            ['allow_ips' => '0.0.0.0/0'],
            // a start that has come: the button "start now" is for it
            ['start_at' => '2026-12-01T09:00'],
            ['start_at' => '2026-12-01T08:00', 'end_at' => '2026-12-01T10:00'],
        ];
        foreach ($cases as $case) {
            $this->post(admin_url('maintenance'), $case)->assertSessionHasErrors('maintenance');
        }
        $this->assertNull(MaintenanceMode::settings()['start_at'], 'nothing saved');
    }

    public function testOnlyTheAdministratorsAlsoWithoutRoles(): void
    {
        // the roles not used: Auth\Permission lets everyone in, the page still does not
        System::permission_available(false);
        System::clearRequestSession();
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('maintenance'), $ajax)->assertForbidden();
        $this->post(admin_urls('maintenance', 'start'), [], $ajax)->assertForbidden();
        $this->assertFalse(MaintenanceMode::settings()['active']);
    }

    public function testTheIpTheServerSees(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('maintenance'))->assertOk()->assertSee(exmtrans('maintenance.your_ip', ['ip' => '127.0.0.1']));
    }

    public function testTheOperatorsCannotBeEndedHere(): void
    {
        MaintenanceMode::startOperator(['allow_admins' => true]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('maintenance'))->assertOk()->assertSee(exmtrans('maintenance.state.operator'));
        $this->post(admin_urls('maintenance', 'end'));
        MaintenanceMode::reset();
        $this->assertSame(MaintenanceMode::SOURCE_OPERATOR, MaintenanceMode::current()['source'] ?? null);
    }

    public function testTheMenuOfAnUpdatedSite(): void
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'maintenance')->delete();
        $migration = require exment_package_path('database/migrations/2026_11_26_000000_add_maintenance_menu_under_admin.php');
        $migration->up();
        $admin = DB::table($menuTable)->where('menu_name', 'admin')->where('menu_type', 'parent_node')->first();
        $row = DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'maintenance')->first();
        $this->assertNotNull($row);
        $this->assertSame(intval($admin->id), intval($row->parent_id));
        // once
        $migration->up();
        $this->assertSame(1, DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'maintenance')->count());

        // hidden while the feature is not used
        $this->useFeature(false);
        $this->assertTrue(Features::hidesSystemMenu('maintenance'));
    }
}
