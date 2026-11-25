<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Safety check
     *
     * Up to v7.0.3, the menu "Safety Check" (menu_name "safety_check") was made at the top of the menu (no parent).
     * Only system administrators use it, so move it under "Admin Setting" (menu_name "admin"),
     * where SafetyCheckInstaller makes it now. Only when it is still at the top: a menu that someone moved is left as it is.
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
        $menu = DB::table($menuTable)->where('menu_name', 'safety_check')->where('menu_type', 'custom')->first();
        if (isset($admin) && isset($menu) && intval($menu->parent_id) === 0) {
            $order = intval(DB::table($menuTable)->where('parent_id', $admin->id)->max('order')) + 1;
            DB::table($menuTable)->where('id', $menu->id)->update(['parent_id' => $admin->id, 'order' => $order]);
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // the menu is not moved back (someone may have moved it after the update)
    }
};
