<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The data sync (ISS00113): the page "Data sync".
     *
     * A new site gets the menu from the system template. Add it to "Admin Setting", right after
     * the scheduled jobs, when the site has that menu and does not have it yet. Who sees it: the
     * system administrators (Auth\Permission, "sync"), and only when the feature is used
     * (Features, "data_sync": hidden while it is off).
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
        if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'sync')->exists()) {
            return;
        }

        $order = intval(DB::table($menuTable)->where('parent_id', $admin->id)->max('order')) + 1;
        DB::table($menuTable)->insert([
            'parent_id' => $admin->id,
            'order' => $order,
            'title' => \Exceedone\Exment\Model\Menu::systemTitle('sync'),
            'icon' => 'fa-refresh',
            'uri' => 'sync',
            'menu_type' => 'system',
            'menu_name' => 'sync',
            'menu_target' => 'sync',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // right after the scheduled jobs
        \Exceedone\Exment\Model\Menu::placeSystemMenuAfter('sync', 'scheduled_jobs');
    }

    /**
     * @return void
     */
    public function down()
    {
        // the menu is not removed (someone may have moved or renamed it)
    }
};
