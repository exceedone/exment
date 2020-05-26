<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPasswordResetFlg extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if(Schema::hasTable('login_users')){
            Schema::table('login_users', function (Blueprint $table) {
                if(!Schema::hasColumn('login_users', 'password_reset_flg')){
                    $table->boolean('password_reset_flg')->default(false)->after('login_provider');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('login_users', function (Blueprint $table) {
            if (Schema::hasColumn('login_users', 'password_reset_flg')) {
                $table->dropColumn('password_reset_flg');
            }
        });
    }
}
