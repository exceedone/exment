<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feature management (Admin setting): a new feature is off until a system
     * administrator turns it on (Services\Features).
     *
     * - the menu of the page, at the end of "Admin Setting" (a new site gets it
     *   from the system template)
     * - a site that had a feature in its menu before this update keeps it on,
     *   so that updating does not change what the users see. A menu added by
     *   a migration of this same update (a site updated from an older version)
     *   does not count: that feature stays off.
     * - the plugins keep their active flag and permissions as they are
     *
     * @return void
     */
    public function up()
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($menuTable) || !Schema::hasTable('systems')) {
            return;
        }
        // a new site: nothing to keep (the template makes the menu)
        if (!DB::table($menuTable)->exists()) {
            return;
        }

        if (!DB::table('systems')->where('system_name', 'features')->exists()) {
            $settings = [];
            // a menu made more than this before the update was there before it
            $before = now()->subMinutes(30);
            foreach (['file_server', 'studio'] as $feature) {
                $menu = DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', $feature)->first();
                if (!isset($menu)) {
                    continue;
                }
                $made = isset($menu->created_at) ? \Carbon\Carbon::parse($menu->created_at) : null;
                if (isset($made) && $made->gt($before)) {
                    continue;
                }
                $settings[$feature] = ['enabled' => true, 'role_groups' => null, 'menu' => true];
            }
            DB::table('systems')->insert([
                'system_name' => 'features',
                'system_value' => json_encode($settings, JSON_FORCE_OBJECT),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->addMenu($menuTable);
    }

    protected function addMenu(string $menuTable): void
    {
        $admin = DB::table($menuTable)->where('menu_name', 'admin')->where('menu_type', 'parent_node')->first();
        if (!isset($admin)) {
            return;
        }
        if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'feature')->exists()) {
            return;
        }
        $order = intval(DB::table($menuTable)->where('parent_id', $admin->id)->max('order')) + 1;
        DB::table($menuTable)->insert([
            'parent_id' => $admin->id,
            'order' => $order,
            'title' => exmtrans('menu.system_definitions.feature'),
            'icon' => 'fa-toggle-on',
            'uri' => 'feature',
            'menu_type' => 'system',
            'menu_name' => 'feature',
            'menu_target' => 'feature',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return void
     */
    public function down()
    {
        // the setting and the menu stay (an administrator may have changed them)
    }
};
