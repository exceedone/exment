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
        \DB::createView(SystemTableName::VIEW_WORKFLOW_VALUE, View\WorkflowValueView::createWorkflowValueView());
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        \DB::dropView(SystemTableName::VIEW_WORKFLOW_VALUE);
        //
    }
}
