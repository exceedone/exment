<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * The security check right after Tuning in "Admin Setting" (ISS02833).
 */
class SecurityCheckMenuOrderTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    /**
     * The menus under the parent, read again each time.
     *
     * @phpstan-impure
     * @return array<string>
     */
    protected function children(int $parent): array
    {
        return DB::table('admin_menu')->where('parent_id', $parent)->orderBy('order')->orderBy('id')->pluck('menu_name')->map(function ($name) {
            return strval($name);
        })->all();
    }

    public function testASiteWhereItWasAddedAtTheEnd(): void
    {
        $parent = intval(DB::table('admin_menu')->insertGetId(['parent_id' => 0, 'order' => 99, 'title' => 'T', 'menu_type' => 'parent_node', 'menu_name' => 'test_admin', 'created_at' => now(), 'updated_at' => now()]));
        DB::table('admin_menu')->where('menu_type', 'system')->whereIn('menu_name', ['tuning', 'maintenance', 'security_check'])->delete();
        foreach (['backup' => 4, 'tuning' => 5, 'maintenance' => 6, 'safety_check_test' => 7, 'security_check' => 8] as $name => $order) {
            DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', $name)->delete();
            DB::table('admin_menu')->insert(['parent_id' => $parent, 'order' => $order, 'title' => $name, 'uri' => $name, 'menu_type' => 'system', 'menu_name' => $name, 'menu_target' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        (require base_path('vendor/exceedone/exment/database/migrations/2026_11_29_000001_put_security_check_after_tuning.php'))->up();
        $this->assertSame(['backup', 'tuning', 'security_check', 'maintenance', 'safety_check_test'], $this->children($parent));

        // again: nothing changes
        Menu::placeSystemMenuAfter('security_check', 'tuning');
        $this->assertSame(['backup', 'tuning', 'security_check', 'maintenance', 'safety_check_test'], $this->children($parent));
    }

    public function testMovedElsewhereByAnAdministrator(): void
    {
        $a = intval(DB::table('admin_menu')->insertGetId(['parent_id' => 0, 'order' => 98, 'title' => 'A', 'menu_type' => 'parent_node', 'menu_name' => 'test_a', 'created_at' => now(), 'updated_at' => now()]));
        $b = intval(DB::table('admin_menu')->insertGetId(['parent_id' => 0, 'order' => 99, 'title' => 'B', 'menu_type' => 'parent_node', 'menu_name' => 'test_b', 'created_at' => now(), 'updated_at' => now()]));
        DB::table('admin_menu')->where('menu_type', 'system')->whereIn('menu_name', ['tuning', 'security_check'])->delete();
        DB::table('admin_menu')->insert(['parent_id' => $a, 'order' => 1, 'title' => 't', 'uri' => 'tuning', 'menu_type' => 'system', 'menu_name' => 'tuning', 'menu_target' => 'tuning', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_menu')->insert(['parent_id' => $b, 'order' => 5, 'title' => 's', 'uri' => 'security_check', 'menu_type' => 'system', 'menu_name' => 'security_check', 'menu_target' => 'security_check', 'created_at' => now(), 'updated_at' => now()]);

        Menu::placeSystemMenuAfter('security_check', 'tuning');
        $this->assertSame(['security_check'], $this->children($b), 'left where the administrator put it');
    }
}
