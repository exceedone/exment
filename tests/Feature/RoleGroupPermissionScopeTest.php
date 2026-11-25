<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;

/**
 * A user who may only set the members of role groups (ROLE_GROUP_USER_ORGANIZATION) changes the
 * members, and nothing more: creating, deleting and importing role groups (an import sets the
 * permissions too) need the permission of all role groups (ROLE_GROUP_ALL).
 */
class RoleGroupPermissionScopeTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['exment.role_group_import_export' => true]);
    }

    protected function tearDown(): void
    {
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    protected function actAsMemberSetter(): void
    {
        $role_group = new RoleGroup();
        $role_group->role_group_name = 'member_setter_test';
        $role_group->role_group_view_name = 'member setter test';
        $role_group->save();
        RoleGroupPermission::create([
            'role_group_id' => $role_group->id,
            'role_group_permission_type' => RoleType::SYSTEM,
            'role_group_target_id' => 1,
            'permissions' => ['role_group_user_organization'],
        ]);
        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertInstanceOf(LoginUser::class, $user);
        RoleGroupUserOrganization::create([
            'role_group_id' => $role_group->id,
            'role_group_user_org_type' => SystemTableName::USER,
            'role_group_target_id' => $user->base_user_id,
        ]);
        System::clearCache();
        System::clearRequestSession();
        $this->be($user);
    }

    public function testAMemberSetterDoesNotDeleteARoleGroup(): void
    {
        $this->actAsMemberSetter();
        $target = RoleGroup::where('role_group_name', 'data_admin_group')->first();
        $this->assertNotNull($target);

        System::clearRequestSession();
        $this->delete(admin_urls('role_group', $target->id), [], static::AJAX);

        $this->assertNotNull(RoleGroup::find($target->id));
    }

    public function testAMemberSetterDoesNotImportRoleGroups(): void
    {
        $this->actAsMemberSetter();
        $file = UploadedFile::fake()->create('role_group.xlsx', 1);

        System::clearRequestSession();
        $this->post(admin_url('role_group/import'), ['custom_table_file' => $file, 'select_primary_key' => 'id'], static::AJAX)
            ->assertStatus(403);
    }

    public function testTheAdministratorStillDeletesARoleGroup(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $target = new RoleGroup();
        $target->role_group_name = 'to_delete_test';
        $target->role_group_view_name = 'to delete test';
        $target->save();

        System::clearRequestSession();
        $this->delete(admin_urls('role_group', $target->id), [], static::AJAX)->assertStatus(200);

        $this->assertNull(RoleGroup::find($target->id));
    }
}
