<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * System performance log (7.0)
     *
     * `exment:update` makes the table "System Performance Log", and its menu was made at the top of the menu
     * (no parent). It is a log for the administrators, so move it under "Admin Setting" (menu_name "admin"),
     * as the template does now. Only when it is still at the top: a menu that someone moved is left as it is.
     *
     * The name is not the one of the 7.1 migration (2026_11_18_000000_move_system_logs_menu_under_admin),
     * so that the 7.1 one still runs on a site updated from 7.0.
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
        $logMenu = DB::table($menuTable)->where('menu_name', 'system_logs')->where('menu_type', 'table')->first();
        if (isset($admin) && isset($logMenu) && intval($logMenu->parent_id) === 0) {
            $order = intval(DB::table($menuTable)->where('parent_id', $admin->id)->max('order')) + 1;
            DB::table($menuTable)->where('id', $logMenu->id)->update(['parent_id' => $admin->id, 'order' => $order]);
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
