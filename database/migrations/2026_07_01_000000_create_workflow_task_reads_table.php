<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Database\ExtendedBlueprint;

/**
 * Feature 1: per-user "seen" state for un-actioned workflow tasks.
 * One row = "this user has already seen the pending task of this record".
 *
 * Stored as plain integer columns, not as a composed key string, so the navbar badge can be
 * a single COUNT with a NOT EXISTS against this table instead of loading every pending
 * record into PHP just to subtract the seen ones.
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
                $table->integer('custom_table_id')->unsigned();
                // same type as workflow_values.morph_id
                $table->bigInteger('morph_id')->unsigned();
                $table->timestamps();
                $table->timeusers();

                // Also THE lookup index of the navbar badge: its filter is
                // target_user_id = ? AND custom_table_id = ? AND morph_id = <record>,
                // in this column order, so one composite index serves both jobs.
                // (explicit name: the generated one would be 66 chars, over the MySQL limit of 64)
                $table->unique(['target_user_id', 'custom_table_id', 'morph_id'], 'wf_task_reads_user_table_morph');

                // WorkflowAction::forwardWorkflowValue() deletes every user's rows for one
                // record, which cannot use the index above (it does not know target_user_id).
                $table->index(['custom_table_id', 'morph_id'], 'wf_task_reads_table_morph');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_task_reads');
    }
};
