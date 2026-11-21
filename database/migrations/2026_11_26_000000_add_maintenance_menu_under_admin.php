<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maintenance mode (ISS01396).
     *
     * A new site gets the menu from the system template. Add it at the end of
     * "Admin Setting" when the site has that menu and does not have it yet.
     * Who sees it: the system administrators (Auth\Permission, "maintenance"),
     * and only when the feature is used (Features, "maintenance_mode").
     *
     * @return void
     */
    public function up()
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($menuTable)) {
            return;
        }

        $admin = DB::table($menuTable)->where('menu_name', 'admin')->where('menu_type', 'parent_node')->first();
        if (!isset($admin)) {
            return;
        }
        if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'maintenance')->exists()) {
            return;
        }

        $order = intval(DB::table($menuTable)->where('parent_id', $admin->id)->max('order')) + 1;
        DB::table($menuTable)->insert([
            'parent_id' => $admin->id,
            'order' => $order,
            'title' => exmtrans('menu.system_definitions.maintenance'),
            'icon' => 'fa-wrench',
            'uri' => 'maintenance',
            'menu_type' => 'system',
            'menu_name' => 'maintenance',
            'menu_target' => 'maintenance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return void
     */
    public function down()
    {
        // the menu is not removed (someone may have moved or renamed it)
    }
};
