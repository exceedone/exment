<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature 1: "delete" on the task list takes the task off the user's own list.
 *
 * The row of a task is a real record of a real table, shared with everybody who works on it,
 * so the button must not delete it - it only stores "this user took this task off the list".
 * That is per user and per record, exactly what a row of workflow_task_reads already is, so it
 * is one more flag on that row rather than a table of its own:
 *  - no row          : unread
 *  - hidden_flg = 0  : read
 *  - hidden_flg = 1  : taken off the list (and read)
 * WorkflowAction::forwardWorkflowValue() deletes every row of a record whenever an action is
 * executed on it (a status change, or one more approval of a multi-approver step), so the task
 * is shown again, as unread, from then on.
 *
 * The lookups keep using the unique (target_user_id, custom_table_id, morph_id) index: at most
 * one row per user and record, and the flag is read from that row, so it needs no index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('workflow_task_reads') || Schema::hasColumn('workflow_task_reads', 'hidden_flg')) {
            return;
        }

        Schema::table('workflow_task_reads', function (Blueprint $table) {
            $table->boolean('hidden_flg')->default(false)->after('morph_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('workflow_task_reads') || !Schema::hasColumn('workflow_task_reads', 'hidden_flg')) {
            return;
        }

        Schema::table('workflow_task_reads', function (Blueprint $table) {
            $table->dropColumn('hidden_flg');
        });
    }
};
