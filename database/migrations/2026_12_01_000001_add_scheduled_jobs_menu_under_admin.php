<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scheduled jobs (ISS02832): what php artisan schedule:run does, and when.
     *
     * A new site gets the menu from the system template. Add it to "Admin Setting",
     * right after the security check, when the site has that menu and does not have it yet.
     * Who sees it: the system administrators (Auth\Permission, "scheduled_jobs"),
     * and only when the feature is used (Features, "scheduled_jobs").
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
        if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'scheduled_jobs')->exists()) {
            return;
        }

        $order = intval(DB::table($menuTable)->where('parent_id', $admin->id)->max('order')) + 1;
        DB::table($menuTable)->insert([
            'parent_id' => $admin->id,
            'order' => $order,
            'title' => \Exceedone\Exment\Model\Menu::systemTitle('scheduled_jobs'),
            'icon' => 'fa-clock-o',
            'uri' => 'scheduled_jobs',
            'menu_type' => 'system',
            'menu_name' => 'scheduled_jobs',
            'menu_target' => 'scheduled_jobs',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // right after the security check (or Tuning, on a site without it there)
        \Exceedone\Exment\Model\Menu::placeSystemMenuAfter('scheduled_jobs', 'security_check');
        if (!DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'security_check')->where('parent_id', $admin->id)->exists()) {
            \Exceedone\Exment\Model\Menu::placeSystemMenuAfter('scheduled_jobs', 'tuning');
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
