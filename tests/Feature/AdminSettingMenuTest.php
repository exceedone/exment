<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;

/**
 * "Login Setting" and "API Setting" are under "Admin Setting" from v7.1.0
 * (migration 2026_11_21_000001_add_login_api_setting_menus_under_admin).
 */
class AdminSettingMenuTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testUpdateAddsLoginAndApiSettingUnderAdminSetting(): void
    {
        Menu::where('menu_type', 'system')->whereIn('menu_name', ['login_setting', 'api_setting'])->delete();
        $admin = $this->adminSettingMenu();

        $this->runMigration();

        foreach (['login_setting', 'api_setting'] as $name) {
            $menu = Menu::where('menu_type', 'system')->where('menu_name', $name)->first();
            $this->assertNotNull($menu, "{$name} is added");
            $this->assertEquals($admin->id, $menu->parent_id, "{$name} is under Admin Setting");
            $this->assertEquals($name, $menu->uri);
        }
        $this->assertGreaterThan(
            Menu::where('menu_type', 'system')->where('menu_name', 'login_setting')->value('order'),
            Menu::where('menu_type', 'system')->where('menu_name', 'api_setting')->value('order')
        );
    }

    public function testUpdateDoesNotAddTwiceOrMoveAMenuSomeoneMade(): void
    {
        Menu::where('menu_type', 'system')->whereIn('menu_name', ['login_setting', 'api_setting'])->delete();
        $this->runMigration();
        $master = Menu::where('menu_type', 'parent_node')->where('menu_name', 'master')->first();
        $this->assertNotNull($master);
        $api = Menu::where('menu_type', 'system')->where('menu_name', 'api_setting')->first();
        $api->parent_id = $master->id;
        $api->save();

        $this->runMigration();

        $this->assertEquals(1, Menu::where('menu_type', 'system')->where('menu_name', 'login_setting')->count());
        $this->assertEquals(1, Menu::where('menu_type', 'system')->where('menu_name', 'api_setting')->count());
        $this->assertEquals($master->id, Menu::where('menu_type', 'system')->where('menu_name', 'api_setting')->value('parent_id'));
    }

    protected function adminSettingMenu(): Menu
    {
        $admin = Menu::where('menu_type', 'parent_node')->where('menu_name', 'admin')->first();
        $this->assertNotNull($admin, 'The test site has the menu "Admin Setting".');
        return $admin;
    }

    protected function runMigration(): void
    {
        $migration = require __DIR__ . '/../../database/migrations/2026_11_21_000001_add_login_api_setting_menus_under_admin.php';
        $migration->up();
    }
}
