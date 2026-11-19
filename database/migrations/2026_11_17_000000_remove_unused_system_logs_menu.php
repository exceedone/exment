<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * System performance log
     *
     * Up to v7.0.2, `exment:update` made the table "System Performance Log" and its menu at the top of the menu
     * even when the log was off, so an empty menu showed up after updating. Now the table and the menu are made
     * only when the log is on (and the menu again when someone turns the log on).
     *
     * Remove that menu only when all of these are true:
     * - the log is off (system setting "logging_toggle_available")
     * - the log has no rows
     * - the menu is still at the top (no one moved it)
     * The table is left as it is (someone may have changed it).
     *
     * This runs before 2026_11_18_000000_move_system_logs_menu_under_admin (7.1) and
     * 2026_11_19_000000_move_system_logs_menu_under_admin_on_7_0 (7.0), which move a menu still at the top.
     *
     * @return void
     */
    public function up()
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($menuTable) || !Schema::hasTable('custom_tables') || !Schema::hasTable('systems')) {
            return;
        }

        // the log is on: keep it
        $toggle = DB::table('systems')->where('system_name', 'logging_toggle_available')->value('system_value');
        if (boolval($toggle)) {
            return;
        }

        $logMenu = DB::table($menuTable)->where('menu_name', 'system_logs')->where('menu_type', 'table')->first();
        if (!isset($logMenu) || intval($logMenu->parent_id) !== 0) {
            return;
        }

        // the log has rows: keep it
        $customTable = DB::table('custom_tables')->where('table_name', 'system_logs')->first();
        if (isset($customTable)) {
            $dbTable = 'exm__' . $customTable->suuid;
            if (Schema::hasTable($dbTable) && DB::table($dbTable)->exists()) {
                return;
            }
        }

        DB::table($menuTable)->where('id', $logMenu->id)->delete();
    }

    /**
     * @return void
     */
    public function down()
    {
        // the menu is made again when someone turns the log on
    }
};
