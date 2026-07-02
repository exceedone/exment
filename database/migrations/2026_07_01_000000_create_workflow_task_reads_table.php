<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Database\ExtendedBlueprint;

/**
 * Feature 1: per-user "seen" state for un-actioned workflow tasks.
 * A task is identified by a task_key (see WorkflowTaskService::taskKey).
 * The navbar badge counts pending tasks whose task_key is NOT in this table
 * for the current user (mirrors notify_navbars.read_flg for a computed list).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if (!\Schema::hasTable('workflow_task_reads')) {
            $schema->create('workflow_task_reads', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->integer('target_user_id')->unsigned();
                $table->string('task_key', 100);
                $table->timestamps();
                $table->timeusers();

                $table->unique(['target_user_id', 'task_key']);
                $table->index('target_user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_task_reads');
    }
};
