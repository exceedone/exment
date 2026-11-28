<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The insights (ISS02964): its menu in "Admin Setting" of an installed site, right after
     * the log management (a new site gets it from the system template). Who sees it: the system
     * administrators and Audit (Auth\Permission, "insights").
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
        if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'insights')->exists()) {
            return;
        }

        $order = intval(DB::table($menuTable)->where('parent_id', $admin->id)->max('order')) + 1;
        DB::table($menuTable)->insert([
            'parent_id' => $admin->id,
            'order' => $order,
            'title' => \Exceedone\Exment\Model\Menu::systemTitle('insights'),
            'icon' => 'fa-line-chart',
            'uri' => 'insights',
            'menu_type' => 'system',
            'menu_name' => 'insights',
            'menu_target' => 'insights',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // right after the log management (or the scheduled jobs)
        foreach (['log_management', 'scheduled_jobs'] as $after) {
            if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', $after)->where('parent_id', $admin->id)->exists()) {
                \Exceedone\Exment\Model\Menu::placeSystemMenuAfter('insights', $after);
                break;
            }
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // the menu is not removed (someone may have moved or renamed it)
    }
};
