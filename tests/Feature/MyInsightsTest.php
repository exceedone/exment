<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Model\InsightDaily;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\McpLog;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\Insights\InsightReport;
use Exceedone\Exment\Services\Insights\Insights;
use Exceedone\Exment\Services\Insights\MyInsights;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * "My insights" (ISS02964): each user sees only their own numbers, an administrator too; the
 * numbers of a user never reach the page of the site or its CSV. Rolled back after each test.
 */
class MyInsightsTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const DAY = '2021-07-14';

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

    /**
     * @param array<string, bool> $on
     */
    protected function features(array $on): void
    {
        $settings = [];
        foreach ($on as $key => $enabled) {
            $settings[$key] = ['enabled' => $enabled];
        }
        System::features($settings);
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

    protected function signIn(LoginUser $user, string $ip, Carbon $at, string $result = LoginLog::RESULT_SUCCESS): void
    {
        (new LoginLog())->forceFill([
            'login_user_id' => $user->id, 'base_user_id' => $user->base_user_id, 'login_name' => $user->login_name,
            'provider' => 'pure', 'result' => $result, 'ip' => $ip, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0', 'created_at' => $at,
        ])->save();
    }

    public function testOffByDefault(): void
    {
        $this->features([]);
        $this->assertFalse(Features::enabled(Insights::MY_FEATURE));
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('auth/insights'), ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(404);
        $this->get(admin_url('auth/setting'))->assertDontSee(admin_url('auth/insights'), false);
    }

    public function testOnlyMyOwnNumbers(): void
    {
        $this->features([Insights::MY_FEATURE => true]);
        $admin = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $today = Carbon::today();
        // user1: a place used before, a new one, a failure; the admin: a place of its own
        $this->signIn($user1, '192.0.2.10', $today->copy()->subDays(40)->setTime(9, 0));
        $this->signIn($user1, '192.0.2.10', $today->copy()->subDays(3)->setTime(9, 0));
        $this->signIn($user1, '203.0.113.77', $today->copy()->subDays(2)->setTime(22, 0));
        $this->signIn($user1, '198.51.100.9', $today->copy()->subDay()->setTime(3, 0), LoginLog::RESULT_FAILURE);
        $this->signIn($admin, '198.51.100.250', $today->copy()->subDay()->setTime(8, 0));
        (new McpLog())->forceFill(['base_user_id' => $admin->base_user_id, 'method' => 'tools/call', 'tool' => 'admin_only_tool', 'status' => 'ok', 'created_at' => $today->copy()->subDay()])->save();

        $mine = new MyInsights($user1, $today->copy()->subDays(29), $today);
        $logins = $mine->logins();
        $places = array_column($logins['new_places'], 'ip');
        $this->assertContains('203.0.113.77', $places, 'a place not used in the days before');
        $this->assertNotContains('192.0.2.10', $places, 'a place used before');
        $this->assertNotContains('198.51.100.250', $places, 'not the place of another user');
        $this->assertContains('198.51.100.9', array_column($logins['failures'], 'ip'));
        $this->assertGreaterThanOrEqual(1, $logins['hours'][22]);
        $this->assertGreaterThanOrEqual(2, $logins['days_in']);

        // the page of user1: user1's numbers; a parameter does not choose another user
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('auth/setting'))->assertSee(admin_url('auth/insights'), false);
        $page = $this->get(admin_url('auth/insights') . '?user=' . $admin->id . '&base_user_id=' . $admin->base_user_id, $ajax);
        $page->assertOk()->assertSee(exmtrans('my_insights.only_you'))->assertSee('203.0.113.77')
            ->assertDontSee('198.51.100.250')->assertDontSee('admin_only_tool');

        // the administrator sees here only their own
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('auth/insights'), $ajax)->assertOk()->assertSee('admin_only_tool')
            ->assertDontSee('203.0.113.77')->assertDontSee('198.51.100.9');
    }

    public function testTheNumbersOfAUserStayWithTheUser(): void
    {
        $this->features([Insights::FEATURE => true, Insights::MY_FEATURE => true]);
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $day = Carbon::parse(static::DAY);
        $this->signIn($user1, '192.0.2.10', $day->copy()->setTime(10, 15));
        Insights::$now = $day->copy()->addDay()->setTime(4, 50);
        Insights::collect($day);

        // kept by the user
        $this->assertSame(1, intval(InsightDaily::query()->where('date', static::DAY)->where('metric', 'user_login_hour')->where('key', $user1->base_user_id . '|10')->value('value')));
        // not on the page of the site, nor in its CSV
        $report = new InsightReport($day, $day, true);
        foreach ($report->csvRows() as $row) {
            $this->assertNotContains($row[1], Insights::USER_METRICS);
        }

        // without "My insights", the numbers of the users are not counted
        $this->features([Insights::FEATURE => true]);
        Insights::collect($day);
        $this->assertSame(0, InsightDaily::query()->where('date', static::DAY)->whereIn('metric', Insights::USER_METRICS)->count());
    }

    public function testTheCardsOfWhatTheSiteDoesNotHave(): void
    {
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $mine = new MyInsights($user1, Carbon::today()->subDays(6), Carbon::today());
        // no ExmentPM in the test data: no card
        $this->assertNull($mine->pmIssues());
        $workflow = $mine->workflow();
        $this->assertIsArray($workflow);
        $this->assertGreaterThanOrEqual(0, $workflow['waiting']);
        $this->assertSame(['ok' => 0, 'error' => 0], $mine->api()['mcp']);
    }
}
