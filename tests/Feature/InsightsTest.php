<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\BrowserLog;
use Exceedone\Exment\Model\InsightDaily;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\McpLog;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\ScheduledJobRun;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\Insights\InsightReport;
use Exceedone\Exment\Services\Insights\Insights;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Auth\Database\OperationLog;

/**
 * The page "Insights" (ISS02964): the nightly count from the logs, the counters, the report of a
 * period (the people only for Audit), the page, the CSV, and who sees it. Rolled back after each test.
 */
class InsightsTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /** A day no other test writes logs on. */
    protected const DAY = '2021-06-15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        InsightDaily::forgetTableExists();
        System::clearRequestSession();
    }

    protected function tearDown(): void
    {
        Insights::$now = null;
        System::clearCache();
        Features::reset();
        parent::tearDown();
    }

    protected function useFeature(bool $on = true): void
    {
        System::features([Insights::FEATURE => ['enabled' => $on]]);
        System::clearCache();
        System::clearRequestSession();
        Features::reset();
    }

    protected function loginAs(string $id): LoginUser
    {
        /** @var LoginUser $user */
        $user = LoginUser::findOrFail($id);
        $this->be($user);
        System::clearRequestSession();
        return $user;
    }

    /**
     * The logs of a day: two sign-ins and a failure, a view shown, AI calls, browser errors and a
     * slow page, a job that failed, a change of a role group and the page of a plugin.
     */
    protected function logsOf(Carbon $day): void
    {
        $admin = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $at = $day->copy()->setTime(10, 0);
        foreach ([[$admin, LoginLog::RESULT_SUCCESS], [$user1, LoginLog::RESULT_SUCCESS], [null, LoginLog::RESULT_FAILURE]] as [$user, $result]) {
            (new LoginLog())->forceFill([
                'login_user_id' => optional($user)->id, 'base_user_id' => optional($user)->base_user_id, 'login_name' => isset($user) ? $user->login_name : 'nobody',
                'provider' => 'pure', 'result' => $result, 'ip' => '192.0.2.1', 'created_at' => $at,
            ])->save();
        }
        foreach (['ok', 'ok', 'error'] as $status) {
            (new McpLog())->forceFill(['base_user_id' => $admin->base_user_id, 'method' => 'tools/call', 'tool' => 'search_data', 'status' => $status, 'created_at' => $at])->save();
        }
        BrowserLog::query()->insert([
            ['base_user_id' => $admin->base_user_id, 'kind' => BrowserLog::KIND_ERROR, 'path' => '/admin/data/x', 'duration_ms' => null, 'count' => 3, 'created_at' => $at],
            ['base_user_id' => $admin->base_user_id, 'kind' => BrowserLog::KIND_PAGE, 'path' => '/admin/slow', 'duration_ms' => Insights::SLOW_PAGE_MS + 1, 'count' => 2, 'created_at' => $at],
            ['base_user_id' => $admin->base_user_id, 'kind' => BrowserLog::KIND_PAGE, 'path' => '/admin/fast', 'duration_ms' => 100, 'count' => 5, 'created_at' => $at],
        ]);
        ScheduledJobRun::query()->insert([
            ['job_key' => 'exment:schedule', 'command' => 'exment:schedule', 'started_at' => $at, 'finished_at' => $at, 'result' => ScheduledJobRun::RESULT_SUCCESS, 'triggered_by' => 'system'],
            ['job_key' => 'exment:sync', 'command' => 'exment:sync', 'started_at' => $at, 'finished_at' => $at, 'result' => ScheduledJobRun::RESULT_FAILED, 'triggered_by' => 'system'],
        ]);
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        OperationLog::query()->insert([
            ['user_id' => $admin->id, 'path' => $prefix . '/role_group/1', 'method' => 'PUT', 'ip' => '192.0.2.1', 'input' => '{}', 'created_at' => $at, 'updated_at' => $at],
            ['user_id' => $admin->id, 'path' => $prefix . '/role_group/1', 'method' => 'GET', 'ip' => '192.0.2.1', 'input' => '{}', 'created_at' => $at, 'updated_at' => $at],
            ['user_id' => $admin->id, 'path' => $prefix . '/plugins/my_page/list', 'method' => 'GET', 'ip' => '192.0.2.1', 'input' => '{}', 'created_at' => $at, 'updated_at' => $at],
        ]);
    }

    /** @return array<string, int> */
    protected function kept(string $metric, string $date = self::DAY): array
    {
        return InsightDaily::query()->where('date', $date)->where('metric', $metric)->pluck('value', 'key')->map(function ($v) { return intval($v); })->all();
    }

    public function testOffByDefault(): void
    {
        $this->assertFalse(Features::enabled(Insights::FEATURE));
        $this->assertFalse(Insights::enabled());
        // not counted when the feature is off
        Insights::hit('webhook_failed', 'hooks.example.com');
        $this->assertSame(0, InsightDaily::query()->where('metric', 'webhook_failed')->count());
    }

    public function testTheNightlyCount(): void
    {
        $this->useFeature();
        $day = Carbon::parse(static::DAY);
        $this->logsOf($day);
        Insights::$now = $day->copy()->addDay()->setTime(4, 50);

        $days = Insights::collectUntilYesterday($day, $day);
        $this->assertSame([static::DAY], $days);

        $this->assertEquals(['success' => 2, 'failure' => 1], array_intersect_key($this->kept('login'), ['success' => 0, 'failure' => 0]));
        $this->assertGreaterThanOrEqual(2, $this->kept('active_day')[''] ?? 0, 'the two who signed in');
        $this->assertEquals(['ok' => 2, 'error' => 1], $this->kept('mcp'));
        $this->assertEquals(['search_data' => 3], $this->kept('mcp_tool'));
        $this->assertSame(3, $this->kept('browser')[BrowserLog::KIND_ERROR] ?? 0);
        $this->assertEquals(['/admin/slow' => 2], $this->kept('slow_page'), 'only the page above the time');
        $this->assertEquals(['success' => 1, 'failed' => 1], array_intersect_key($this->kept('job'), ['success' => 0, 'failed' => 0]));
        $this->assertEquals(['exment:sync' => 1], $this->kept('job_failed'));
        $this->assertEquals(['role_group' => 1], $this->kept('admin_change'), 'the change, not the read');
        $this->assertEquals(['my_page' => 1], $this->kept('plugin_use'));

        // counted again: the same numbers, not twice; a counter of the day stays
        Insights::hit('webhook_failed', 'hooks.example.com');
        Insights::$now = $day->copy()->setTime(12, 0);
        Insights::hit('webhook_failed', 'hooks.example.com');
        Insights::$now = $day->copy()->addDay()->setTime(4, 50);
        Insights::collect($day);
        $this->assertEquals(['ok' => 2, 'error' => 1], $this->kept('mcp'));
        $this->assertEquals(['hooks.example.com' => 1], $this->kept('webhook_failed'));
        $this->assertEquals(['hooks.example.com' => 1], $this->kept('webhook_failed', $day->copy()->addDay()->format('Y-m-d')));
    }

    public function testTheReportAndThePeople(): void
    {
        $this->useFeature();
        $day = Carbon::parse(static::DAY);
        $this->logsOf($day);
        Insights::$now = $day->copy()->addDay()->setTime(4, 50);
        Insights::collect($day);

        $totals = new InsightReport($day->copy()->subDays(6), $day, false);
        $this->assertSame(1, $totals->series('login', [LoginLog::RESULT_FAILURE])[static::DAY]);
        $this->assertSame(7, count($totals->dates()));
        $this->assertSame(['search_data' => 3], $totals->api()['tools']);
        $this->assertSame(1, array_sum($totals->health()['failed']));
        // the totals only: no names
        $this->assertSame([], $totals->security()['failures']);
        $this->assertSame([], $totals->users()['inactive']);
        $this->assertSame([], $totals->tables()['people']);

        // Audit: who failed to sign in
        $people = new InsightReport($day->copy()->subDays(6), $day, true);
        $this->assertSame(['nobody' => 1], $people->security()['failures']);

        // the CSV: one row a number, not the count kept for the API keys
        $rows = $people->csvRows();
        $this->assertContains([static::DAY, 'mcp', 'error', 1], $rows);
        $this->assertEmpty(array_filter($rows, function ($row) {
            return $row[1] === 'api_key_total';
        }));
    }

    public function testThePageTheCsvAndWhoSeesIt(): void
    {
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->useFeature();
        $day = Carbon::parse(static::DAY);
        $this->logsOf($day);
        Insights::$now = $day->copy()->addDay()->setTime(4, 50);
        Insights::collect($day);
        Insights::$now = null;
        $query = '?from=2021-06-09&to=' . static::DAY;

        // a user without the permission
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('insights') . $query, $ajax)->assertStatus(403);
        $this->get(admin_urls('insights', 'export.csv') . $query, $ajax)->assertStatus(403);

        // the system administrator: the totals, the charts and the links to the logs
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $page = $this->get(admin_url('insights') . $query, $ajax);
        $page->assertOk()
            ->assertSee(exmtrans('insights.cards.active_users'))
            ->assertSee('data-insight=', false)
            ->assertSee(admin_urls('log_management', 'mcp') . '?from=2021-06-09&amp;to=' . static::DAY . '&amp;status=error', false)
            ->assertSee('search_data');
        $csv = $this->get(admin_urls('insights', 'export.csv') . $query);
        $csv->assertOk();
        $text = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFdate,metric,key,value", $text);
        $this->assertStringContainsString(static::DAY . ',mcp,error,1', $text);

        // a user with Audit sees the people
        $user2 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $group = RoleGroup::create(['role_group_name' => 'insights_test', 'role_group_view_name' => 'insights test']);
        RoleGroupPermission::create(['role_group_id' => $group->id, 'role_group_permission_type' => RoleType::SYSTEM, 'role_group_target_id' => null, 'permissions' => [Permission::AUDIT]]);
        $member = new RoleGroupUserOrganization();
        $member->role_group_id = $group->id;
        $member->role_group_user_org_type = 'user';
        $member->role_group_target_id = $user2->base_user_id;
        $member->save();
        System::clearCache();
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->get(admin_url('insights') . $query, $ajax)->assertOk()
            ->assertSee(exmtrans('insights.cards.people'))
            ->assertSee('nobody');
    }

    public function testTheCommand(): void
    {
        // off: nothing counted
        $this->artisan('exment:insights')->assertExitCode(0);
        $this->assertSame(0, InsightDaily::query()->where('date', static::DAY)->count());
        // the days asked are counted even when it is off
        $this->logsOf(Carbon::parse(static::DAY));
        $this->artisan('exment:insights', ['--from' => static::DAY, '--to' => static::DAY])->assertExitCode(0);
        $this->assertEquals(['ok' => 2, 'error' => 1], $this->kept('mcp'));
        $this->artisan('exment:insights', ['--from' => 'yesterday'])->assertExitCode(1);
    }
}
