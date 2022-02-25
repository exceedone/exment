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

        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });


        Schema::table('custom_view_filters', function (Blueprint $table) {
            if (!Schema::hasColumn('custom_view_filters', 'suuid')) {
                $table->string('suuid', 20)->index()->after('id')->nullable();
            }
        });
        Schema::table('custom_view_sorts', function (Blueprint $table) {
            if (!Schema::hasColumn('custom_view_sorts', 'suuid')) {
                $table->string('suuid', 20)->index()->after('id')->nullable();
            }
            if (!Schema::hasColumn('custom_view_sorts', 'options')) {
                $table->json('options')->after('priority')->nullable();
            }
        });
        
        if (!Schema::hasTable('custom_view_grid_filters')) {
            $schema->create('custom_view_grid_filters', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->string('suuid', 20)->index();
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
        
        \Artisan::call('exment:patchdata', ['action' => 'patch_custom_view_summary_view_pivot']);
        \Artisan::call('exment:patchdata', ['action' => 'view_filter_suuid']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if (Schema::hasTable('custom_view_sorts')) {
            $schema->table('custom_view_sorts', function (ExtendedBlueprint $table) {
                if (Schema::hasColumn('custom_view_sorts', 'options')) {
                    $table->dropColumn('options');
                }
                if (Schema::hasColumn('custom_view_sorts', 'suuid')) {
                    $table->dropIndex(['suuid']);
                    $table->dropColumn('suuid');
                }
            });
        }
        if (Schema::hasTable('custom_view_filters')) {
            $schema->table('custom_view_filters', function (ExtendedBlueprint $table) {
                if (Schema::hasColumn('custom_view_filters', 'suuid')) {
                    $table->dropIndex(['suuid']);
                    $table->dropColumn('suuid');
                }
            });
        }

        Schema::dropIfExists('custom_view_grid_filters');
    }
}
