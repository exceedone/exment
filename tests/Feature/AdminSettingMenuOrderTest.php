<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\DB;

/**
 * The order of the menus of the admin settings (7.2.0): a site updated keeps
 * the order its administrator made, and takes the order of a new site when
 * it still has an order an Exment made.
 */
class AdminSettingMenuOrderTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function migration(): mixed
    {
        return require base_path('vendor/exceedone/exment/database/migrations/2026_11_25_000000_reorder_admin_setting_menus.php');
    }

    protected function adminId(): int
    {
        return intval(DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', 'admin')->value('id'));
    }

    /**
     * Put the children of "Admin Setting" in this order (the rows that are
     * there), with an unknown one (made by the site) in the middle.
     *
     * @param string[] $names
     */
    protected function arrange(array $names): void
    {
        $admin = $this->adminId();
        $rows = DB::table('admin_menu')->where('parent_id', $admin)->get();
        $order = 1;
        foreach ($names as $name) {
            $row = $rows->firstWhere('menu_name', $name);
            if (isset($row)) {
                DB::table('admin_menu')->where('id', $row->id)->update(['order' => $order++]);
            }
        }
        // the others (not named): at the end
        foreach ($rows as $row) {
            if (!in_array($row->menu_name, $names, true)) {
                DB::table('admin_menu')->where('id', $row->id)->update(['order' => $order++]);
            }
        }
    }

    /**
     * @return string[]
     */
    protected function names(): array
    {
        return DB::table('admin_menu')->where('parent_id', $this->adminId())->orderBy('order')->orderBy('id')->pluck('menu_name')->map(function ($name) {
            return strval($name);
        })->all();
    }

    public function testAnUpdatedSiteTakesTheNewOrder(): void
    {
        // a site of 7.1.2 updated: the old template, then what the updates added
        DB::table('admin_menu')->insert([
            'parent_id' => $this->adminId(), 'order' => 99, 'title' => 'My page', 'icon' => 'fa-star',
            'uri' => 'data/information', 'menu_type' => 'custom', 'menu_name' => 'my_page', 'menu_target' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->arrange(['system', 'custom_table', 'menu', 'template', 'backup', 'loginuser', 'role_group', 'workflow', 'plugin', 'studio', 'my_page', 'mail_template', 'login_setting', 'api_setting', 'feature', 'tuning']);
        $this->migration()->up();
        $names = $this->names();
        $known = array_values(array_filter($names, function ($name) {
            return $name !== 'my_page';
        }));
        $expected = array_values(array_filter(
            ['system', 'login_setting', 'api_setting', 'custom_table', 'studio', 'menu', 'workflow', 'mail_template', 'loginuser', 'role_group', 'feature', 'plugin', 'template', 'backup', 'tuning'],
            function ($name) use ($names) {
                return in_array($name, $names, true);
            }
        ));
        $this->assertSame($expected, array_slice($known, 0, count($expected)));
        // the menu of the site comes after the known ones
        $this->assertGreaterThan(array_search('tuning', $names, true) ?: array_search('backup', $names, true), array_search('my_page', $names, true));

        // a second run changes nothing
        $this->migration()->up();
        $this->assertSame($names, $this->names());
    }

    public function testAnOrderAnAdministratorMadeStays(): void
    {
        $this->arrange(['menu', 'system', 'custom_table', 'template', 'backup', 'loginuser', 'role_group', 'workflow', 'plugin', 'mail_template']);
        $before = $this->names();
        $this->migration()->up();
        $this->assertSame($before, $this->names());
        // the titles and the parents are not touched
        $this->assertSame(1, DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', 'admin')->count());
    }
}
