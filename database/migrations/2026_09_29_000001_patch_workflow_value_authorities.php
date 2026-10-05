<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Records that were already waiting for more approvals when this version came in, at a step whose
 * users were picked when the record got there (実行時に選択): their newest workflow value carries no
 * picks, so the work user view does not see the approvers who have not acted yet. The same copy
 * WorkflowAction::executeAction() now makes on every approval is made for them once.
 *
 * Nothing to undo: the copies are the users the record page already reads for those records.
 */
return new class extends Migration
{
    public function up(): void
    {
        \Artisan::call('exment:patchdata', ['action' => 'workflow_value_authorities']);
    }

    public function down(): void
    {
    }
};
