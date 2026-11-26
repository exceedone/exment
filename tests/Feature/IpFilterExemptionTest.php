<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Middleware\ApiIPFilter;
use Exceedone\Exment\Middleware\WebIPFilter;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\AuditReport\AuditReport;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\IpFilterExemptions;
use Exceedone\Exment\Services\LogManagement\LogWriter;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\Request;

/**
 * The users the IP filter of the web pages does not refuse (ISS02915): users, organizations (and
 * the ones below), role groups; IPv4 and IPv6; the login page, the logout of a user who is not
 * exempt, the record of the uses, the API that keeps its filter, the IT audit report.
 */
class IpFilterExemptionTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const FILTER = "192.0.2.0/24\r\n2001:db8::/32";
    protected const OUTSIDE_V4 = '198.51.100.7';
    protected const OUTSIDE_V6 = '2001:db9::7';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        System::web_ip_filters(static::FILTER);
    }

    protected function tearDown(): void
    {
        System::web_ip_filters('');
        System::web_ip_filter_exempt_users([]);
        System::web_ip_filter_exempt_organizations([]);
        System::web_ip_filter_exempt_role_groups([]);
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    protected function user(string $login): LoginUser
    {
        /** @var LoginUser $user */
        $user = LoginUser::findOrFail($login);
        return $user;
    }

    protected function fromIp(string $ip): self
    {
        System::clearRequestSession();
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    protected function isFilterPage(string $content): bool
    {
        return str_contains($content, 'Exment | Error');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, LoginLog>
     */
    protected function logsOf(LoginUser $user, string $result)
    {
        LogWriter::flush();
        return LoginLog::query()->where('base_user_id', $user->base_user_id)->where('result', $result)->get();
    }

    public function testWithoutExemptionsNothingChanges(): void
    {
        $this->assertFalse(IpFilterExemptions::configured());
        foreach ([static::OUTSIDE_V4, static::OUTSIDE_V6] as $ip) {
            $this->assertTrue($this->isFilterPage(strval($this->fromIp($ip)->get(admin_url('auth/login'))->getContent())), $ip);
        }
        $this->be($this->user(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $this->assertTrue($this->isFilterPage(strval($this->fromIp(static::OUTSIDE_V4)->get(admin_url(''))->getContent())));
        // inside: as before
        $this->assertFalse($this->isFilterPage(strval($this->fromIp('2001:db8::1')->get(admin_url(''))->getContent())));
    }

    public function testAnExemptUserFromOutsideIPv4AndIPv6(): void
    {
        $user1 = $this->user(TestDefine::TESTDATA_USER_LOGINID_USER1);
        System::web_ip_filter_exempt_users([$user1->base_user_id]);
        System::clearCache();

        // the login page is shown from outside, the other pages before the login go to it
        $this->assertFalse($this->isFilterPage(strval($this->fromIp(static::OUTSIDE_V6)->get(admin_url('auth/login'))->getContent())));
        $this->fromIp(static::OUTSIDE_V6)->get(admin_url(''))->assertRedirect(admin_url('auth/login'));

        $this->be($user1);
        foreach ([static::OUTSIDE_V4, static::OUTSIDE_V4, static::OUTSIDE_V6] as $ip) {
            $response = $this->fromIp($ip)->get(admin_url(''));
            $this->assertFalse($this->isFilterPage(strval($response->getContent())), $ip);
        }
        $this->assertAuthenticatedAs($user1, 'admin');
        // once a session and address
        $logs = $this->logsOf($user1, LoginLog::RESULT_IP_EXEMPT);
        $this->assertSame([static::OUTSIDE_V4, static::OUTSIDE_V6], $logs->pluck('ip')->all());
    }

    public function testAUserNotExemptIsLoggedOut(): void
    {
        $user1 = $this->user(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = $this->user(TestDefine::TESTDATA_USER_LOGINID_USER2);
        System::web_ip_filter_exempt_users([$user1->base_user_id]);
        System::clearCache();

        $this->be($user2);
        $this->assertTrue($this->isFilterPage(strval($this->fromIp(static::OUTSIDE_V4)->get(admin_url(''))->getContent())));
        $this->assertGuest('admin');
        $failures = $this->logsOf($user2, LoginLog::RESULT_FAILURE);
        $this->assertSame([LoginLog::REASON_IP_NOT_ALLOWED], $failures->pluck('failure_reason')->all());

        // the login itself from outside: logged out after it
        System::clearRequestSession();
        $request = Request::create(admin_url('auth/login'), 'POST', [], [], [], ['REMOTE_ADDR' => static::OUTSIDE_V6]);
        $request->setLaravelSession(app('session')->driver());
        $result = (new WebIPFilter())->handle($request, function () use ($user2) {
            \Auth::guard('admin')->login($user2);
            return 'logged in';
        }, 'login');
        $this->assertNotSame('logged in', $result);
        $this->assertGuest('admin');

        // inside: as before
        $this->be($user2);
        $this->assertFalse($this->isFilterPage(strval($this->fromIp('192.0.2.10')->get(admin_url(''))->getContent())));
        $this->assertAuthenticatedAs($user2, 'admin');
    }

    public function testOrganizationsAndRoleGroups(): void
    {
        // an organization: its users and the users of the organizations below it
        System::web_ip_filter_exempt_organizations([TestDefine::TESTDATA_ORGANIZATION_DEV]);
        System::clearCache();
        $this->assertTrue(IpFilterExemptions::isExempt($this->user(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB)));
        $this->assertTrue(IpFilterExemptions::isExempt($this->user(TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC)), 'an organization below');
        $this->assertFalse(IpFilterExemptions::isExempt($this->user(TestDefine::TESTDATA_USER_LOGINID_USER1)));

        // a role group
        System::web_ip_filter_exempt_organizations([]);
        System::web_ip_filter_exempt_role_groups([TestDefine::TESTDATA_ROLEGROUP_GENERAL]);
        System::clearCache();
        foreach ([TestDefine::TESTDATA_USER_LOGINID_USER1, TestDefine::TESTDATA_USER_LOGINID_USER2, TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC] as $login) {
            $user = $this->user($login);
            $this->assertSame(in_array(intval(TestDefine::TESTDATA_ROLEGROUP_GENERAL), Features::roleGroupIds($user), true), IpFilterExemptions::isExempt($user), $login);
        }
    }

    public function testTheApiKeepsItsFilter(): void
    {
        $user1 = $this->user(TestDefine::TESTDATA_USER_LOGINID_USER1);
        System::web_ip_filter_exempt_users([$user1->base_user_id]);
        System::api_ip_filters(static::FILTER);
        System::clearCache();
        $this->be($user1, 'admin');
        foreach ([static::OUTSIDE_V4, static::OUTSIDE_V6] as $ip) {
            System::clearRequestSession();
            $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
            $result = (new ApiIPFilter())->handle($request, function () {
                return 'passed';
            });
            $this->assertNotSame('passed', $result, $ip);
        }
        System::api_ip_filters('');
    }

    public function testTheAuditReport(): void
    {
        $user1 = $this->user(TestDefine::TESTDATA_USER_LOGINID_USER1);
        System::web_ip_filter_exempt_users([$user1->base_user_id]);
        System::web_ip_filter_exempt_role_groups([TestDefine::TESTDATA_ROLEGROUP_GENERAL]);
        System::clearCache();
        $this->be($user1);
        $this->fromIp(static::OUTSIDE_V4)->get(admin_url(''));
        LogWriter::flush();

        $this->be($this->user(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $report = new AuditReport(Carbon::now()->subDay(), Carbon::now()->addMinute());
        $exemptions = $report->section('ip_exemptions')['rows'];
        $this->assertCount(2, $exemptions);
        $this->assertSame('user1', $exemptions[0][1]);
        $this->assertSame(strval(exmtrans('audit_report.member_type.role_group')), $exemptions[1][0]);
        $access = $report->section('ip_exempt_access')['rows'];
        $this->assertCount(1, $access);
        $this->assertSame(['user1', static::OUTSIDE_V4], [$access[0][1], $access[0][3]]);
        $this->assertContains(strval(exmtrans('log_management.result.ip_exempt')), array_column($report->section('logins')['rows'], 0));
    }
}
