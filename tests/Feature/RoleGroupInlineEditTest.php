<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The order of a role group is edited in the list: x-editable sends `_editable=1`, the inline
 * edit of admin-core 2.0 sends `_edit_inline` (ISS02862). Both change the order only, not the
 * whole role group.
 */
class RoleGroupInlineEditTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    protected function roleGroup(): RoleGroup
    {
        $role_group = new RoleGroup();
        $role_group->role_group_name = 'inline_edit_test';
        $role_group->role_group_view_name = 'inline edit test';
        $role_group->role_group_order = 1;
        $role_group->save();
        return $role_group;
    }

    public function testXEditableEditsTheOrder(): void
    {
        // x-editable posts the field as name / value / pk (Form::handleEditable())
        $this->assertTheOrderIsEditedInTheList(fn (RoleGroup $role_group) => [
            '_editable' => '1',
            'name' => 'role_group_order',
            'value' => 7,
            'pk' => $role_group->id,
        ]);
    }

    public function testTheInlineEditOfAdminCoreTwoEditsTheOrder(): void
    {
        $this->assertTheOrderIsEditedInTheList(fn (RoleGroup $role_group) => [
            '_edit_inline' => 'true',
            'role_group_order' => 7,
        ]);
    }

    /**
     * @param \Closure(RoleGroup): array<string, mixed> $payload what the list sends
     */
    protected function assertTheOrderIsEditedInTheList(\Closure $payload): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $role_group = $this->roleGroup();

        System::clearRequestSession();
        $this->put(admin_urls('role_group', $role_group->id), $payload($role_group) + [
            '_method' => 'PUT',
        ], static::AJAX);

        /** @var RoleGroup $role_group */
        $role_group = RoleGroup::query()->findOrFail($role_group->id);
        $this->assertSame(7, (int) $role_group->role_group_order);
        // not saved as the whole form (which would empty the name shown)
        $this->assertSame('inline edit test', $role_group->role_group_view_name);
    }
}
