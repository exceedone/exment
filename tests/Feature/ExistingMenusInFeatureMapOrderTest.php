<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\DB;

/**
 * 2026_11_27_000001_put_existing_menus_in_the_order_of_the_feature_map
 * (ISS02819): on an existing site "Manage Master" goes after the groups and
 * the file server into "Groupware", only where an Exment put them.
 */
class ExistingMenusInFeatureMapOrderTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;
    use OldAdminMenuTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        // a site of 7.0 to 7.2: one "Admin Setting", the file server at the top, "Manage Master" third
        $this->makeOldAdminMenu();
        DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', 'master')->update(['order' => 3]);
    }

    protected function runMigration(): void
    {
        $migration = require base_path('vendor/exceedone/exment/database/migrations/2026_11_27_000001_put_existing_menus_in_the_order_of_the_feature_map.php');
        $migration->up();
    }

    protected function row(string $type, string $name): ?\stdClass
    {
        return DB::table('admin_menu')->where('menu_type', $type)->where('menu_name', $name)->first();
    }

    public function testMasterAndTheFileServerTakeTheirPlaces(): void
    {
        $this->assertSame(0, intval($this->row('system', 'file_server')->parent_id), 'the old site has the file server at the top');
        $this->runMigration();

        $this->assertSame(90, intval($this->row('parent_node', 'master')->order));
        $groupware = $this->row('parent_node', 'groupware');
        $this->assertNotNull($groupware);
        $this->assertSame(intval($groupware->id), intval($this->row('system', 'file_server')->parent_id));

        // running again changes nothing
        $before = DB::table('admin_menu')->orderBy('id')->get(['id', 'parent_id', 'order'])->toArray();
        $this->runMigration();
        $this->assertEquals($before, DB::table('admin_menu')->orderBy('id')->get(['id', 'parent_id', 'order'])->toArray());
    }

    public function testWhatAnAdministratorMovedStays(): void
    {
        DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', 'master')->update(['order' => 7]);
        $master = $this->row('parent_node', 'master');
        DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'file_server')->update(['parent_id' => $master->id]);

        $this->runMigration();

        $this->assertSame(7, intval($this->row('parent_node', 'master')->order));
        $this->assertSame(intval($master->id), intval($this->row('system', 'file_server')->parent_id));
        $this->assertNull($this->row('parent_node', 'groupware'), 'no group is made when nothing goes in it');
    }
}
