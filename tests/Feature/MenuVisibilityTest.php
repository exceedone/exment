<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\DB;

/**
 * Who sees which menu (ISS02831): what the left menu, the editing of the
 * menus and the template export show, for each thing that hides a menu -
 * a feature not used (Feature management), demo mode, the menu of a plugin
 * turned off, the permissions, a group with nothing to show, a group only
 * for some role groups, and a system administrator.
 *
 * Written before the decision was moved into one place
 * (Services\MenuVisibility), to keep the results as they were.
 */
class MenuVisibilityTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    /** @var int a role group of user1 only */
    protected $roleGroupId;

    /** @var string */
    protected $pluginName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Features::reset();
        config(['exment.features_locked' => '']);
        $this->makeMenus();
    }

    protected function tearDown(): void
    {
        config(['exment.demo_mode' => false]);
        Features::reset();
        System::clearRequestSession();
        parent::tearDown();
    }

    // the menus of the test ------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     */
    protected function insertMenu(array $row): int
    {
        if (isset($row['options']) && is_array($row['options'])) {
            $row['options'] = json_encode($row['options']);
        }
        return intval(DB::table('admin_menu')->insertGetId($row + [
            'parent_id' => 0,
            'order' => 900,
            'icon' => 'fa-star',
            'uri' => null,
            'menu_target' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    protected function makeMenus(): void
    {
        $role = new RoleGroup();
        $role->role_group_name = 'mvis_role';
        $role->role_group_view_name = 'mvis_role';
        $role->save();
        $this->roleGroupId = intval($role->id);
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        RoleGroupUserOrganization::insert(['role_group_id' => $role->id, 'role_group_user_org_type' => 'user', 'role_group_target_id' => $user1->base_user_id]);

        $plugin = new Plugin();
        $plugin->uuid = (string)\Illuminate\Support\Str::uuid();
        $plugin->plugin_name = $this->pluginName = 'MvisTestPlugin';
        $plugin->plugin_view_name = 'Mvis';
        $plugin->plugin_types = \Exceedone\Exment\Enums\PluginType::PAGE;
        $plugin->active_flg = false;
        $plugin->version = '1.0.0';
        $plugin->save();

        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $organization = CustomTable::getEloquent(SystemTableName::ORGANIZATION);

        // a group of the site with something in it for everyone
        $open = $this->insertMenu(['title' => 'mvis-open', 'menu_type' => 'parent_node', 'menu_name' => 'mvis_open']);
        $this->insertMenu(['parent_id' => $open, 'order' => 1, 'title' => 'mvis-table', 'menu_type' => 'table', 'menu_name' => 'mvis_table', 'menu_target' => $table->id]);
        $this->insertMenu(['parent_id' => $open, 'order' => 2, 'title' => 'mvis-sys-menu', 'menu_type' => 'system', 'menu_name' => 'mvis_sys_menu', 'menu_target' => 'menu']);
        $this->insertMenu(['parent_id' => $open, 'order' => 3, 'title' => 'mvis-sys-studio', 'menu_type' => 'system', 'menu_name' => 'mvis_sys_studio', 'menu_target' => 'studio']);
        $this->insertMenu(['parent_id' => $open, 'order' => 4, 'title' => 'mvis-studio-link', 'menu_type' => 'custom', 'menu_name' => 'mvis_studio_link', 'uri' => 'studio']);
        $this->insertMenu(['parent_id' => $open, 'order' => 5, 'title' => 'mvis-sys-backup', 'menu_type' => 'system', 'menu_name' => 'mvis_sys_backup', 'menu_target' => 'backup']);
        $this->insertMenu(['parent_id' => $open, 'order' => 6, 'title' => 'mvis-plugin', 'menu_type' => 'plugin', 'menu_name' => 'mvis_plugin', 'menu_target' => $plugin->id]);
        $this->insertMenu(['parent_id' => $open, 'order' => 7, 'title' => 'mvis-org', 'menu_type' => 'table', 'menu_name' => 'mvis_org', 'menu_target' => $organization->id]);
        $this->insertMenu(['parent_id' => $open, 'order' => 8, 'title' => 'mvis-ext', 'menu_type' => 'custom', 'menu_name' => 'mvis_ext', 'uri' => 'https://example.com/mvis']);
        // a menu of a table or a plugin that is not there any more
        $this->insertMenu(['parent_id' => $open, 'order' => 9, 'title' => 'mvis-gone-table', 'menu_type' => 'table', 'menu_name' => 'mvis_gone_table', 'menu_target' => 999999]);
        $this->insertMenu(['parent_id' => $open, 'order' => 10, 'title' => 'mvis-gone-plugin', 'menu_type' => 'plugin', 'menu_name' => 'mvis_gone_plugin', 'menu_target' => 999999]);

        // a group only for a role group (user1's)
        $limited = $this->insertMenu(['title' => 'mvis-limited', 'menu_type' => 'parent_node', 'menu_name' => 'mvis_limited', 'options' => ['visible_role_groups' => [$this->roleGroupId]]]);
        $this->insertMenu(['parent_id' => $limited, 'title' => 'mvis-limited-ext', 'menu_type' => 'custom', 'menu_name' => 'mvis_limited_ext', 'uri' => 'https://example.com/limited']);

        // a group with only the settings of the administrators in it
        $admin = $this->insertMenu(['title' => 'mvis-admin', 'menu_type' => 'parent_node', 'menu_name' => 'mvis_admin']);
        $this->insertMenu(['parent_id' => $admin, 'title' => 'mvis-admin-role', 'menu_type' => 'system', 'menu_name' => 'mvis_admin_role', 'menu_target' => 'role_group']);

        // a group of the feature map with only a menu of a feature in it
        $map = $this->insertMenu(['title' => 'mvis-map', 'menu_type' => 'parent_node', 'menu_name' => 'hr']);
        $this->insertMenu(['parent_id' => $map, 'title' => 'mvis-map-studio', 'menu_type' => 'custom', 'menu_name' => 'mvis_map_studio', 'uri' => 'studio']);

        // a group of the site with only a menu of a feature in it
        $site = $this->insertMenu(['title' => 'mvis-site', 'menu_type' => 'parent_node', 'menu_name' => 'mvis_site']);
        $this->insertMenu(['parent_id' => $site, 'title' => 'mvis-site-studio', 'menu_type' => 'custom', 'menu_name' => 'mvis_site_studio', 'uri' => 'studio']);

        // groups with nothing in them: one of the feature map, one of the site
        $this->insertMenu(['title' => 'mvis-empty-map', 'menu_type' => 'parent_node', 'menu_name' => 'sales']);
        $this->insertMenu(['title' => 'mvis-empty-site', 'menu_type' => 'parent_node', 'menu_name' => 'mvis_empty_site']);

        System::clearRequestSession();
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function setFeatures(array $settings): void
    {
        Features::save($settings);
        System::clearRequestSession();
        Features::reset();
    }

    protected function login(string $loginId): LoginUser
    {
        System::clearRequestSession();
        Features::reset();
        $user = LoginUser::findOrFail($loginId);
        $this->be($user);
        return $user;
    }

    // what each place shows -----------------------------------------------------------

    /**
     * The rows of the menu (Menu::allNodes()) of the test, by their title.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function rows(bool $withHidden = false): array
    {
        $menu = new Menu();
        if ($withHidden) {
            $menu->withHidden();
        }
        return collect($menu->allNodes())->filter(function ($row) {
            return str_starts_with(strval($row['title']), 'mvis-');
        })->keyBy('title')->all();
    }

    /**
     * The titles of the left menu of the user, as the sidebar walks the tree:
     * a node the user sees, and in it the children the user sees.
     *
     * @return string[]
     */
    protected function leftMenu(LoginUser $user): array
    {
        $titles = [];
        $walk = function (array $nodes) use (&$walk, &$titles, $user) {
            foreach ($nodes as $node) {
                if (!$user->visible($node)) {
                    continue;
                }
                $titles[] = strval($node['title']);
                if (isset($node['children'])) {
                    $walk($node['children']);
                }
            }
        };
        $walk((new Menu())->toTree());
        return array_values(array_filter($titles, function ($title) {
            return str_starts_with($title, 'mvis-');
        }));
    }

    /**
     * The left menu as the page shows it.
     */
    protected function sidebar(): string
    {
        $response = $this->get(admin_url('data/' . TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL));
        $response->assertOk();
        $html = strval($response->getContent());
        $start = strpos($html, '<ul class="sidebar-menu">');
        $end = strpos($html, '<!-- /.sidebar-menu -->');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        return substr($html, intval($start), intval($end) - intval($start));
    }

    /**
     * The menus a template takes (TemplateExporter::setTemplateMenu), of the test.
     *
     * @param string[] $targetTables
     * @return string[]
     */
    protected function exported(array $targetTables = []): array
    {
        $config = [];
        $method = new \ReflectionMethod(\Exceedone\Exment\Services\TemplateImportExport\TemplateExporter::class, 'setTemplateMenu');
        $method->setAccessible(true);
        $method->invokeArgs(null, [&$config, $targetTables, false]);
        $names = array_map('strval', array_column($config['admin_menu'] ?? [], 'menu_name'));
        return array_values(array_filter($names, function ($name) {
            return str_starts_with($name, 'mvis_') || in_array($name, ['hr', 'sales'], true);
        }));
    }

    // the tests --------------------------------------------------------------------------

    public function testTheRowsOfTheMenu(): void
    {
        $this->setFeatures(['studio' => ['enabled' => true, 'role_groups' => null, 'menu' => true]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $rows = $this->rows();
        // the menus of a table or a plugin that is not there: never
        $this->assertArrayNotHasKey('mvis-gone-table', $rows);
        $this->assertArrayNotHasKey('mvis-gone-plugin', $rows);
        // what a row gets: the uri, the icon
        $this->assertSame('data/' . TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $rows['mvis-table']['uri']);
        $this->assertSame('auth/menu', $rows['mvis-sys-menu']['uri']);
        $this->assertSame('plugins/mvis_test_plugin', $rows['mvis-plugin']['uri']);
        $this->assertNull($rows['mvis-open']['uri']);
        $this->assertArrayNotHasKey('feature_hidden', $rows['mvis-sys-studio']);
        // the same with the hidden ones, nothing is hidden
        $this->assertSame(array_keys($rows), array_keys($this->rows(true)));
    }

    public function testAFeatureNotUsed(): void
    {
        $this->setFeatures(['studio' => ['enabled' => false, 'role_groups' => null, 'menu' => true]]);
        $admin = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        // its system menu and a menu to its page: not in the menu of the pages
        $rows = $this->rows();
        $this->assertArrayNotHasKey('mvis-sys-studio', $rows);
        $this->assertArrayNotHasKey('mvis-studio-link', $rows);
        $this->assertArrayNotHasKey('mvis-map-studio', $rows);
        $this->assertArrayHasKey('mvis-table', $rows);

        // kept and marked for the editing and the templates
        $hidden = $this->rows(true);
        foreach (['mvis-sys-studio', 'mvis-studio-link', 'mvis-map-studio', 'mvis-site-studio'] as $title) {
            $this->assertTrue($hidden[$title]['feature_hidden'] ?? false, $title);
        }
        $this->assertArrayNotHasKey('feature_hidden', $hidden['mvis-table']);

        // the left menu: a group of the feature map with nothing left goes,
        // a group of the site with nothing left stays (as an empty group of the site)
        $left = $this->leftMenu($admin);
        $this->assertNotContains('mvis-sys-studio', $left);
        $this->assertNotContains('mvis-studio-link', $left);
        $this->assertNotContains('mvis-map', $left);
        $this->assertContains('mvis-site', $left);
        $this->assertNotContains('mvis-site-studio', $left);

        // a template takes them all
        $exported = $this->exported();
        foreach (['mvis_sys_studio', 'mvis_studio_link', 'hr', 'mvis_map_studio', 'mvis_site', 'mvis_site_studio'] as $name) {
            $this->assertContains($name, $exported, $name);
        }

        // the editing of the menus shows them, marked
        $page = $this->get(admin_url('auth/menu'));
        $page->assertOk();
        $page->assertSee('mvis-sys-studio');
        $page->assertSee('mvis-studio-link');
        $page->assertSee(exmtrans('menu.feature_hidden'));
    }

    public function testTheMenuOfAFeatureTurnedOff(): void
    {
        $this->setFeatures(['studio' => ['enabled' => true, 'role_groups' => null, 'menu' => false]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $rows = $this->rows();
        // the system menu goes, a menu to the page stays (the page is there)
        $this->assertArrayNotHasKey('mvis-sys-studio', $rows);
        $this->assertArrayHasKey('mvis-studio-link', $rows);
        $this->assertTrue($this->rows(true)['mvis-sys-studio']['feature_hidden'] ?? false);
    }

    public function testAFeatureForSomeRoleGroups(): void
    {
        $this->setFeatures(['studio' => ['enabled' => true, 'role_groups' => [$this->roleGroupId], 'menu' => true]]);

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertArrayHasKey('mvis-sys-studio', $this->rows());
        $this->assertArrayHasKey('mvis-studio-link', $this->rows());

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertArrayNotHasKey('mvis-sys-studio', $this->rows());
        $this->assertArrayNotHasKey('mvis-studio-link', $this->rows());
        $this->assertTrue($this->rows(true)['mvis-studio-link']['feature_hidden'] ?? false);

        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertArrayHasKey('mvis-sys-studio', $this->rows());
    }

    public function testDemoMode(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertArrayHasKey('mvis-sys-backup', $this->rows());

        config(['exment.demo_mode' => true]);
        // not in the menu, not in the editing either, not in a template
        $this->assertArrayNotHasKey('mvis-sys-backup', $this->rows());
        $this->assertArrayNotHasKey('mvis-sys-backup', $this->rows(true));
        $this->assertNotContains('mvis_sys_backup', $this->exported());
        $this->assertArrayHasKey('mvis-sys-menu', $this->rows());
    }

    public function testTheMenuOfAPluginTurnedOff(): void
    {
        $admin = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertContains('mvis-plugin', $this->leftMenu($admin));

        $this->setFeatures([Features::PLUGIN_PREFIX . $this->pluginName => ['menu' => false]]);
        $admin = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertArrayNotHasKey('mvis-plugin', $this->rows());
        $this->assertTrue($this->rows(true)['mvis-plugin']['feature_hidden'] ?? false);
        $this->assertNotContains('mvis-plugin', $this->leftMenu($admin));
        $this->assertContains('mvis_plugin', $this->exported());
    }

    public function testThePermissions(): void
    {
        $this->setFeatures(['studio' => ['enabled' => true, 'role_groups' => null, 'menu' => true]]);

        $admin = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertSame([
            'mvis-open', 'mvis-table', 'mvis-sys-menu', 'mvis-sys-studio', 'mvis-studio-link', 'mvis-sys-backup', 'mvis-plugin', 'mvis-org', 'mvis-ext',
            'mvis-limited', 'mvis-limited-ext',
            'mvis-admin', 'mvis-admin-role',
            'mvis-map', 'mvis-map-studio',
            'mvis-site', 'mvis-site-studio',
            'mvis-empty-site',
        ], $this->leftMenu($admin));

        $user2 = $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $left = $this->leftMenu($user2);
        // a table the user may see, an outside URL
        $this->assertContains('mvis-open', $left);
        $this->assertContains('mvis-table', $left);
        $this->assertContains('mvis-ext', $left);
        // the settings of the administrators: not, nor their group
        $this->assertNotContains('mvis-sys-menu', $left);
        $this->assertNotContains('mvis-sys-backup', $left);
        $this->assertNotContains('mvis-admin', $left);
        $this->assertNotContains('mvis-admin-role', $left);
        // a group only for user1's role group: not
        $this->assertNotContains('mvis-limited', $left);
        $this->assertNotContains('mvis-limited-ext', $left);
        // the groups with nothing in them
        $this->assertNotContains('mvis-empty-map', $left);
        $this->assertSame($left, $this->leftMenu($user2), 'the same the second time');

        // with a table of the target tables, a template takes the menus of those tables
        // and the groups they are in (as the user who exports sees them)
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertSame(['mvis_open', 'mvis_table'], $this->exported([TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL]));
    }

    public function testAGroupForSomeRoleGroups(): void
    {
        $this->assertContains('mvis-limited', $this->leftMenu($this->login(TestDefine::TESTDATA_USER_LOGINID_USER1)));
        $this->assertContains('mvis-limited-ext', $this->leftMenu($this->login(TestDefine::TESTDATA_USER_LOGINID_USER1)));
        $this->assertNotContains('mvis-limited', $this->leftMenu($this->login(TestDefine::TESTDATA_USER_LOGINID_USER2)));
        $this->assertContains('mvis-limited', $this->leftMenu($this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN)));

        // the editing of the menus and a template: the group is there, whoever looks
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertArrayHasKey('mvis-limited', $this->rows(true));
        $this->assertContains('mvis_limited', $this->exported());
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertArrayHasKey('mvis-limited', $this->rows());
    }

    public function testTheOrganizationsNotUsed(): void
    {
        $admin = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertContains('mvis-org', $this->leftMenu($admin));

        System::organization_available(false);
        $admin = $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertNotContains('mvis-org', $this->leftMenu($admin));
        // the row is there: only the left menu leaves it out
        $this->assertArrayHasKey('mvis-org', $this->rows());
    }

    public function testTheLeftMenuOfThePage(): void
    {
        $this->setFeatures(['studio' => ['enabled' => false, 'role_groups' => null, 'menu' => true]]);

        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $sidebar = $this->sidebar();
        foreach (['mvis-open', 'mvis-table', 'mvis-sys-menu', 'mvis-plugin', 'mvis-limited', 'mvis-admin-role', 'mvis-site', 'mvis-empty-site'] as $title) {
            $this->assertStringContainsString('<span>' . $title . '</span>', $sidebar, $title);
        }
        foreach (['mvis-sys-studio', 'mvis-studio-link', 'mvis-map', 'mvis-empty-map', 'mvis-gone-table', 'mvis-gone-plugin'] as $title) {
            $this->assertStringNotContainsString('<span>' . $title . '</span>', $sidebar, $title);
        }

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $sidebar = $this->sidebar();
        foreach (['mvis-open', 'mvis-table', 'mvis-ext'] as $title) {
            $this->assertStringContainsString('<span>' . $title . '</span>', $sidebar, $title);
        }
        foreach (['mvis-sys-menu', 'mvis-limited', 'mvis-admin', 'mvis-sys-studio', 'mvis-map', 'mvis-empty-map'] as $title) {
            $this->assertStringNotContainsString('<span>' . $title . '</span>', $sidebar, $title);
        }
    }
}
