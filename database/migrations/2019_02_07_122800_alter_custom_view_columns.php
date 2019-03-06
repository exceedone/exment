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

        // drop table name unique index from custom table
        Schema::table('custom_tables', function (Blueprint $table) {
            $table->dropUnique(['table_name']);
        });
        // add order column to custom_tables and custom_columns
        Schema::table('custom_tables', function (Blueprint $table) {
            $table->integer('order')->after('showlist_flg')->default(0);
        });
        Schema::table('custom_columns', function (Blueprint $table) {
            $table->integer('order')->after('system_flg')->default(0);
        });
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
        // add table name unique index from custom table
        Schema::table('custom_tables', function (Blueprint $table) {
            $table->unique(['table_name']);
        });
        // drop order column to custom_tables and custom_columns
        Schema::table('custom_tables', function (Blueprint $table) {
            $table->dropColumn('order');
        });
        Schema::table('custom_columns', function (Blueprint $table) {
            $table->dropColumn('order');
        });
    }
}
