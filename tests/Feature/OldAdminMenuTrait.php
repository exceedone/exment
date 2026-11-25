<?php

namespace Exceedone\Exment\Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * The menu of a site installed before the menu was put in groups (ISS02819):
 * everything of the settings under one "Admin Setting" (menu_name admin), and
 * the file server at the top. A new site (system_template) has the groups
 * admin_data, admin_user and groupware, so the tests of the migrations made
 * for the older sites first turn the menu back into that shape.
 */
trait OldAdminMenuTrait
{
    protected function makeOldAdminMenu(): void
    {
        $table = 'admin_menu';
        $admin = DB::table($table)->where('menu_type', 'parent_node')->where('menu_name', 'admin')->first();
        if (!isset($admin)) {
            return;
        }
        foreach (['admin_data', 'admin_user'] as $group) {
            $parent = DB::table($table)->where('menu_type', 'parent_node')->where('menu_name', $group)->first();
            if (!isset($parent)) {
                continue;
            }
            foreach (DB::table($table)->where('parent_id', $parent->id)->orderBy('order')->get() as $row) {
                $order = intval(DB::table($table)->where('parent_id', $admin->id)->max('order')) + 1;
                DB::table($table)->where('id', $row->id)->update(['parent_id' => $admin->id, 'order' => $order]);
            }
            DB::table($table)->where('id', $parent->id)->delete();
        }
        // the menus a site of 7.1 did not have on its first menu
        DB::table($table)->whereIn('menu_name', ['notify', 'cell_style_preset', 'operation_log', 'trash'])->where('menu_type', 'system')->delete();

        $groupware = DB::table($table)->where('menu_type', 'parent_node')->where('menu_name', 'groupware')->first();
        if (isset($groupware)) {
            DB::table($table)->where('parent_id', $groupware->id)->update(['parent_id' => $groupware->parent_id, 'order' => $groupware->order]);
            DB::table($table)->where('id', $groupware->id)->delete();
        }
    }
}
