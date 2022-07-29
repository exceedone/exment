<?php

namespace Exceedone\Exment\Database\View;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Define;

class WorkflowStartView
{
    /**
     * create workflow view sql
     */
    public static function createWorkflowStartView()
    {
        /////// first query. not has workflow value's custom value
        return \DB::table(SystemTableName::WORKFLOW_TABLE)
        ->join(SystemTableName::WORKFLOW, function ($join) {
            $join->on(SystemTableName::WORKFLOW_TABLE . '.workflow_id', SystemTableName::WORKFLOW . ".id")
            ;
        })
        ->join(SystemTableName::WORKFLOW_ACTION, function ($join) {
            $join->on(SystemTableName::WORKFLOW_ACTION . '.workflow_id', SystemTableName::WORKFLOW . ".id")
                ->where(SystemTableName::WORKFLOW_ACTION . '.status_from', Define::WORKFLOW_START_KEYNAME)
            ;
        })
        ->join(SystemTableName::WORKFLOW_AUTHORITY, function ($join) {
            $join->on(SystemTableName::WORKFLOW_AUTHORITY . '.workflow_action_id', SystemTableName::WORKFLOW_ACTION . ".id")
            ;
        })
        ->where(SystemTableName::WORKFLOW_TABLE . '.active_flg', 1)
        ->distinct()
        ->select([
            'workflows.id as workflow_id',
            'workflow_tables.custom_table_id as workflow_table_id',
            'workflow_actions.id as workflow_action_id',
            'workflow_authorities.related_id as authority_related_id',
            'workflow_authorities.related_type as authority_related_type',
        ]);
    }
}
