<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The menu of the file server is called "ファイルサーバー" / "File server", as
 * its pages, Feature management and the manual call it. A site installed with
 * an early 7.2 got "文書管理" / "Documents" from the template: that title goes
 * back, only while it is still exactly the one the template gave (a title an
 * administrator wrote stays).
 */
return new class extends Migration
{
    public const TITLES = [
        '文書管理' => 'ファイルサーバー',
        'Documents' => 'File server',
    ];

    /**
     * @return void
     */
    public function up()
    {
        $table = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($table)) {
            return;
        }
        foreach (static::TITLES as $from => $to) {
            DB::table($table)
                ->where('menu_type', 'system')
                ->where('menu_name', 'file_server')
                ->where('title', $from)
                ->update(['title' => $to, 'updated_at' => now()]);
        }
    }

    /**
     * @return void
     */
    public function down()
    {
    }
};
