<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Login Setting and API Setting
     *
     * Up to v7.0.3 these two screens were not in the menu: they were opened from a button of the
     * system setting page, so they were hard to find. From v7.1.0 they are under "Admin Setting".
     * Add them at the end of "Admin Setting" when the site has that menu and does not have them yet.
     * Who sees them is decided as for every system menu: "Login Setting" for system administrators,
     * "API Setting" only when the API is on.
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

        $icons = ['login_setting' => 'fa-sign-in', 'api_setting' => 'fa-code-fork'];
        foreach ($icons as $name => $icon) {
            if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', $name)->exists()) {
                continue;
            }
            $order = intval(DB::table($menuTable)->where('parent_id', $admin->id)->max('order')) + 1;
            DB::table($menuTable)->insert([
                'parent_id' => $admin->id,
                'order' => $order,
                'title' => \Exceedone\Exment\Model\Menu::systemTitle($name),
                'icon' => $icon,
                'uri' => $name,
                'menu_type' => 'system',
                'menu_name' => $name,
                'menu_target' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // the menus are not removed (someone may have moved or renamed them)
    }
};
