<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class RoleGroupsPatch221007 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('role_groups', function (Blueprint $table) {
            if (!Schema::hasColumn('role_groups', 'role_group_order')) {
                $table->integer('role_group_order')->default(0)->after('role_group_view_name');
            }
        });

        Schema::table('role_groups', function (Blueprint $table) {
            $table->index('role_group_order');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('role_groups')) {
            Schema::table('role_groups', function ($table) {
                if (Schema::hasColumn('role_groups', 'role_group_order')) {
                    $table->dropIndex(['role_group_order']);
                    $table->dropColumn('role_group_order');
                }
            });
        }
    }
}
