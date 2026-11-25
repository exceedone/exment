<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * System performance log (v7.1)
     *
     * 1. The menu "System Performance Log" was made at the top of the menu (no parent). It is a log for the
     *    administrators, so move it under "Admin Setting" (menu_name "admin"), as the template does now.
     *    Only when it is still at the top: a menu that someone moved is left as it is.
     * 2. From v7.1 the log saves only slow requests (logging_slow_request_seconds / logging_slow_sql_seconds).
     *    Where the log is already on, keep saving every request, as before (both 0), until someone changes it.
     *
     * @return void
     */
    public function up()
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        if (Schema::hasTable($menuTable)) {
            $admin = DB::table($menuTable)->where('menu_name', 'admin')->where('menu_type', 'parent_node')->first();
            $logMenu = DB::table($menuTable)->where('menu_name', 'system_logs')->where('menu_type', 'table')->first();
            if (isset($admin) && isset($logMenu) && intval($logMenu->parent_id) === 0) {
                $order = intval(DB::table($menuTable)->where('parent_id', $admin->id)->max('order')) + 1;
                DB::table($menuTable)->where('id', $logMenu->id)->update(['parent_id' => $admin->id, 'order' => $order]);
            }
        }

        if (Schema::hasTable('systems')) {
            $toggle = DB::table('systems')->where('system_name', 'logging_toggle_available')->value('system_value');
            if (boolval($toggle)) {
                foreach (['logging_slow_request_seconds', 'logging_slow_sql_seconds'] as $name) {
                    if (!DB::table('systems')->where('system_name', $name)->exists()) {
                        $now = now();
                        DB::table('systems')->insert(['system_name' => $name, 'system_value' => '0', 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }
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
