<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\DB;

/**
 * 2026_11_27_000000_group_admin_setting_menus (ISS02819): a site whose admin
 * settings are still as an Exment made them gets the three groups of a new
 * site; an order an administrator made, a site that has the groups, a title
 * an administrator gave and the menus of plugins stay.
 */
class GroupAdminSettingMenusTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;
    use OldAdminMenuTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function run2611(string $name): void
    {
        $migration = require base_path("vendor/exceedone/exment/database/migrations/$name.php");
        $migration->up();
    }

    /**
     * A site of 7.1 or 7.2 updated: one "Admin Setting", put in the order of 7.2 by its update.
     */
    protected function makeUpdatedSite(): void
    {
        $this->makeOldAdminMenu();
        // the order of a site of 7.1 (its template, then what the updates added at the end)
        $admin = $this->parentId('admin');
        // what a site or its plugins added comes after the menus of Exment
        DB::table('admin_menu')->where('parent_id', $admin)->update(['order' => DB::raw('`order` + 1000')]);
        $order = 1;
        foreach (['system', 'custom_table', 'menu', 'template', 'backup', 'loginuser', 'role_group', 'workflow', 'plugin', 'mail_template', 'login_setting', 'api_setting', 'studio', 'feature', 'tuning', 'maintenance', 'safety_check'] as $name) {
            DB::table('admin_menu')->where('parent_id', $admin)->where('menu_name', $name)->update(['order' => $order++]);
        }
        $this->run2611('2026_11_25_000000_reorder_admin_setting_menus');
    }

    protected function parentId(string $name): ?int
    {
        $id = DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', $name)->value('id');
        return isset($id) ? intval($id) : null;
    }

    /**
     * @return string[]
     */
    protected function children(string $parent): array
    {
        return DB::table('admin_menu')->where('parent_id', $this->parentId($parent))->orderBy('order')->orderBy('id')->pluck('menu_name')->map(function ($name) {
            return strval($name);
        })->all();
    }

    public function testAnUpdatedSiteGetsTheGroups(): void
    {
        $this->makeUpdatedSite();
        DB::table('admin_menu')->insert([
            'parent_id' => $this->parentId('admin'), 'order' => 99, 'title' => 'My plugin', 'icon' => 'fa-star',
            'uri' => 'plugins/my', 'menu_type' => 'plugin', 'menu_name' => 'my_plugin', 'menu_target' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->run2611('2026_11_27_000000_group_admin_setting_menus');

        $this->assertSame(['custom_table', 'studio', 'menu', 'workflow', 'mail_template', 'template'], $this->children('admin_data'));
        $this->assertSame(['loginuser', 'role_group', 'login_setting', 'api_setting'], $this->children('admin_user'));
        $system = $this->children('admin');
        $kept = ['system', 'feature', 'plugin', 'backup', 'tuning', 'maintenance'];
        // the ones the site has (maintenance is there when its feature was turned on), in this order and first
        $this->assertSame(array_values(array_intersect($kept, $system)), array_slice($system, 0, count(array_intersect($kept, $system))));
        $this->assertContains('system', $system);
        $this->assertSame('my_plugin', end($system), 'a plugin menu stays in "Admin Setting", at the end');

        // the groups come right before "Admin Setting"
        $roots = DB::table('admin_menu')->where('parent_id', 0)->orderBy('order')->orderBy('id')->pluck('menu_name')->map(function ($name) {
            return strval($name);
        })->all();
        $at = array_search('admin', $roots, true);
        $this->assertSame(['admin_data', 'admin_user', 'admin'], array_slice($roots, $at - 2, 3));

        $site = Menu::siteLocale();
        $this->assertSame(trans('exment::exment.menu.system_definitions.admin_system', [], $site), DB::table('admin_menu')->where('id', $this->parentId('admin'))->value('title'));
        $this->assertSame(trans('exment::exment.menu.system_definitions.admin_data', [], $site), DB::table('admin_menu')->where('id', $this->parentId('admin_data'))->value('title'));
    }

    public function testAnOrderAnAdministratorMadeStays(): void
    {
        $this->makeUpdatedSite();
        $admin = $this->parentId('admin');
        // the administrator put the login users first
        DB::table('admin_menu')->where('parent_id', $admin)->where('menu_name', 'loginuser')->update(['order' => 0]);
        $before = DB::table('admin_menu')->orderBy('id')->get(['id', 'parent_id', 'order', 'title'])->toArray();

        $this->run2611('2026_11_27_000000_group_admin_setting_menus');

        $this->assertNull($this->parentId('admin_data'));
        $this->assertEquals($before, DB::table('admin_menu')->orderBy('id')->get(['id', 'parent_id', 'order', 'title'])->toArray());
    }

    public function testATitleAnAdministratorGaveStays(): void
    {
        $this->makeUpdatedSite();
        DB::table('admin_menu')->where('id', $this->parentId('admin'))->update(['title' => 'Our settings']);
        $this->run2611('2026_11_27_000000_group_admin_setting_menus');
        $this->assertNotNull($this->parentId('admin_data'));
        $this->assertSame('Our settings', DB::table('admin_menu')->where('id', $this->parentId('admin'))->value('title'));
    }

    public function testANewSiteDoesNotChange(): void
    {
        // a new site (system_template) has the groups already
        $before = DB::table('admin_menu')->orderBy('id')->get(['id', 'parent_id', 'order', 'title'])->toArray();
        $this->run2611('2026_11_27_000000_group_admin_setting_menus');
        $this->assertEquals($before, DB::table('admin_menu')->orderBy('id')->get(['id', 'parent_id', 'order', 'title'])->toArray());
    }

    public function testRunningAgainChangesNothing(): void
    {
        $this->makeUpdatedSite();
        $this->run2611('2026_11_27_000000_group_admin_setting_menus');
        $before = DB::table('admin_menu')->orderBy('id')->get(['id', 'parent_id', 'order', 'title'])->toArray();
        $this->run2611('2026_11_27_000000_group_admin_setting_menus');
        $this->assertEquals($before, DB::table('admin_menu')->orderBy('id')->get(['id', 'parent_id', 'order', 'title'])->toArray());
    }
}
