<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The menus of the admin settings the updates of 7.2 add under "Admin
     * Setting" (the parent node "admin"), with their icons.
     */
    public const MENUS = [
        'login_setting' => 'fa-sign-in',
        'api_setting' => 'fa-code-fork',
        'studio' => 'fa-th-large',
        'feature' => 'fa-toggle-on',
        'tuning' => 'fa-tachometer',
    ];

    /**
     * A site without the parent node "admin" (an administrator removed or
     * renamed it) did not get the menus of 7.2: they are added next to the
     * menu "System Setting" (under its parent), or at the top of the menu.
     * A site with the parent node got them from the migrations before.
     *
     * @return void
     */
    public function up()
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($menuTable)) {
            return;
        }
        // a site that is not installed yet gets the menus from the template
        if (!DB::table($menuTable)->exists()) {
            return;
        }
        if (DB::table($menuTable)->where('menu_name', 'admin')->where('menu_type', 'parent_node')->exists()) {
            return;
        }

        $system = DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'system')->first();
        $parentId = isset($system) ? intval($system->parent_id) : 0;
        foreach (static::MENUS as $name => $icon) {
            if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', $name)->exists()) {
                continue;
            }
            $order = intval(DB::table($menuTable)->where('parent_id', $parentId)->max('order')) + 1;
            DB::table($menuTable)->insert([
                'parent_id' => $parentId,
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
