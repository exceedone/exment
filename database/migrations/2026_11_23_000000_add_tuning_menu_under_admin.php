<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tuning (ISS00414): what would make the site faster.
     *
     * A new site gets the menu from the system template. Add it at the end of
     * "Admin Setting" when the site has that menu and does not have Tuning yet.
     * Who sees it is decided as for every system menu (Auth\Permission, "tuning").
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
        if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'tuning')->exists()) {
            return;
        }

        $order = intval(DB::table($menuTable)->where('parent_id', $admin->id)->max('order')) + 1;
        DB::table($menuTable)->insert([
            'parent_id' => $admin->id,
            'order' => $order,
            'title' => exmtrans('menu.system_definitions.tuning'),
            'icon' => 'fa-tachometer',
            'uri' => 'tuning',
            'menu_type' => 'system',
            'menu_name' => 'tuning',
            'menu_target' => 'tuning',
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
