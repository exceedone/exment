<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddViewToNotifies extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if(!Schema::hasColumn('notifies', 'custom_view_id')){
            Schema::table('notifies', function (Blueprint $table) {
                $table->integer('custom_view_id')->after('custom_table_id')->unsigned()->nullable();
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
        Schema::table('notifies', function($table) {
            $table->dropColumn('custom_view_id');
        });
    }
}
