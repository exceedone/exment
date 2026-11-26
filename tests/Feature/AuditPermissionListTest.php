<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PermissionChangeLog;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\AuditReport\AuditReport;
use Exceedone\Exment\Services\AuditReport\PermissionMatrix;
use Exceedone\Exment\Services\TablePolicy;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The permission list of the IT audit report (ISS02911): for all the users at once, the same
 * answer as the permissions of a request (CustomTable::hasPermission), through the role groups of
 * the users, of their organizations and of the organizations above or below; the counts of each
 * table; the changes of the administrators in the last month.
 */
class AuditPermissionListTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    /**
     * @param int|string $id
     */
    protected function loginAs($id): LoginUser
    {
        /** @var LoginUser $user */
        $user = LoginUser::findOrFail($id);
        $this->be($user);
        System::clearRequestSession();
        return $user;
    }

    public function testTheSameAnswerAsARequest(): void
    {
        $logins = [
            TestDefine::TESTDATA_USER_LOGINID_ADMIN,
            TestDefine::TESTDATA_USER_LOGINID_USER1,
            TestDefine::TESTDATA_USER_LOGINID_USER2,
            TestDefine::TESTDATA_USER_LOGINID_DEV_USERB,
            TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC,
            TestDefine::TESTDATA_USER_LOGINID_DEV1_USERD,
        ];
        $users = [];
        foreach ($logins as $login) {
            $users[$login] = intval(LoginUser::findOrFail($login)->base_user_id);
        }
        $admins = collect(System::system_admin_users() ?? [])->map(function ($id) {
            return strval($id);
        })->all();
        $matrix = (new PermissionMatrix(array_values($users), $admins))->matrix();

        $checked = 0;
        foreach ($logins as $login) {
            $this->loginAs($login);
            $cells = $matrix[$users[$login]] ?? [];
            foreach (CustomTable::allRecords() as $table) {
                if (!is_null(TablePolicy::of($table->table_name))) {
                    continue;
                }
                $cell = $cells[intval($table->id)] ?? null;
                $all = $cells[PermissionMatrix::ALL_TABLES] ?? null;
                if (isset($all) && (is_null($cell) || array_search($all['level'], PermissionMatrix::LEVELS, true) < array_search($cell['level'], PermissionMatrix::LEVELS, true))) {
                    $cell = $all;
                }
                $level = $cell['level'] ?? null;
                $what = "login {$login}, table {$table->table_name}";
                $this->assertSame($table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE), isset($level) && PermissionMatrix::canEdit($level), 'edit: ' . $what);
                $this->assertSame($table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE), isset($level) && PermissionMatrix::canView($level), 'view: ' . $what);
                $checked++;
            }
        }
        $this->assertGreaterThan(20, $checked);
        // the test data gives some through organizations
        $sources = [];
        foreach ($matrix as $cells) {
            foreach ($cells as $cell) {
                $sources = array_merge($sources, $cell['sources']);
            }
        }
        $this->assertNotEmpty(array_filter($sources, function ($source) {
            return str_contains($source, ' → ');
        }), 'a permission through an organization');
    }

    public function testTheSections(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $report = new AuditReport(Carbon::now()->subMonth(), Carbon::now());

        $rows = $report->section('user_table_access')['rows'];
        $this->assertContains(['admin', exmtrans('audit_report.permission_list.all_tables')], array_map(function ($row) {
            return [$row[0], $row[2]];
        }, $rows));
        $user1 = array_values(array_filter($rows, function ($row) {
            return $row[0] === 'user1';
        }));
        $this->assertNotEmpty($user1);

        $tables = $report->section('table_access')['rows'];
        $this->assertCount(CustomTable::allRecords()->count(), $tables);
        foreach ($tables as $row) {
            // the system administrator at least
            $this->assertGreaterThanOrEqual(1, intval($row[1]), $row[0]);
        }

        // only a table, only a user
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $this->assertNotNull($table);
        $user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $only = (new AuditReport(Carbon::now()->subMonth(), Carbon::now()))->filterData(intval($table->id), intval($user->base_user_id));
        $this->assertCount(1, $only->section('table_access')['rows']);
        foreach ($only->section('user_table_access')['rows'] as $row) {
            $this->assertSame('user1', $row[0]);
            $this->assertContains($row[2], [strval($table->table_view_name), exmtrans('audit_report.permission_list.all_tables')]);
        }
    }

    public function testTheChangesOfTheAdministrators(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $group = RoleGroup::query()->firstOrFail();
        $row = [
            'role_group_id' => $group->id,
            'role_group_name' => 'AuditPermissionListTest',
            'change' => PermissionChangeLog::CHANGE_ADDED,
            'kind' => PermissionChangeLog::KIND_PERMISSION,
            'role_type' => strval(RoleType::SYSTEM),
            'permission' => Permission::SYSTEM,
            'source' => 'page',
        ];
        PermissionChangeLog::query()->insert($row + ['created_at' => Carbon::now()->subDays(3)]);
        // more than a month before: not in it
        PermissionChangeLog::query()->insert(['role_group_name' => 'AuditPermissionListTest old'] + $row + ['created_at' => Carbon::now()->subMonths(2)]);
        // a table: not an administrator
        PermissionChangeLog::query()->insert(['role_group_name' => 'AuditPermissionListTest table', 'role_type' => strval(RoleType::TABLE), 'permission' => Permission::CUSTOM_VALUE_VIEW] + $row + ['created_at' => Carbon::now()->subDays(3)]);

        $report = new AuditReport(Carbon::now()->subMonths(3), Carbon::now());
        $names = array_column($report->section('administrator_changes')['rows'], 2);
        $this->assertContains('AuditPermissionListTest', $names);
        $this->assertNotContains('AuditPermissionListTest old', $names);
        $this->assertNotContains('AuditPermissionListTest table', $names);
    }
}
