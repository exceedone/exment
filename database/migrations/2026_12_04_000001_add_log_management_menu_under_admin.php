<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The log management (ISS02839): its menu in "Admin Setting" of an installed site, right after
     * the scheduled jobs (a new site gets it from the system template). Who sees it: the system
     * administrators and Audit (Auth\Permission, "log_management").
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
        if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'log_management')->exists()) {
            return;
        }

        $order = intval(DB::table($menuTable)->where('parent_id', $admin->id)->max('order')) + 1;
        DB::table($menuTable)->insert([
            'parent_id' => $admin->id,
            'order' => $order,
            'title' => \Exceedone\Exment\Model\Menu::systemTitle('log_management'),
            'icon' => 'fa-archive',
            'uri' => 'log_management',
            'menu_type' => 'system',
            'menu_name' => 'log_management',
            'menu_target' => 'log_management',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // right after the scheduled jobs (or the security check, or Tuning)
        foreach (['scheduled_jobs', 'security_check', 'tuning'] as $after) {
            if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', $after)->where('parent_id', $admin->id)->exists()) {
                \Exceedone\Exment\Model\Menu::placeSystemMenuAfter('log_management', $after);
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
