<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class DropUniqueKeys extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //
        Schema::table('plugins', function (Blueprint $table) {
            $table->dropUnique(['plugin_name']);
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['role_name']);
        });
        Schema::table('dashboards', function (Blueprint $table) {
            $table->dropUnique(['dashboard_name']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (\Schema::hasTable('dashboards')) {
            Schema::table('dashboards', function (Blueprint $table) {
                $table->unique(['dashboard_name']);
            });
        }
        if (\Schema::hasTable('roles')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->unique(['role_name']);
            });
        }
        if (\Schema::hasTable('plugins')) {
            Schema::table('plugins', function (Blueprint $table) {
                $table->unique(['plugin_name']);
            });
        }
    }
}
