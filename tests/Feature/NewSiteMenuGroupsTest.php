<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\DB;

/**
 * The first menu of a new site (system_template, ISS02819): the settings in
 * three groups, the master data under "Master", the file server under
 * "Groupware". "admin" keeps its name, so what is added under it later (the
 * 7.2 migrations, the feature management, the safety check) still finds it.
 */
class NewSiteMenuGroupsTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    /**
     * @return string[]
     */
    protected function children(string $parent): array
    {
        $id = DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', $parent)->value('id');
        $this->assertNotNull($id, "the parent $parent");
        return DB::table('admin_menu')->where('parent_id', $id)->orderBy('order')->orderBy('id')->pluck('menu_name')->map(function ($name) {
            return strval($name);
        })->all();
    }

    public function testTheSettingsAreInThreeGroups(): void
    {
        // no "notify": its page only says that the notifications moved (GitHub #2289)
        $this->assertSame(['custom_table', 'studio', 'menu', 'workflow', 'mail_template', 'cell_style_preset', 'template', 'trash'], $this->children('admin_data'));
        $this->assertSame(['loginuser', 'role_group', 'login_setting', 'api_setting', 'operation_log'], $this->children('admin_user'));
        // the safety check is added under "admin" by its installer
        $this->assertSame(['system', 'safety_check_setting', 'meili_setting', 'feature', 'plugin', 'backup', 'tuning', 'security_check', 'scheduled_jobs', 'sync', 'log_management', 'maintenance'], array_values(array_diff($this->children('admin'), ['safety_check'])));
    }

    // GitHub #2289: a site made with the menu of 7.2.1 to 7.2.x loses the item, and only that one
    public function testTheOldNotifyItemIsRemovedOnlyWhileItIsThatItem(): void
    {
        $parent = DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', 'admin_data')->value('id');
        $row = ['parent_id' => $parent, 'order' => 6, 'title' => '通知', 'icon' => 'fa-bell', 'menu_type' => 'system', 'menu_name' => 'notify', 'created_at' => now(), 'updated_at' => now()];
        $old = DB::table('admin_menu')->insertGetId($row + ['uri' => 'notify', 'menu_target' => 'notify']);
        $elsewhere = DB::table('admin_menu')->insertGetId(array_merge($row, ['uri' => 'data/information', 'menu_target' => 'notify']));
        $withChild = DB::table('admin_menu')->insertGetId($row + ['uri' => 'notify', 'menu_target' => 'notify']);
        DB::table('admin_menu')->insert(['parent_id' => $withChild, 'order' => 1, 'title' => 'x', 'icon' => '', 'uri' => 'system', 'menu_type' => 'system', 'menu_name' => 'system', 'menu_target' => 'system', 'created_at' => now(), 'updated_at' => now()]);

        $migration = require __DIR__ . '/../../database/migrations/2026_12_11_000000_remove_old_notify_menu.php';
        $migration->up();
        $migration->up();

        $this->assertFalse(DB::table('admin_menu')->where('id', $old)->exists());
        $this->assertTrue(DB::table('admin_menu')->where('id', $elsewhere)->exists());
        $this->assertTrue(DB::table('admin_menu')->where('id', $withChild)->exists());
        $this->assertSame(['custom_table', 'studio', 'menu', 'workflow', 'mail_template', 'cell_style_preset', 'template', 'trash'], array_values(array_diff($this->children('admin_data'), ['notify'])));
    }

    public function testTheGroupsAreInOrder(): void
    {
        $roots = DB::table('admin_menu')->where('parent_id', 0)->orderBy('order')->orderBy('id')->pluck('menu_name')->map(function ($name) {
            return strval($name);
        })->all();
        $known = array_values(array_intersect($roots, ['home', 'groupware', 'master', 'admin_data', 'admin_user', 'admin']));
        $this->assertSame(['home', 'groupware', 'master', 'admin_data', 'admin_user', 'admin'], $known);
        $this->assertSame(['file_server'], $this->children('groupware'));
        $this->assertSame(['base_info', 'organization', 'user', 'information'], $this->children('master'));
    }

    public function testAGroupIsMadeInTheOrderOfTheFeatureMap(): void
    {
        $id = \Exceedone\Exment\Model\Menu::groupParentId('project');
        $this->assertNotNull($id);
        $this->assertSame($id, \Exceedone\Exment\Model\Menu::groupParentId('project'), 'the second time finds it');
        $this->assertNull(\Exceedone\Exment\Model\Menu::groupParentId('no_such_group'));
        $row = DB::table('admin_menu')->where('id', $id)->first();
        $this->assertSame(0, intval($row->parent_id));
        $this->assertSame('parent_node', $row->menu_type);
        $this->assertSame(\Exceedone\Exment\Model\Menu::systemTitle('project'), $row->title);

        $roots = DB::table('admin_menu')->where('parent_id', 0)->orderBy('order')->orderBy('id')->pluck('menu_name')->map(function ($name) {
            return strval($name);
        })->all();
        $known = array_values(array_intersect($roots, ['home', 'groupware', 'project', 'master', 'admin_data', 'admin_user', 'admin']));
        $this->assertSame(['home', 'groupware', 'project', 'master', 'admin_data', 'admin_user', 'admin'], $known);
    }

    public function testAGroupWithNothingToShowIsHidden(): void
    {
        $admin = \Exceedone\Exment\Model\LoginUser::findOrFail(\Exceedone\Exment\Tests\TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        // the menus of a feature turned off are taken out of the tree before the check
        $this->assertFalse($admin->visible(['menu_type' => 'parent_node', 'menu_name' => 'groupware']));
        $this->assertFalse($admin->visible(['menu_type' => 'parent_node', 'menu_name' => 'groupware', 'children' => []]));
        $this->assertTrue($admin->visible(['menu_type' => 'parent_node', 'menu_name' => 'groupware', 'children' => [['menu_type' => 'custom', 'uri' => 'https://example.com/']]]));
        // a parent an administrator made, empty: shown as before (MenuNullUriTest)
        $this->assertTrue($admin->visible(['menu_type' => 'parent_node', 'menu_name' => 'my_folder', 'uri' => null]));
    }

    public function testEveryMenuOfTheTemplateIsASystemMenuOrATable(): void
    {
        $config = json_decode((string) file_get_contents(exment_package_path('system_template/config.json')), true);
        foreach ($config['admin_menu'] as $menu) {
            if ($menu['menu_type'] === 'system') {
                $this->assertArrayHasKey($menu['menu_name'], Define::MENU_SYSTEM_DEFINITION, $menu['menu_name']);
            }
        }
        $this->assertArrayHasKey('workflow_task', Define::MENU_SYSTEM_DEFINITION);
        $this->assertNotSame('menu.system_definitions.workflow_task', exmtrans('menu.system_definitions.workflow_task'));
    }
}
