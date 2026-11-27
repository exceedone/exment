<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sha256 of the file a backup made (ISS02943): a restore of that file checks it first.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('backup_runs') || Schema::hasColumn('backup_runs', 'sha256')) {
            return;
        }
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->string('sha256', 64)->nullable()->after('file_size');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('backup_runs') && Schema::hasColumn('backup_runs', 'sha256')) {
            Schema::table('backup_runs', function (Blueprint $table) {
                $table->dropColumn('sha256');
            });
        }
    }
};
