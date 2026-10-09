<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Database\ExtendedBlueprint;

/**
 * Feature 1: rebuild workflow_task_reads with integer columns.
 *
 * The first version of the table stored one "task_key" string ("{table}:{record}:wv{id}").
 * A string built in PHP cannot be filtered in SQL, so the navbar badge had to load every
 * pending record and subtract the seen ones in PHP. The table now stores the same identity
 * as integer columns so the badge is a COUNT with a NOT EXISTS.
 *
 * Only development databases ever got the string version (the feature is not released), so
 * the rows are simply dropped instead of converted: losing a "seen" mark only means the
 * navbar shows the task as new once more. On any other installation this is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!\Schema::hasTable('workflow_task_reads') || !\Schema::hasColumn('workflow_task_reads', 'task_key')) {
            return;
        }

        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        $schema->drop('workflow_task_reads');

        $schema->create('workflow_task_reads', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('target_user_id')->unsigned();
            $table->integer('custom_table_id')->unsigned();
            $table->bigInteger('morph_id')->unsigned();
            $table->timestamps();
            $table->timeusers();

            $table->unique(['target_user_id', 'custom_table_id', 'morph_id'], 'wf_task_reads_user_table_morph');
            $table->index(['custom_table_id', 'morph_id'], 'wf_task_reads_table_morph');
        });
    }

    public function down(): void
    {
        // nothing to roll back: the create migration owns this table
    }
};
