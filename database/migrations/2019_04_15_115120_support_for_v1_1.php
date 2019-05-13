<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\CurrencySymbol;

class SupportForV11 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if(!Schema::hasTable('custom_view_summaries')){
            $schema->create('custom_view_summaries', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->integer('custom_view_id')->unsigned();
                $table->integer('view_column_type')->default(0);
                $table->integer('view_column_target_id')->nullable();
                $table->integer('view_summary_condition')->unsigned()->default(0);
                $table->string('view_column_name', 40)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->timeusers();
    
                $table->foreign('custom_view_id')->references('id')->on('custom_views');
            });
        }

        if(!Schema::hasColumn('custom_view_columns', 'view_column_name')){
            $schema->table('custom_view_columns', function($table) {
                $table->string('view_column_name', 40)->nullable();
            });
        }

        if(!Schema::hasColumn('custom_view_columns', 'view_column_table_id')){
            Schema::table('custom_view_columns', function (Blueprint $table) {
                $table->integer('view_column_table_id')->after('view_column_type')->unsigned();
            });
        }

        if(!Schema::hasColumn('custom_view_summaries', 'view_column_table_id')){
            Schema::table('custom_view_summaries', function (Blueprint $table) {
                $table->integer('view_column_table_id')->after('view_column_type')->unsigned();
            });
        }

        if(!Schema::hasColumn('custom_view_filters', 'view_column_table_id')){
            Schema::table('custom_view_filters', function (Blueprint $table) {
                $table->integer('view_column_table_id')->after('view_column_type')->unsigned();
            });
        }

        if(!Schema::hasColumn('custom_view_sorts', 'view_column_table_id')){
            Schema::table('custom_view_sorts', function (Blueprint $table) {
                $table->integer('view_column_table_id')->after('view_column_type')->unsigned();
            });
        }

        if(!Schema::hasColumn('custom_copy_columns', 'from_column_table_id')){
            Schema::table('custom_copy_columns', function (Blueprint $table) {
                $table->integer('from_column_table_id')->nullable()->after('from_column_type')->unsigned();
                $table->integer('to_column_table_id')->after('to_column_type')->unsigned();
            });
        }

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

        \DB::statement('UPDATE custom_copy_columns a SET from_column_table_id = (
            CASE a.from_column_type
                WHEN 0 THEN (SELECT custom_table_id from custom_columns where id = a.from_column_target_id)
                ELSE (SELECT from_custom_table_id from custom_copies where id = a.custom_copy_id)
            END), 
            to_column_table_id = (
            CASE a.to_column_type
                WHEN 0 THEN (SELECT custom_table_id from custom_columns where id = a.to_column_target_id)
                ELSE (SELECT to_custom_table_id from custom_copies where id = a.custom_copy_id)
            END)');

        // drop table name unique index from custom table
        if(count(Schema::getUniqueListing('custom_tables', 'table_name')) > 0){
            Schema::table('custom_tables', function (Blueprint $table) {
                $table->dropUnique(['table_name']);
            });    
        }

        // add order column to custom_tables and custom_columns
        if(!Schema::hasColumn('custom_tables', 'order')){
            Schema::table('custom_tables', function (Blueprint $table) {
                $table->integer('order')->after('showlist_flg')->default(0);
            });
        }

        if(!Schema::hasColumn('custom_columns', 'order')){
            Schema::table('custom_columns', function (Blueprint $table) {
                $table->integer('order')->after('system_flg')->default(0);
            });
        }
        
        // Change Custom Column options.currency_symbol
        $columns = CustomColumn::whereNotNull('options->currency_symbol')->get();
        foreach($columns as $column){
            // update options->currency_symbol
            $symbol = CurrencySymbol::getEnum($column->getOption('currency_symbol'));
            if(!isset($symbol)){
                continue;
            }

            $column->setOption('currency_symbol', $symbol->getKey());
            $column->save();
        }
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
        
        Schema::dropIfExists('custom_view_summaries');

        Schema::table('custom_view_columns', function($table) {
            $table->dropColumn('view_column_name');
        });
        
    }
}
