<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\AuditReportFile;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PasswordHistory;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\UserApiKey;
use Exceedone\Exment\Services\AuditReport\AuditReport;
use Exceedone\Exment\Services\AuditReport\AuditReportStore;
use Illuminate\Http\UploadedFile;
use Exceedone\Exment\Services\LogManagement\LogSettings;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Auth\Database\OperationLog;

/**
 * The IT audit report (ISS02840): its sections from the accounts, the login records and the API
 * keys; the page, the CSV and the PDF; who sees it. Everything a test writes is rolled back.
 */
class AuditReportTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        System::clearRequestSession();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    /**
     * @param int|string $id the login user of the test data (TestDefine)
     */
    protected function loginAs($id): void
    {
        $this->be(LoginUser::findOrFail($id));
        System::clearRequestSession();
    }

    /**
     * @param int|string $id the login user of the test data (TestDefine)
     */
    protected function login($id): LoginUser
    {
        /** @var LoginUser $user */
        $user = LoginUser::findOrFail($id);
        return $user;
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function log(?LoginUser $user = null, array $values = []): LoginLog
    {
        $log = new LoginLog();
        $log->forceFill(array_merge([
            'login_user_id' => optional($user)->id,
            'base_user_id' => optional($user)->base_user_id,
            'provider' => 'pure',
            'result' => LoginLog::RESULT_SUCCESS,
            'ip' => '192.0.2.1',
        ], $values))->save();
        return $log;
    }

    /**
     * The rows of a section, each by the labels of the columns.
     *
     * @return array<int, array<string, string>>
     */
    protected function rows(AuditReport $report, string $section): array
    {
        $data = $report->section($section);
        return array_map(function ($row) use ($data) {
            return array_combine(array_keys($data['columns']), $row) ?: [];
        }, $data['rows']);
    }

    /**
     * @return array<string, string>|null the row of a user
     */
    protected function rowOf(AuditReport $report, string $section, LoginUser $user): ?array
    {
        $code = strval($user->user_code);
        foreach ($this->rows($report, $section) as $row) {
            if (($row['user_code'] ?? null) === $code) {
                return $row;
            }
        }
        return null;
    }

    /** A role group that gives Audit to a user. */
    protected function auditGroup(LoginUser $user): RoleGroup
    {
        $group = RoleGroup::create(['role_group_name' => 'audit_report_test', 'role_group_view_name' => 'audit report test']);
        RoleGroupPermission::create([
            'role_group_id' => $group->id,
            'role_group_permission_type' => RoleType::SYSTEM,
            'role_group_target_id' => null,
            'permissions' => [Permission::AUDIT],
        ]);
        $member = new RoleGroupUserOrganization();
        $member->role_group_id = $group->id;
        $member->role_group_user_org_type = 'user';
        $member->role_group_target_id = $user->base_user_id;
        $member->save();
        System::clearCache();
        System::clearRequestSession();
        return $group;
    }

    public function testTheAccounts(): void
    {
        $admin = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user1 = $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->auditGroup($user2);
        $now = Carbon::now()->addDays(10);
        // later than any login of the other tests
        $this->log($admin, ['created_at' => Carbon::now()->subDays(40)]);
        $this->log($admin, ['created_at' => Carbon::now()->addDay()]);
        $history = new PasswordHistory();
        $history->forceFill(['login_user_id' => $user1->id, 'password' => 'x', 'created_at' => Carbon::parse('2030-01-02 03:04:05')])->save();

        $report = new AuditReport(Carbon::now()->subMonth(), Carbon::now(), 5, $now);

        $row = $this->rowOf($report, 'users', $admin);
        $this->assertNotNull($row);
        $this->assertSame(Carbon::now()->addDay()->format('Y-m-d'), $row['last_login'], 'the last successful login');
        $this->assertSame(exmtrans('audit_report.login_type.pure'), $row['login_type']);
        $this->assertStringContainsString(AuditReport::permissionText(Permission::SYSTEM), $row['system_permissions']);
        $this->assertSame('2030-01-02', $this->rowOf($report, 'users', $user1)['password_changed'] ?? null);

        // not used: no login in the 5 days before $now (the admin: 9 days before)
        $this->assertNotNull($this->rowOf($report, 'inactive', $user1));
        $this->assertNotNull($this->rowOf($report, 'inactive', $admin));
        $this->assertNull($this->rowOf(new AuditReport(Carbon::now()->subMonth(), Carbon::now(), 30, $now), 'inactive', $admin));

        // the administrators: the system administrator, and the user with Audit through a role group
        $this->assertNotNull($this->rowOf($report, 'administrators', $admin));
        $audit = $this->rowOf($report, 'administrators', $user2);
        $this->assertNotNull($audit);
        $this->assertSame(AuditReport::permissionText(Permission::AUDIT), $audit['system_permissions']);
        $this->assertSame('audit report test', $audit['role_groups']);

        // no mail address of anyone
        $text = json_encode($report->sections(), JSON_UNESCAPED_UNICODE) ?: '';
        $email = strval($admin->email);
        if ($email !== '') {
            $this->assertStringNotContainsString($email, $text);
        }
    }

    public function testTheLogins(): void
    {
        $user1 = $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $base = Carbon::parse('2020-03-20 12:00:00');
        $from = $base->copy()->subDays(10);
        // before the period: user1 from .1
        $this->log($user1, ['created_at' => $base->copy()->subDays(20), 'ip' => '192.0.2.1']);
        // in the period
        foreach ([1, 2, 3] as $i) {
            $this->log($user1, ['result' => LoginLog::RESULT_FAILURE, 'failure_reason' => LoginLog::REASON_WRONG_PASSWORD, 'created_at' => $base->copy()->subDays(5)->addMinutes($i), 'ip' => '198.51.100.' . $i]);
        }
        $this->log($user2, ['result' => LoginLog::RESULT_FAILURE, 'failure_reason' => LoginLog::REASON_WRONG_PASSWORD, 'created_at' => $base->copy()->subDays(5)]);
        $this->log(null, ['result' => LoginLog::RESULT_LOCKOUT, 'base_user_id' => $user1->base_user_id, 'created_at' => $base->copy()->subDays(5)->addMinutes(4)]);
        $this->log($user1, ['created_at' => $base->copy()->subDays(4), 'ip' => '192.0.2.1']);
        $this->log($user1, ['created_at' => $base->copy()->subDays(3), 'ip' => '203.0.113.9']);
        $this->log($user1, ['created_at' => $base->copy()->subDays(2), 'ip' => '203.0.113.9']);
        // after the period
        $this->log($user1, ['created_at' => $base->copy()->addDays(3), 'ip' => '203.0.113.10']);

        $report = new AuditReport($from, $base->copy());

        $counts = [];
        foreach ($this->rows($report, 'logins') as $row) {
            $counts[$row['result'] . '/' . $row['reason']] = $row['count'];
        }
        $this->assertSame('3', $counts[exmtrans('log_management.result.success') . '/'] ?? null);
        $this->assertSame('4', $counts[exmtrans('log_management.result.failure') . '/' . exmtrans('log_management.reason.wrong_password')] ?? null);
        $this->assertSame('1', $counts[exmtrans('log_management.result.lockout') . '/'] ?? null);

        // 3 failures and more: user1, not user2
        $failures = $this->rowOf($report, 'login_failures', $user1);
        $this->assertSame('3', $failures['failures'] ?? null);
        $this->assertStringContainsString('198.51.100.1', $failures['ips'] ?? '');
        $this->assertNull($this->rowOf($report, 'login_failures', $user2));

        $this->assertNotNull($this->rowOf($report, 'lockouts', $user1));

        // the new address once; not the one known before the period, not after the period
        $ips = array_column($this->rows($report, 'new_ips'), 'ip');
        $this->assertSame(['203.0.113.9'], $ips);
    }

    public function testTheApiKeys(): void
    {
        $admin = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $key = function (string $name, array $values) use ($admin): UserApiKey {
            $key = new UserApiKey();
            $key->forceFill(array_merge([
                'user_id' => $admin->base_user_id,
                'name' => $name,
                'key_prefix' => 'exk_' . substr(md5($name), 0, 6),
                'key_hash' => hash('sha256', $name),
                'scopes' => ['value_read'],
                'expires_at' => Carbon::now()->addDays(30),
                'created_at' => Carbon::now()->subDays(5),
            ], $values))->save();
            return $key;
        };
        $key('audit-long', ['expires_at' => Carbon::now()->addYears(2)]);
        $key('audit-ip', ['allowed_ips' => '192.0.2.0/24']);
        $key('audit-revoked-before', ['revoked_at' => Carbon::now()->subMonths(6), 'created_at' => Carbon::now()->subYear()]);
        $key('audit-revoked-in', ['revoked_at' => Carbon::now()->subDays(2)]);

        $report = new AuditReport(Carbon::now()->subMonth(), Carbon::now());
        $rows = [];
        foreach ($this->rows($report, 'api_keys') as $row) {
            $rows[$row['name']] = $row;
        }
        $this->assertArrayNotHasKey('audit-revoked-before', $rows, 'stopped before the period');
        $this->assertSame(exmtrans('audit_report.state.revoked'), $rows['audit-revoked-in']['state'] ?? null);
        $this->assertStringContainsString(exmtrans('audit_report.mark.long_expiry'), $rows['audit-long']['marks'] ?? '');
        $this->assertStringContainsString(exmtrans('audit_report.mark.any_ip'), $rows['audit-long']['marks'] ?? '');
        $this->assertSame('', $rows['audit-ip']['marks'] ?? null);
        $this->assertSame('192.0.2.0/24', $rows['audit-ip']['allowed_ips'] ?? null);
        $this->assertSame(strval($admin->user_code), $rows['audit-ip']['user_code'] ?? null);
        $this->assertStringNotContainsString(hash('sha256', 'audit-ip'), json_encode($report->sections()) ?: '', 'never the hash of a key');
    }

    public function testThePageTheCsvAndThePdf(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $admin = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->log($admin, ['created_at' => Carbon::parse('2020-05-01 10:00:00'), 'ip' => '=1+1']);
        $query = ['from' => '2020-04-01', 'to' => '2020-06-30', 'inactive_days' => '30'];

        $this->get(admin_url('log_management'))->assertOk()->assertSee(admin_urls('log_management', 'audit'), false);

        $page = $this->get(admin_urls_query('log_management', 'audit', $query))->assertOk();
        foreach (AuditReport::SECTIONS as $section) {
            $page->assertSee(exmtrans('audit_report.section.' . $section));
        }
        $page->assertSee('value="2020-04-01"', false);

        $csv = $this->get(admin_urls_query('log_management', 'audit', 'new_ips.csv', $query));
        $csv->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF" . exmtrans('audit_report.column.created_at'), $content);
        $this->assertStringContainsString("'=1+1", $content, 'a cell that would start a formula is quoted');
        $this->get(admin_urls_query('log_management', 'audit', 'nothing.csv', $query))->assertNotFound();

        // the PDF; kept in the operation log when the log keeps only the changes
        System::operation_log_scope(LogSettings::SCOPE_CHANGES);
        System::clearCache();
        $before = OperationLog::query()->where('path', 'like', '%log_management/audit.pdf')->count();
        $pdf = $this->get(admin_urls_query('log_management', 'audit.pdf', $query));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', strval($pdf->getContent()));
        $this->assertSame($before + 1, OperationLog::query()->where('path', 'like', '%log_management/audit.pdf')->count());
        System::operation_log_scope(LogSettings::SCOPE_ALL);
    }

    public function testWhoSeesIt(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        // as ajax: the page of "no permission" ends the process on a page request; to ajax it is
        // a 403 with the same message
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $denied = function (\Illuminate\Testing\TestResponse $response, string $what): void {
            $response->assertStatus(403);
            $response->assertSee(trans('admin.deny'), false);
            $response->assertDontSee(exmtrans('audit_report.section.users'), false);
            $this->assertStringNotContainsString('%PDF', strval($response->getContent()), $what);
        };
        $record = AuditReportFile::create(['period_from' => '2020-01-01', 'period_to' => '2020-03-31', 'source' => AuditReportFile::SOURCE_SCHEDULE, 'sha256' => str_repeat('a', 64), 'size' => 0, 'created_at' => Carbon::now()]);
        $reports = AuditReportFile::query()->count();

        $denied($this->get(admin_urls('log_management', 'audit'), $ajax), 'the page');
        $denied($this->get(admin_urls('log_management', 'audit.pdf'), $ajax), 'the PDF');
        $denied($this->get(admin_urls('log_management', 'audit', 'users.csv'), $ajax), 'a CSV');
        $denied($this->get(admin_urls('log_management', 'audit', 'files', $record->id), $ajax), 'a file of the job');
        $denied($this->post(admin_urls('log_management', 'audit', 'verify'), ['report' => UploadedFile::fake()->createWithContent('report.pdf', 'x')], $ajax), 'the check');
        $denied($this->post(admin_urls('log_management', 'audit', 'setting'), ['audit_report_schedule' => AuditReportStore::SCHEDULE_MONTHLY], $ajax), 'the settings');
        System::clearCache();
        $this->assertSame(AuditReportStore::SCHEDULE_OFF, AuditReportStore::schedule(), 'the setting is not changed');
        $this->assertSame($reports, AuditReportFile::query()->count(), 'no PDF made');

        // Audit through a role group
        $user2 = $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->auditGroup($user2);
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->get(admin_urls('log_management', 'audit'))->assertOk();
        $this->get(admin_urls('log_management', 'audit', 'users.csv'))->assertOk();
    }

    public function testThePeriod(): void
    {
        [$from, $to] = AuditReport::previousQuarter(Carbon::parse('2026-11-26 12:00:00'));
        $this->assertSame('2026-07-01', $from->format('Y-m-d'));
        $this->assertSame('2026-09-30', $to->format('Y-m-d'));
        [$from, $to] = AuditReport::previousQuarter(Carbon::parse('2026-02-01'));
        $this->assertSame('2025-10-01', $from->format('Y-m-d'));
        $this->assertSame('2025-12-31', $to->format('Y-m-d'));
    }
}
