<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;

class SupportForV21 extends Migration
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

        if (!\Schema::hasTable('custom_operations')) {
            $schema->create('custom_operations', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->string('suuid', 20)->index();
                $table->integer('custom_table_id')->unsigned();
                $table->string('operation_name', 40);
                $table->json('options')->nullable();
                $table->timestamps();
                $table->timeusers();
            });
        }

        if (!\Schema::hasTable('custom_operation_columns')) {
            $schema->create('custom_operation_columns', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->integer('custom_operation_id')->unsigned();
                $table->integer('view_column_type')->default(0);
                $table->integer('view_column_target_id');
                $table->string('update_value_text', 1024);
                $table->timestamps();
                $table->timeusers();

                $table->foreign('custom_operation_id')->references('id')->on('custom_operations');
            });
        }

        \Artisan::call('exment:patchdata', ['action' => 'move_plugin']);
        \Artisan::call('exment:patchdata', ['action' => 'move_template']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
        Schema::dropIfExists('custom_operation_columns');
        Schema::dropIfExists('custom_operations');
    }
}
