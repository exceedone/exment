<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Services\MenuArranger;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\DB;

/**
 * "Arrange in the standard order" (Services\MenuArranger, ISS02819): also a
 * site with other menus among the admin settings (Exment SaaS) gets the menu
 * of a new site; the other menus stay, and "Undo" puts everything back.
 */
class MenuArrangerTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;
    use OldAdminMenuTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        // a site of 7.1: one "Admin Setting" with a menu of its own in the middle, "Manage Master" third, "HOME"
        $this->makeOldAdminMenu();
        DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', 'master')->update(['order' => 3, 'title' => 'マスター管理']);
        DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'home')->update(['title' => 'HOME']);
        DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', 'admin')->update(['title' => '管理者設定']);
        $admin = DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', 'admin')->value('id');
        DB::table('admin_menu')->insert([
            'parent_id' => $admin, 'order' => 4, 'title' => 'テナント設定', 'icon' => 'fa-building', 'uri' => 'tenant-settings',
            'menu_type' => 'custom', 'menu_name' => 'tenant-settings', 'menu_target' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function snapshot(): array
    {
        return DB::table('admin_menu')->orderBy('id')->get(['id', 'parent_id', 'order', 'title'])->map(function ($row) {
            return (array) $row;
        })->all();
    }

    /**
     * @return string[]
     */
    protected function children(string $parent): array
    {
        $id = DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', $parent)->value('id');
        $this->assertNotNull($id, $parent);
        return DB::table('admin_menu')->where('parent_id', $id)->orderBy('order')->orderBy('id')->pluck('menu_name')->map(function ($name) {
            return strval($name);
        })->all();
    }

    public function testASiteWithOtherMenusGetsTheStandardOrder(): void
    {
        $arranger = new MenuArranger();
        $this->assertNotEmpty($arranger->plan());
        $arranger->arrange();

        $this->assertSame(['custom_table', 'studio', 'menu', 'workflow', 'mail_template', 'template'], array_values(array_intersect($this->children('admin_data'), ['custom_table', 'studio', 'menu', 'workflow', 'mail_template', 'notify', 'cell_style_preset', 'template'])));
        $this->assertSame(['loginuser', 'role_group', 'login_setting', 'api_setting'], array_values(array_intersect($this->children('admin_user'), ['loginuser', 'role_group', 'login_setting', 'api_setting', 'operation_log'])));
        $system = $this->children('admin');
        $this->assertSame('system', $system[0]);
        $this->assertContains('tenant-settings', $system, 'a menu of the site stays in "Settings: System"');
        $this->assertSame(90, intval(DB::table('admin_menu')->where('menu_name', 'master')->value('order')));
        $this->assertSame(['file_server'], $this->children('groupware'));
        $this->assertSame(trans('exment::exment.menu.arrange.title_home', [], \Exceedone\Exment\Model\Menu::siteLocale()), DB::table('admin_menu')->where('menu_name', 'home')->value('title'));

        // arranged: nothing more to do
        $this->assertSame([], (new MenuArranger())->plan());
    }

    public function testUndoPutsTheMenuBack(): void
    {
        $before = $this->snapshot();
        $arranger = new MenuArranger();
        $arranger->arrange();
        $this->assertTrue($arranger->canRestore());

        $this->assertTrue($arranger->restore());
        $this->assertEquals($before, $this->snapshot(), 'the same rows, parents, orders and titles; the groups it made are gone');
        $this->assertFalse((new MenuArranger())->canRestore());
    }

    public function testATitleAnAdministratorGaveStays(): void
    {
        DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', 'master')->update(['title' => 'Our masters']);
        (new MenuArranger())->arrange();
        $this->assertSame('Our masters', DB::table('admin_menu')->where('menu_name', 'master')->value('title'));
    }
}
