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

        $schema->create('custom_form_priority_conditions', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('custom_form_priority_id')->unsigned();
            $table->integer('form_priority_type');
            $table->integer('target_column_id')->nullable();
            $table->string('form_filter_condition_value', 256)->nullable();
            $table->timestamps();
            $table->timeusers();
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
        Schema::dropIfExists('custom_form_priorities');
        Schema::dropIfExists('custom_form_priority_conditions');
    }
}
