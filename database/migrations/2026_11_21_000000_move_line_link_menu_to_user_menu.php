<?php

use Exceedone\Exment\Services\Line\LineInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LINE
     *
     * Up to v7.0.3 the page "LINE" (link your own LINE account) was a menu item at the top of the
     * side menu. From v7.1.0 it is in the user menu (top right), next to the user setting.
     * Remove the side menu item only when it is still at the top: a menu that someone moved is left as it is.
     *
     * @return void
     */
    public function up()
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($menuTable)) {
            return;
        }

        DB::table($menuTable)
            ->where('menu_type', 'custom')
            ->where('menu_name', 'line_link')
            ->where('parent_id', 0)
            ->delete();
    }

    /**
     * @return void
     */
    public function down()
    {
        if (LineInstaller::systemTemplateImported()) {
            LineInstaller::ensureLinkMenu();
        }
    }
};
