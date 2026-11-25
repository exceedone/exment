<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The settings of the safety check and of the full-text search (Meilisearch) have pages of
     * their own (#2157), once on the detail page of the system settings: their menus in
     * "Admin Setting" of an installed site, right after "System Setting" (a new site gets them
     * from the system template). Who sees them: the system administrators (Auth\Permission).
     */
    public const MENUS = [
        'safety_check_setting' => 'fa-heartbeat',
        'meili_setting' => 'fa-search',
    ];

    /**
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

        // under "Admin Setting", or (a site without it) next to "System Setting"
        $admin = DB::table($menuTable)->where('menu_name', 'admin')->where('menu_type', 'parent_node')->first();
        $system = DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'system')->first();
        if (isset($admin)) {
            $parentId = intval($admin->id);
        } elseif (isset($system)) {
            $parentId = intval($system->parent_id);
        } else {
            return;
        }

        $after = 'system';
        foreach (static::MENUS as $name => $icon) {
            if (!DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', $name)->exists()) {
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
                // right after "System Setting" (only when it is under the same parent)
                \Exceedone\Exment\Model\Menu::placeSystemMenuAfter($name, $after);
            }
            $after = $name;
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
