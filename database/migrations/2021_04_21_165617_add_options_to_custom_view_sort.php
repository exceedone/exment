<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Exceedone\Exment\Database\ExtendedBlueprint;
use Illuminate\Support\Facades\Schema;

class AddOptionsToCustomViewSort extends Migration
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


        Schema::table('custom_view_sorts', function (Blueprint $table) {
            if (!Schema::hasColumn('custom_view_sorts', 'options')) {
                $table->json('options')->after('priority')->nullable();
            }
        });
        
        $schema->create('custom_view_grid_filters', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('custom_view_id')->unsigned();
            $table->integer('view_column_type')->default(0);
            $table->integer('view_column_table_id')->unsigned();
            $table->integer('view_column_target_id')->nullable();
            $table->integer('order')->unsigned()->default(0);
            $table->json('options')->nullable();
            $table->timestamps();
            $table->timeusers();

            $table->foreign('custom_view_id')->references('id')->on('custom_views');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('custom_view_sorts', function (Blueprint $table) {
            if(Schema::hasColumn('custom_view_sorts', 'options')){
                $table->dropColumn('options');
            }
        });
    }
}
