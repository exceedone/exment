<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GitHub #2289: the first menu of a new site (7.2.1 to 7.2.x) had "通知" under
     * "設定：データと画面". It opens the old page of the notifications, which only says that
     * they moved (to each table and workflow, since v4.2.0) and asks to delete the menu.
     *
     * Removed only while it is still that item: a system menu of "notify" that opens the old
     * page and has nothing under it. A menu someone pointed somewhere else stays.
     *
     * @return void
     */
    public function up()
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($menuTable)) {
            return;
        }
        $ids = DB::table($menuTable)
            ->where('menu_type', 'system')
            ->where('menu_name', 'notify')
            ->where(function ($query) {
                $query->where('menu_target', 'notify')->orWhereNull('menu_target');
            })
            ->whereIn('uri', ['notify', '/notify'])
            ->pluck('id')
            ->all();
        foreach ($ids as $id) {
            if (DB::table($menuTable)->where('parent_id', $id)->exists()) {
                continue;
            }
            DB::table($menuTable)->where('id', $id)->delete();
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // the item is not put back (it led to a page that only says it moved)
    }
};
