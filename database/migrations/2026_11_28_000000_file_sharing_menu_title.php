<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The file server is called "ファイル共有" / "File sharing" for users
 * (snoro, 2026-11-23). Its menu made by Feature management before got the
 * name of then, "ファイルサーバー" / "File Server", and the template of 7.2
 * gave "文書管理" / "Documents": these go to the new name, only while the title is
 * still exactly one of them (a title an administrator wrote stays). The URL
 * (/file_server) and the menu_name do not change.
 */
return new class extends Migration
{
    public const TITLES = [
        'ファイルサーバー' => 'ファイル共有',
        '文書管理' => 'ファイル共有',
        'File Server' => 'File sharing',
        'File server' => 'File sharing',
        'Documents' => 'File sharing',
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
