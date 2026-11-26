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
        $this->assertSame(['custom_table', 'studio', 'menu', 'workflow', 'mail_template', 'notify', 'cell_style_preset', 'template'], $this->children('admin_data'));
        $this->assertSame(['loginuser', 'role_group', 'login_setting', 'api_setting', 'operation_log'], $this->children('admin_user'));
        // the safety check is added under "admin" by its installer, the storage conversion by its migration
        $this->assertSame(['system', 'feature', 'plugin', 'backup', 'tuning', 'security_check', 'scheduled_jobs', 'maintenance'], array_values(array_diff($this->children('admin'), ['safety_check', 'storage_conversion'])));
    }

    public function testTheSystemTemplateAndTheLayoutHaveTheStorageConversionMenuLast(): void
    {
        $config = json_decode(strval(file_get_contents(dirname(__DIR__, 2) . '/system_template/config.json')), true);
        $admin = array_values(array_map(function ($m) {
            return $m['menu_name'];
        }, array_filter($config['admin_menu'], function ($m) {
            return ($m['parent_name'] ?? null) === 'admin' && $m['menu_type'] === 'system';
        })));
        $this->assertSame('storage_conversion', end($admin));
        $layout = collect(\Exceedone\Exment\Services\MenuArranger::LAYOUT)->first(function ($g) {
            return $g[0] === 'admin';
        });
        $this->assertSame(['system', 'storage_conversion'], end($layout[1]));
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
