<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Services\Line\LineInstaller;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The menu migrations of v7.1.0, run one after another as `exment:update` does on a site
 * updated from v7.0.3: the menus end up where v7.1.0 puts them, and running them again changes nothing.
 */
class V710MenuMigrationsTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected const MIGRATIONS = [
        '2026_11_20_000000_move_safety_check_menu_under_admin',
        '2026_11_21_000000_move_line_link_menu_to_user_menu',
        '2026_11_21_000001_add_login_api_setting_menus_under_admin',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testASiteFromV703GetsTheV710Menus(): void
    {
        $admin = Menu::where('menu_type', 'parent_node')->where('menu_name', 'admin')->first();
        $this->assertNotNull($admin);
        $this->makeTheMenusOfV703();

        $this->runMigrations();

        $this->assertEquals($admin->id, Menu::where('menu_name', 'safety_check')->value('parent_id'), 'safety check under Admin Setting');
        $this->assertFalse(Menu::where('menu_name', 'line_link')->exists(), 'LINE is in the user menu, not the side menu');
        foreach (['login_setting', 'api_setting'] as $name) {
            $this->assertEquals($admin->id, Menu::where('menu_type', 'system')->where('menu_name', $name)->value('parent_id'), "{$name} under Admin Setting");
        }
    }

    public function testRunningTheMigrationsAgainChangesNothing(): void
    {
        $this->makeTheMenusOfV703();
        $this->runMigrations();
        $before = Menu::orderBy('id')->get(['id', 'parent_id', 'order', 'menu_name'])->toArray();

        $this->runMigrations();

        $this->assertEquals($before, Menu::orderBy('id')->get(['id', 'parent_id', 'order', 'menu_name'])->toArray());
    }

    /**
     * v7.0.3: safety check and LINE at the top of the side menu, no login / API setting menu.
     */
    protected function makeTheMenusOfV703(): void
    {
        Menu::where('menu_type', 'system')->whereIn('menu_name', ['login_setting', 'api_setting'])->delete();
        SafetyCheckInstaller::removeMenu();
        LineInstaller::removeLinkMenu();
        SafetyCheckInstaller::ensureAll();
        $safety = Menu::where('menu_name', 'safety_check')->first();
        $safety->parent_id = 0;
        $safety->order = 99;
        $safety->save();
        LineInstaller::ensureLinkMenu();
    }

    protected function runMigrations(): void
    {
        foreach (static::MIGRATIONS as $name) {
            $migration = require __DIR__ . '/../../database/migrations/' . $name . '.php';
            $migration->up();
        }
    }
}
