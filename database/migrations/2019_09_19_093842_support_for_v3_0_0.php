<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Enums\WorkflowType;

class SupportForV300 extends Migration
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
            $table->integer('target_column_id')->nullable();
            $table->string('condition_value', 1024)->nullable();
            $table->timestamps();
            $table->timeusers();
            
            $table->index(['morph_type', 'morph_id']);
        });

        if (!Schema::hasColumn('admin_menu', 'options')) {
            Schema::table('admin_menu', function (Blueprint $table) {
                $table->json('options')->after('uri')->nullable();
            });
        }

        
        $schema->create('workflows', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->string('suuid', 20)->index();
            $table->integer('workflow_type')->default(WorkflowType::COMMON);

            $table->string('workflow_view_name', 30);
            $table->string('start_status_name', 30);
            $table->boolean('setting_completed_flg')->default(false);

            $table->timestamps();
            $table->timeusers();
        });

        $schema->create('workflow_tables', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('workflow_id')->unsigned()->index();
            $table->integer('custom_table_id')->unsigned()->nullable();
            $table->boolean('active_flg')->default(false);
            $table->date('active_start_date')->nullable();
            $table->date('active_end_date')->nullable();

            $table->timestamps();
            $table->timeusers();
            
            $table->foreign('workflow_id')->references('id')->on('workflows');
            $table->foreign('custom_table_id')->references('id')->on('custom_tables');
        });

        $schema->create('workflow_statuses', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('workflow_id')->unsigned()->index();
            $table->integer('status_type')->unsigned()->index()->default(0);
            $table->integer('order')->unsigned()->index();
            $table->string('status_name', 30);
            $table->boolean('datalock_flg')->default(false);
            $table->boolean('completed_flg')->default(false);

            $table->timestamps();
            $table->timeusers();
            
            $table->foreign('workflow_id')->references('id')->on('workflows');
        });

        $schema->create('workflow_actions', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('workflow_id')->unsigned()->index();
            $table->string('status_from');
            $table->string('action_name', 30);
            $table->boolean('ignore_work')->default(false)->index();
            $table->json('options')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->timeusers();
            
            $table->foreign('workflow_id')->references('id')->on('workflows');
        });

        $schema->create('workflow_condition_headers', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('workflow_action_id')->unsigned()->index();
            $table->string('status_to');
            $table->boolean('enabled_flg')->default(false);

            $table->timestamps();
            $table->timeusers();
            
            $table->foreign('workflow_action_id')->references('id')->on('workflow_actions');
        });

        $schema->create('workflow_authorities', function (ExtendedBlueprint $table) {
            $table->string('related_id');
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
            $table->integer('workflow_action_id')->unsigned()->nullable()->index();
            $table->integer('workflow_status_from_id')->unsigned()->nullable()->index();
            $table->integer('workflow_status_to_id')->unsigned()->nullable()->index();
            $table->string('comment', 1000)->nullable();
            $table->boolean('action_executed_flg')->default(false)->index();
            $table->boolean('latest_flg')->default(false)->index();

            $table->timestamps();
            $table->timeusers();

            $table->index(['morph_type', 'morph_id']);
            $table->foreign('workflow_id')->references('id')->on('workflows');
            $table->foreign('workflow_status_from_id')->references('id')->on('workflow_statuses');
            $table->foreign('workflow_status_to_id')->references('id')->on('workflow_statuses');
        });

        $schema->create('workflow_value_authorities', function (ExtendedBlueprint $table) {
            $table->string('related_id');
            $table->string('related_type', 255);
            $table->integer('workflow_value_id')->unsigned()->index();

            $table->index(['related_id', 'related_type']);
        });

        Schema::table('notifies', function ($table) {
            $table->integer('workflow_id')->unsigned()->nullable()->after('custom_table_id');
        });

        \Artisan::call('exment:patchdata', ['action' => 'workflow_mail_template']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('workflow_value_authorities');
        Schema::dropIfExists('workflow_values');
        Schema::dropIfExists('workflow_authorities');
        Schema::dropIfExists('workflow_condition_headers');
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_statuses');
        Schema::dropIfExists('workflow_tables');
        Schema::dropIfExists('workflows');

        Schema::table('admin_menu', function ($table) {
            $table->dropColumn('options');
        });

        // remove custom_form_priorities
        Schema::dropIfExists('conditions');
        Schema::dropIfExists('custom_form_priorities');
    }
}
