<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Database\ExtendedBlueprint;

class CreateWorkflowTable extends Migration
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

        $schema->create('workflows', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->integer('custom_table_id')->unsigned();

            $table->string('workflow_name', 30);
            $table->string('start_status_name', 30);

            $table->timestamps();
            $table->timeusers();
            
            $table->foreign('custom_table_id')->references('id')->on('custom_tables');
        });

        $schema->create('workflow_statuses', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('workflow_id')->unsigned()->index();
            $table->integer('status_type')->unsigned()->index();
            $table->integer('order')->unsigned()->index();
            $table->string('status_name', 30);
            $table->boolean('datalock_flg')->default(false);

            $table->timestamps();
            $table->timeusers();
            
            $table->foreign('workflow_id')->references('id')->on('workflows');
        });

        $schema->create('workflow_actions', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('workflow_id')->unsigned()->index();
            $table->string('status_from');
            $table->string('status_to');
            $table->string('action_name', 30);
            $table->integer('action_group_id');
            $table->integer('order');
            $table->json('options')->nullable();

            $table->timestamps();
            $table->timeusers();
            
            $table->foreign('workflow_id')->references('id')->on('workflows');
        });

        $schema->create('workflow_authorities', function (ExtendedBlueprint $table) {
            $table->integer('related_id');
            $table->string('related_type', 255);
            $table->integer('workflow_action_id')->unsigned()->index();

            $table->index(['related_id', 'related_type']);
        });

        $schema->create('workflow_values', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->integer('workflow_id')->unsigned()->index();
            $table->string('morph_type', 255);
            $table->bigInteger('morph_id')->unsigned();
            $table->integer('workflow_status_id')->unsigned();
            $table->boolean('enabled_flg')->default(false)->index();

            $table->timestamps();
            $table->timeusers();

            $table->index(['morph_type', 'morph_id']);
            $table->foreign('workflow_id')->references('id')->on('workflows');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('workflow_values');
        Schema::dropIfExists('workflow_authorities');
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_statuses');
        Schema::dropIfExists('workflows');
    }
}
