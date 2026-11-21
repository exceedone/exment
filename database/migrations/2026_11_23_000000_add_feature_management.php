<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feature management (Admin setting): a new feature is off until a system
     * administrator turns it on (Services\Features). Updating does not change
     * what the users of a site see:
     *
     * - a feature the site has used stays on. That is decided by its data,
     *   not by its menu (an update adds menus; a menu may be removed while its
     *   pages are still used): the file server when the site has a workspace,
     *   Studio when someone has opened its pages or the phone app (the
     *   operation log)
     * - the features the plugins had before stay on (Features::register()
     *   with "existing"): the time of this update is kept ("_since"), and a
     *   plugin installed before it keeps them
     * - the plugins keep their active flag and permissions as they are
     * - the menu of the page, at the end of "Admin Setting" (a new site gets it
     *   from the system template)
     *
     * @return void
     */
    public function up()
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($menuTable) || !Schema::hasTable('systems')) {
            return;
        }
        if (DB::table('systems')->where('system_name', 'features')->exists()) {
            $this->addMenu($menuTable);
            return;
        }

        // a new site: nothing to keep, all is new (the plugins installed later
        // come after "_since"; the template makes the menu)
        if (!DB::table($menuTable)->exists()) {
            DB::table('systems')->insert([
                'system_name' => 'features',
                'system_value' => json_encode(['_since' => now()->toIso8601String()]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return;
        }

        // a site updated: what it used stays on
        $settings = ['_since' => now()->toIso8601String()];
        $on = ['enabled' => true, 'role_groups' => null, 'menu' => true];
        if (Schema::hasTable('file_workspaces') && DB::table('file_workspaces')->exists()) {
            $settings['file_server'] = $on;
        }
        if ($this->studioUsed()) {
            $settings['studio'] = $on;
        }
        DB::table('systems')->insert([
            'system_name' => 'features',
            'system_value' => json_encode($settings),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->addMenu($menuTable);
    }

    /**
     * Whether someone has opened the pages of Studio or the phone app.
     */
    protected function studioUsed(): bool
    {
        $table = config('admin.database.operation_log_table', 'admin_operation_log');
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'path')) {
            return false;
        }
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        $prefix = $prefix === '' ? '' : $prefix . '/';
        return DB::table($table)->where(function ($query) use ($prefix) {
            foreach (['studio', 'app'] as $path) {
                $query->orWhere('path', $prefix . $path)->orWhere('path', 'like', $prefix . $path . '/%');
            }
        })->exists();
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
