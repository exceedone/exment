<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PermissionChangeLog;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\AuditReport\AuditReport;
use Exceedone\Exment\Services\LogManagement\LogCatalog;
use Exceedone\Exment\Services\LogManagement\PermissionChanges;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The history of the changes of the role groups and the sections of the IT audit report on the
 * role groups (ISS02840). Everything a test writes is rolled back.
 */
class PermissionChangeHistoryTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be($this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
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
    protected function login($id): LoginUser
    {
        /** @var LoginUser $user */
        $user = LoginUser::findOrFail($id);
        return $user;
    }

    protected function group(): RoleGroup
    {
        $group = RoleGroup::create(['role_group_name' => 'permission_history_test', 'role_group_view_name' => 'permission history test']);
        System::clearCache();
        return $group;
    }

    /**
     * The records of a group, each "change kind role_type target_type target_id permission".
     *
     * @return array<int, string>
     */
    protected function changes(RoleGroup $group): array
    {
        return PermissionChangeLog::query()->where('role_group_id', $group->id)->orderBy('id')->get()->map(function ($log) {
            return implode(' ', array_filter([$log->change, $log->kind, $log->role_type, $log->target_type, $log->target_id, $log->permission], function ($value) {
                return $value !== null && $value !== '';
            }));
        })->all();
    }

    public function testAChangeOfPermissionsIsRecorded(): void
    {
        $group = $this->group();
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);

        PermissionChanges::around($group->id, function () use ($group, $table) {
            RoleGroupPermission::create(['role_group_id' => $group->id, 'role_group_permission_type' => RoleType::SYSTEM, 'role_group_target_id' => null, 'permissions' => [Permission::AUDIT]]);
            RoleGroupPermission::create(['role_group_id' => $group->id, 'role_group_permission_type' => RoleType::TABLE, 'role_group_target_id' => $table->id, 'permissions' => [Permission::CUSTOM_VALUE_VIEW]]);
        });
        $this->assertSame([
            'added permission 0 audit',
            'added permission 1 ' . $table->id . ' custom_value_view',
        ], $this->changes($group));

        // the same again: nothing; one changed: one removed, one added
        PermissionChanges::around($group->id, function () {
        });
        $permission = RoleGroupPermission::query()->where('role_group_id', $group->id)->where('role_group_permission_type', RoleType::TABLE)->firstOrFail();
        PermissionChanges::around($group->id, function () use ($permission) {
            $permission->permissions = [Permission::CUSTOM_VALUE_EDIT];
            $permission->save();
        });
        $this->assertSame([
            'added permission 1 ' . $table->id . ' custom_value_edit',
            'removed permission 1 ' . $table->id . ' custom_value_view',
        ], array_slice($this->changes($group), 2));

        $log = PermissionChangeLog::query()->where('role_group_id', $group->id)->firstOrFail();
        $this->assertSame(intval($this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id), $log->changed_user_id);
        $this->assertSame('permission history test', $log->role_group_name);
    }

    public function testTheMembersPageAndTheDeletingOfAGroup(): void
    {
        $group = $this->group();
        $user1 = $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);

        // the page of the members of the group
        $this->put(admin_urls('role_group', $group->id) . '?form_type=2', [
            'role_group_item' => ['user_' . $user1->base_user_id, 'user_' . $user2->base_user_id],
        ])->assertRedirect();
        $this->assertSame(['added member user ' . $user1->base_user_id, 'added member user ' . $user2->base_user_id], $this->changes($group));
        $this->assertSame(PermissionChanges::SOURCE_PAGE, PermissionChangeLog::query()->where('role_group_id', $group->id)->value('source'));

        $this->put(admin_urls('role_group', $group->id) . '?form_type=2', [
            'role_group_item' => ['user_' . $user2->base_user_id],
        ])->assertRedirect();
        $this->assertSame('removed member user ' . $user1->base_user_id, $this->changes($group)[2] ?? null);

        // a user deleted: removed from its groups
        $member = new RoleGroupUserOrganization();
        $member->role_group_id = $group->id;
        $member->role_group_user_org_type = 'user';
        $member->role_group_target_id = 999999;
        $member->save();
        $deleted = new class () {
            /** @var int */
            public $id = 999999;
            /** @var CustomTable|null */
            public $custom_table;
        };
        $deleted->custom_table = CustomTable::getEloquent('user');
        RoleGroupUserOrganization::deleteRoleGroupUserOrganization($deleted);
        $last = PermissionChangeLog::query()->where('role_group_id', $group->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame(['removed', 'member', 999999, PermissionChanges::SOURCE_DELETED], [$last->change, $last->kind, $last->target_id, $last->source]);

        // the group deleted: all it had, and the group
        $count = count($this->changes($group));
        $this->delete(admin_urls('role_group', $group->id), [], static::AJAX)->assertStatus(200);
        $this->assertSame(['removed member user ' . $user2->base_user_id, 'removed group'], array_slice($this->changes($group), $count));
    }

    public function testTheSectionsOfTheReport(): void
    {
        $group = $this->group();
        $user2 = $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        PermissionChanges::around($group->id, function () use ($group, $user2) {
            RoleGroupPermission::create(['role_group_id' => $group->id, 'role_group_permission_type' => RoleType::SYSTEM, 'role_group_target_id' => null, 'permissions' => [Permission::AUDIT, Permission::LOGIN_USER]]);
            $member = new RoleGroupUserOrganization();
            $member->role_group_id = $group->id;
            $member->role_group_user_org_type = 'user';
            $member->role_group_target_id = $user2->base_user_id;
            $member->save();
        });
        $report = new AuditReport(Carbon::now()->subDay(), Carbon::now());

        $permissions = array_values(array_filter($report->section('role_group_permissions')['rows'], function ($row) {
            return $row[0] === 'permission history test';
        }));
        $this->assertSame([['permission history test', exmtrans('audit_report.role_type.system'), '', AuditReport::permissionText(Permission::AUDIT) . ', ' . AuditReport::permissionText(Permission::LOGIN_USER)]], $permissions);

        $members = array_values(array_filter($report->section('role_group_members')['rows'], function ($row) {
            return $row[0] === 'permission history test';
        }));
        $this->assertSame([['permission history test', exmtrans('audit_report.member_type.user'), strval($user2->user_code), strval($user2->user_name)]], $members);

        $changes = array_values(array_filter($report->section('permission_changes')['rows'], function ($row) {
            return $row[2] === 'permission history test';
        }));
        $this->assertCount(3, $changes);
        $admin = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertSame(trim($admin->user_code . ' ' . $admin->user_name), $changes[0][1]);
        $this->assertSame(exmtrans('audit_report.change.added'), $changes[0][3]);
        $this->assertContains(AuditReport::permissionText(Permission::AUDIT), array_column($changes, 6));
        $this->assertContains(exmtrans('audit_report.member_type.user') . ': ' . trim($user2->user_code . ' ' . $user2->user_name), array_column($changes, 5));

        // a kind of the log management, with its days
        $this->assertSame(LogCatalog::DEFAULT_PERMISSION_CHANGE_DAYS, LogCatalog::types()['permission_change']['retention_days'] ?? null);
        $old = new PermissionChangeLog();
        $old->forceFill(['role_group_id' => $group->id, 'change' => 'added', 'kind' => 'group', 'created_at' => Carbon::now()->subDays(LogCatalog::DEFAULT_PERMISSION_CHANGE_DAYS + 1)])->save();
        LogCatalog::prune();
        $this->assertFalse(PermissionChangeLog::query()->whereKey($old->id)->exists(), 'deleted after the days kept');
        $this->assertCount(3, $this->changes($group));
    }
}
