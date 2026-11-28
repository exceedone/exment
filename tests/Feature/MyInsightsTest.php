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
            'login_user_id' => $user->id, 'base_user_id' => $user->base_user_id, 'login_name' => $user->user_code,
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

    /**
     * A card of a plugin or of other code: called with the user who looks and the period; text only;
     * a link of another kind is dropped; a card that fails does not stop the page.
     */
    public function testTheCardsOfOthers(): void
    {
        $this->features([Insights::MY_FEATURE => true]);
        $seen = [];
        MyInsights::registerCard('test.tasks', function (LoginUser $user, Carbon $from, Carbon $to) use (&$seen) {
            $seen[] = [intval($user->base_user_id), $from->format('Y-m-d'), $to->format('Y-m-d')];
            return [
                'title' => 'My tasks <b>', 'url' => '/admin/data/tasks', 'url_label' => 'Open',
                'rows' => [['label' => 'Overdue', 'value' => 2, 'warn' => true], ['label' => 'Open', 'value' => 5], 'not a row'],
                'help' => 'From the plugin',
            ];
        });
        MyInsights::registerCard('test.bad_link', function () {
            return ['title' => 'Bad link', 'url' => 'javascript:alert(1)', 'rows' => []];
        });
        MyInsights::registerCard('test.fails', function () {
            throw new \RuntimeException('down');
        });
        try {
            $user1 = $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
            $page = $this->get(admin_url('auth/insights') . '?days=7', ['X-Requested-With' => 'XMLHttpRequest']);
            $page->assertOk()->assertSee('My tasks &lt;b&gt;', false)->assertSee('href="/admin/data/tasks"', false)
                ->assertSee('From the plugin')->assertSee('Bad link')->assertDontSee('javascript:alert', false);
            $this->assertSame([[intval($user1->base_user_id), Carbon::today()->subDays(6)->format('Y-m-d'), Carbon::today()->format('Y-m-d')]], $seen);
            $cards = (new MyInsights($user1, Carbon::today(), Carbon::today()))->extraCards();
            $this->assertCount(2, $cards);
            $this->assertSame([['label' => 'Overdue', 'value' => '2', 'warn' => true], ['label' => 'Open', 'value' => '5', 'warn' => false]], $cards[0]['rows']);
            $this->assertNull($cards[1]['url']);
        } finally {
            MyInsights::resetCards();
        }
    }

    /**
     * The security of my own: my keys with the marks of the IT audit report, my sessions only when
     * the site keeps them in the database (else nothing is listed).
     */
    public function testMyKeysAndMySessions(): void
    {
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        \Exceedone\Exment\Services\UserApiKeys::create(intval($user1->base_user_id), 'anywhere', \Exceedone\Exment\Services\UserApiKeys::readOnlyScopes());
        \Exceedone\Exment\Services\UserApiKeys::create(intval($user1->base_user_id), 'office only', \Exceedone\Exment\Services\UserApiKeys::readOnlyScopes(), null, '192.0.2.0/24');
        $mine = new MyInsights($user1, Carbon::today()->subDays(6), Carbon::today());
        $keys = [];
        foreach ((array)$mine->api()['keys'] as $key) {
            $keys[strval($key['name'])] = $key;
        }
        $this->assertContains('any_ip', $keys['anywhere']['marks']);
        $this->assertNotContains('any_ip', $keys['office only']['marks']);

        config(['session.driver' => 'file']);
        $this->assertNull($mine->sessions(), 'sessions in files are not listed');
    }

    public function testTheCardsOfWhatTheSiteDoesNotHave(): void
    {
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $mine = new MyInsights($user1, Carbon::today()->subDays(6), Carbon::today());
        // no plugin with a card in the test data
        $this->assertSame([], $mine->extraCards());
        $workflow = $mine->workflow();
        $this->assertIsArray($workflow);
        $this->assertGreaterThanOrEqual(0, $workflow['waiting']);
        $this->assertSame(['ok' => 0, 'error' => 0], $mine->api()['mcp']);
    }
}
