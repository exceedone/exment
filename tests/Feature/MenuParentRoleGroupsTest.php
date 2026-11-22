<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * A parent menu (a group of the menu) shown only to some role groups (ISS02822):
 * empty is everyone as before, the system administrators always see it, and a
 * template carries the role groups by their name.
 */
class MenuParentRoleGroupsTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function makeRoleGroup(string $name): RoleGroup
    {
        $role = new RoleGroup();
        $role->role_group_name = $name;
        $role->role_group_view_name = $name;
        $role->save();
        return $role;
    }

    /**
     * @param array<int, int> $roleGroupIds
     * @return array<string, mixed>
     */
    protected function group(array $roleGroupIds): array
    {
        return [
            'menu_type' => 'parent_node',
            'menu_name' => 'test_group',
            'options' => ['visible_role_groups' => $roleGroupIds],
            // an outside URL is visible to everyone, so only the group decides
            'children' => [['menu_type' => 'custom', 'uri' => 'https://example.com/']],
        ];
    }

    public function testAGroupWithRoleGroupsIsOnlyForTheirMembers(): void
    {
        $role = $this->makeRoleGroup('menu_group_test');
        $member = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        RoleGroupUserOrganization::insert(['role_group_id' => $role->id, 'role_group_user_org_type' => 'user', 'role_group_target_id' => $member->base_user_id]);
        $other = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $admin = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $this->assertTrue($member->visible($this->group([$role->id])), 'a member sees it');
        $this->assertFalse($other->visible($this->group([$role->id])), 'someone else does not');
        $this->assertTrue($admin->visible($this->group([$role->id])), 'a system administrator always does');
        $this->assertTrue($other->visible($this->group([])), 'no role groups: everyone, as before');
    }

    public function testTheOptionKeepsOnlyIds(): void
    {
        $menu = new Menu();
        $menu->setAttribute('visible_role_groups', ['3', '', 'x', '3', '7']);
        $this->assertSame([3, 7], $menu->visible_role_groups);
        $menu->setAttribute('visible_role_groups', []);
        $this->assertNull($menu->getOption('visible_role_groups'));
    }

    public function testATemplateCarriesTheRoleGroupsByName(): void
    {
        $role = $this->makeRoleGroup('menu_group_template');
        $json = ['menu_type' => 'parent_node', 'menu_name' => 'x', 'options' => ['visible_role_groups' => [$role->id, 999999]]];
        Menu::exportReplaceJson($json);
        $this->assertSame(['menu_group_template'], array_get($json, 'options.visible_role_group_names'));
        $this->assertNull(array_get($json, 'options.visible_role_groups'), 'no id is carried');

        $json['options']['visible_role_group_names'][] = 'not_on_this_site';
        $method = new \ReflectionMethod(Menu::class, 'importVisibleRoleGroups');
        $method->setAccessible(true);
        $args = [&$json];
        $method->invokeArgs(null, $args);
        $this->assertSame([intval($role->id)], array_get($json, 'options.visible_role_groups'));
        $this->assertNull(array_get($json, 'options.visible_role_group_names'));
    }
}
