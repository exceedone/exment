<?php

use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\View;
use Exceedone\Exment\Enums\SystemTableName;

class WorkflowValueView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \ExmentDB::createView(SystemTableName::VIEW_WORKFLOW_VALUE_UNION, View\WorkflowValueView::createWorkflowValueUnionView());
        \ExmentDB::createView(SystemTableName::VIEW_WORKFLOW_START, View\WorkflowStartView::createWorkflowStartView());
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        \ExmentDB::dropView(SystemTableName::VIEW_WORKFLOW_VALUE_UNION);
        \ExmentDB::dropView(SystemTableName::VIEW_WORKFLOW_START);
        //
    }
}
