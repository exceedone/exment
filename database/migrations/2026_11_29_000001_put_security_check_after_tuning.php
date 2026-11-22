<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The security check (ISS02833) right after Tuning: the first version of the
     * migration that added it put it at the end of "Admin Setting".
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable(config('admin.database.menu_table', 'admin_menu'))) {
            return;
        }
        \Exceedone\Exment\Model\Menu::placeSystemMenuAfter('security_check', 'tuning');
    }

    /**
     * @return void
     */
    public function down()
    {
    }
};
