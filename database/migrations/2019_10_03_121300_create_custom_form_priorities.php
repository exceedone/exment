<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;

class CreateCustomFormPriorities extends Migration
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

        $schema->create('custom_form_priorities', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('custom_form_id')->unsigned();
            $table->integer('order')->unsigned()->default(0);
            $table->timestamps();
            $table->timeusers();
        });

        $schema->create('conditions', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('morph_type');
            $table->integer('morph_id')->unsigned();
            $table->integer('condition_type');
            $table->integer('condition_key');
            $table->integer('target_table_id')->nullable();
            $table->integer('target_column_id')->nullable();
            $table->json('condition_value')->nullable();
            $table->timestamps();
            $table->timeusers();
            
            $table->index(['morph_type', 'morph_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // remove custom_form_priorities
        Schema::dropIfExists('conditions');
        Schema::dropIfExists('custom_form_priorities');
    }
}
