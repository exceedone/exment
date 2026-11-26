<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The page "Table storage conversion" (storage_conversion) had no menu: it could only be opened by its address.
     *
     * A new site gets the menu from the system template. Add it to "Admin Setting" (Settings: System), at the end,
     * when the site has that group and does not have the menu yet.
     * Who sees it: the system administrators only (Auth\Permission, "storage_conversion"), and only on a site where
     * the conversion is available (Services\MenuVisibility); the page itself refuses everyone else.
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
        if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'storage_conversion')->exists()) {
            return;
        }

        $order = intval(DB::table($menuTable)->where('parent_id', $admin->id)->max('order')) + 1;
        DB::table($menuTable)->insert([
            'parent_id' => $admin->id,
            'order' => $order,
            'title' => \Exceedone\Exment\Model\Menu::systemTitle('storage_conversion'),
            'icon' => 'fa-exchange',
            'uri' => 'storage_conversion',
            'menu_type' => 'system',
            'menu_name' => 'storage_conversion',
            'menu_target' => 'storage_conversion',
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
