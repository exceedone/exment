<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterCustomViewColumns extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('custom_view_columns', function (Blueprint $table) {
            $table->integer('view_column_table_id')->after('view_column_type')->unsigned();
        });
        Schema::table('custom_view_summaries', function (Blueprint $table) {
            $table->integer('view_column_table_id')->after('view_column_type')->unsigned();
        });
        Schema::table('custom_view_filters', function (Blueprint $table) {
            $table->integer('view_column_table_id')->after('view_column_type')->unsigned();
        });
        Schema::table('custom_view_sorts', function (Blueprint $table) {
            $table->integer('view_column_table_id')->after('view_column_type')->unsigned();
        });
        // set default value.
        \DB::statement('UPDATE custom_view_columns a SET view_column_table_id = (
            CASE a.view_column_type
                WHEN 0 THEN (SELECT custom_table_id from custom_columns where id = a.view_column_target_id)
                ELSE (SELECT custom_table_id from custom_views where id = a.custom_view_id)
            END)');
        \DB::statement('UPDATE custom_view_summaries a SET view_column_table_id = (
            CASE a.view_column_type
                WHEN 0 THEN (SELECT custom_table_id from custom_columns where id = a.view_column_target_id)
                ELSE (SELECT custom_table_id from custom_views where id = a.custom_view_id)
            END)');
        \DB::statement('UPDATE custom_view_filters a SET view_column_table_id = (
            CASE a.view_column_type
                WHEN 0 THEN (SELECT custom_table_id from custom_columns where id = a.view_column_target_id)
                ELSE (SELECT custom_table_id from custom_views where id = a.custom_view_id)
            END)');
        \DB::statement('UPDATE custom_view_sorts a SET view_column_table_id = (
            CASE a.view_column_type
                WHEN 0 THEN (SELECT custom_table_id from custom_columns where id = a.view_column_target_id)
                ELSE (SELECT custom_table_id from custom_views where id = a.custom_view_id)
            END)');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('custom_view_columns', function (Blueprint $table) {
            $table->dropColumn('view_column_table_id');
        });
        Schema::table('custom_view_summaries', function (Blueprint $table) {
            $table->dropColumn('view_column_table_id');
        });
        Schema::table('custom_view_filters', function (Blueprint $table) {
            $table->dropColumn('view_column_table_id');
        });
        Schema::table('custom_view_sorts', function (Blueprint $table) {
            $table->dropColumn('view_column_table_id');
        });
    }
}
