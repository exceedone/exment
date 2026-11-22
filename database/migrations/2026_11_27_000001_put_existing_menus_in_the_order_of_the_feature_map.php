<?php

use Exceedone\Exment\Model\Menu;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The left menu of an existing site in the order of the feature map
 * (exment.net/feature-map/, ISS02819), as a new site has it:
 *
 * - "Manage Master" (menu_name master) goes after the groups (order 90), so
 *   the groups the templates and plugins add (Requests, Projects, IT
 *   support...) come before it. Only when it is still at the top level with
 *   the order the template of 7.0 to 7.2 gave it (3).
 * - The file server (the documents) goes into the group "Groupware", only when
 *   it is still at the top level.
 *
 * A menu an administrator moved stays where it is. Nothing is added except
 * the group "Groupware" for the file server.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        $table = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($table)) {
            return;
        }

        $master = DB::table($table)->where('menu_type', 'parent_node')->where('menu_name', 'master')->first();
        if (isset($master) && intval($master->parent_id) === 0 && intval($master->order) === 3) {
            DB::table($table)->where('id', $master->id)->update(['order' => 90, 'updated_at' => now()]);
        }

        $fileServer = DB::table($table)->where('menu_type', 'system')->where('menu_name', 'file_server')->first();
        if (isset($fileServer) && intval($fileServer->parent_id) === 0) {
            $group = Menu::groupParentId('groupware');
            if (isset($group)) {
                $order = intval(DB::table($table)->where('parent_id', $group)->max('order')) + 1;
                DB::table($table)->where('id', $fileServer->id)->update(['parent_id' => $group, 'order' => $order, 'updated_at' => now()]);
            }
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // the menus stay where they are
    }
};
