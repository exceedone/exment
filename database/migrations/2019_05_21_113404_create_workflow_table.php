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
            $table->string('workflow_name', 30);

            $table->timestamps();
            $table->timeusers();
        });

        $schema->create('workflow_statuses', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('workflow_id')->unsigned()->index();
            $table->string('status_name', 30);
            $table->boolean('editable_flg')->default(false);

            $table->timestamps();
            $table->timeusers();
        });

        $schema->create('workflow_actions', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('workflow_id')->unsigned()->index();
            $table->integer('status_from')->unsigned();
            $table->integer('status_to')->unsigned();
            $table->string('action_name', 30);

            $table->timestamps();
            $table->timeusers();
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
            $table->boolean('enabled_flg')->default(true);

            $table->timestamps();
            $table->timeusers();

            $table->index(['morph_type', 'morph_id']);
        });

        $schema->table('custom_tables', function (ExtendedBlueprint $table) {
            $table->integer('workflow_id')->unsigned()->nullable()->after('order');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('workflow');
        Schema::dropIfExists('workflow_statuses');
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_authorities');
        Schema::dropIfExists('workflow_values');
        Schema::table('custom_tables', function (Blueprint $table) {
            $table->dropColumn('workflow_id');
        });
    }
}
