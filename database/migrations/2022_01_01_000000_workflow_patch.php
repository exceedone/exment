<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\View;
use Exceedone\Exment\Enums\SystemTableName;

class WorkflowPatch extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('workflows', function (Blueprint $table) {
            if (!Schema::hasColumn('workflows', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });
        
        Schema::table('workflow_values', function (Blueprint $table) {
            $table->index('created_user_id');
        });

        \ExmentDB::createView(SystemTableName::VIEW_WORKFLOW_VALUE_UNION, View\WorkflowValueView::createWorkflowValueUnionView());
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('workflows')) {
            Schema::table('workflows', function ($table) {
                if (Schema::hasColumn('workflows', 'deleted_at')) {
                    $table->dropColumn('deleted_at');
                }
            });
        }
        
        Schema::table('workflow_values', function (Blueprint $table) {
            $table->dropIndex(['created_user_id']);
        });
    }
}
