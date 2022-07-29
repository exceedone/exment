<?php

namespace Exceedone\Exment\Database\View;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\WorkflowAction;

class WorkflowValueView
{
    /**
     * create workflow view sql
     */
    public static function createWorkflowValueUnionView()
    {
        // get sub query for first executed workflow value's id.
        $subqueryFirstWorkflowValueId = \DB::table(SystemTableName::WORKFLOW_VALUE)
            ->whereNull('workflow_status_from_id')
            ->groupBy(['morph_id', 'morph_type'])
            ->select('morph_id', 'morph_type', \DB::raw('max(' . SystemTableName::WORKFLOW_VALUE . '.id) AS workflow_value_first_id'))
        ;

        // get sub query for executed workflow value's id.
        $subqueryLastWorkflowValueId = \DB::table(SystemTableName::WORKFLOW_VALUE)
            ->where('action_executed_flg', 0)
            ->groupBy(['morph_id', 'morph_type'])
            ->select('morph_id', 'morph_type', \DB::raw('max(' . SystemTableName::WORKFLOW_VALUE . '.id) AS workflow_value_last_id'))
        ;

        $subquery2 = \DB::table(SystemTableName::WORKFLOW_TABLE)
        ->join(SystemTableName::WORKFLOW, function ($join) {
            $join->on(SystemTableName::WORKFLOW_TABLE . '.workflow_id', SystemTableName::WORKFLOW . ".id")
            ;
        })
        ->join(SystemTableName::CUSTOM_TABLE, function ($join) {
            $join->on(SystemTableName::WORKFLOW_TABLE . '.custom_table_id', SystemTableName::CUSTOM_TABLE . ".id")
            ;
        })
        ->join(SystemTableName::WORKFLOW_VALUE, function ($join) {
            $join->on(SystemTableName::WORKFLOW_VALUE . '.morph_type', SystemTableName::CUSTOM_TABLE . ".table_name")
                ->on(SystemTableName::WORKFLOW_VALUE . '.workflow_id', SystemTableName::WORKFLOW . ".id")
            ;
        })
        ->join(SystemTableName::WORKFLOW_ACTION, function ($join) {
            $join
            ->on(SystemTableName::WORKFLOW_ACTION . '.workflow_id', SystemTableName::WORKFLOW . ".id")
            ->where('ignore_work', 0)
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where(SystemTableName::WORKFLOW_ACTION . '.status_from', Define::WORKFLOW_START_KEYNAME)
                        ->whereNull(SystemTableName::WORKFLOW_VALUE . '.workflow_status_to_id')
                    ;
                })->orWhere(function ($query) {
                    WorkflowAction::appendStatusFromJoinQuery($query);
                });
            });
        })
        ->join(SystemTableName::WORKFLOW_AUTHORITY, function ($join) {
            $join->on(SystemTableName::WORKFLOW_AUTHORITY . '.workflow_action_id', SystemTableName::WORKFLOW_ACTION . ".id")
            ;
        })
        // join for first executed user id
        ->joinSub($subqueryFirstWorkflowValueId, 'workflow_values_first_group', function ($join) {
            $join->on('workflow_values_first_group.morph_id', '=', SystemTableName::WORKFLOW_VALUE . '.morph_id')
                ->on('workflow_values_first_group.morph_type', '=', SystemTableName::WORKFLOW_VALUE . '.morph_type')
            ;
        })
        ->join(SystemTableName::WORKFLOW_VALUE . ' AS workflow_values_first', function ($join) {
            $join->on('workflow_values_first.id', "workflow_values_first_group.workflow_value_first_id")
            ;
        })
        // join for last executed user id
        ->joinSub($subqueryLastWorkflowValueId, 'workflow_values_last_group', function ($join) {
            $join->on('workflow_values_last_group.morph_id', '=', SystemTableName::WORKFLOW_VALUE . '.morph_id')
                ->on('workflow_values_last_group.morph_type', '=', SystemTableName::WORKFLOW_VALUE . '.morph_type')
            ;
        })
        ->join(SystemTableName::WORKFLOW_VALUE . ' AS workflow_values_last', function ($join) {
            $join->on('workflow_values_last.id', "workflow_values_last_group.workflow_value_last_id")
            ;
        })
        ->where(SystemTableName::WORKFLOW_VALUE . '.latest_flg', 1)
        ->where(SystemTableName::WORKFLOW_TABLE . '.active_flg', 1)
        ->distinct()
        ->select([
            'workflow_values.id as workflow_value_id',
            'workflows.id as workflow_id',
            'workflow_tables.custom_table_id as workflow_table_id',
            'workflow_values.morph_id as custom_value_id',
            'workflow_values.morph_type as custom_value_type',
            'workflow_actions.id as workflow_action_id',
            'workflow_authorities.related_id as authority_related_id',
            'workflow_authorities.related_type as authority_related_type',
            'workflow_values_last.created_user_id as last_executed_user_id',
            'workflow_values_first.created_user_id as first_executed_user_id',
        ]);


        /////// third query. has workflow value's custom value and workflow value authorities

        $subquery3 = \DB::table(SystemTableName::WORKFLOW_TABLE)
        ->join(SystemTableName::WORKFLOW, function ($join) {
            $join->on(SystemTableName::WORKFLOW_TABLE . '.workflow_id', SystemTableName::WORKFLOW . ".id")
            ;
        })
        ->join(SystemTableName::CUSTOM_TABLE, function ($join) {
            $join->on(SystemTableName::WORKFLOW_TABLE . '.custom_table_id', SystemTableName::CUSTOM_TABLE . ".id")
            ;
        })
        ->join(SystemTableName::WORKFLOW_VALUE, function ($join) {
            $join->on(SystemTableName::WORKFLOW_VALUE . '.morph_type', SystemTableName::CUSTOM_TABLE . ".table_name")
                ->on(SystemTableName::WORKFLOW_VALUE . '.workflow_id', SystemTableName::WORKFLOW . ".id")
            ;
        })
        ->join(SystemTableName::WORKFLOW_ACTION, function ($join) {
            $join
            ->on(SystemTableName::WORKFLOW_ACTION . '.workflow_id', SystemTableName::WORKFLOW . ".id")
            ->where('ignore_work', 0)
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where(SystemTableName::WORKFLOW_ACTION . '.status_from', Define::WORKFLOW_START_KEYNAME)
                        ->whereNull(SystemTableName::WORKFLOW_VALUE . '.workflow_status_to_id')
                    ;
                })->orWhere(function ($query) {
                    WorkflowAction::appendStatusFromJoinQuery($query);
                });
            });
        })
        ->join(SystemTableName::WORKFLOW_VALUE_AUTHORITY, function ($join) {
            $join->on(SystemTableName::WORKFLOW_VALUE_AUTHORITY . '.workflow_value_id', SystemTableName::WORKFLOW_VALUE . ".id")
            ;
        })
        // join for first executed user id
        ->joinSub($subqueryFirstWorkflowValueId, 'workflow_values_first_group', function ($join) {
            $join->on('workflow_values_first_group.morph_id', '=', SystemTableName::WORKFLOW_VALUE . '.morph_id')
                ->on('workflow_values_first_group.morph_type', '=', SystemTableName::WORKFLOW_VALUE . '.morph_type')
            ;
        })
        ->join(SystemTableName::WORKFLOW_VALUE . ' AS workflow_values_first', function ($join) {
            $join->on('workflow_values_first.id', "workflow_values_first_group.workflow_value_first_id")
            ;
        })
        // join for last executed user id
        ->joinSub($subqueryLastWorkflowValueId, 'workflow_values_last_group', function ($join) {
            $join->on('workflow_values_last_group.morph_id', '=', SystemTableName::WORKFLOW_VALUE . '.morph_id')
                ->on('workflow_values_last_group.morph_type', '=', SystemTableName::WORKFLOW_VALUE . '.morph_type')
            ;
        })
        ->join(SystemTableName::WORKFLOW_VALUE . ' AS workflow_values_last', function ($join) {
            $join->on('workflow_values_last.id', "workflow_values_last_group.workflow_value_last_id")
            ;
        })
        ->where(SystemTableName::WORKFLOW_VALUE . '.latest_flg', 1)
        ->where(SystemTableName::WORKFLOW_TABLE . '.active_flg', 1)
        ->distinct()
        ->select([
            'workflow_values.id as workflow_value_id',
            'workflows.id as workflow_id',
            'workflow_tables.custom_table_id as workflow_table_id',
            'workflow_values.morph_id as custom_value_id',
            'workflow_values.morph_type as custom_value_type',
            'workflow_actions.id as workflow_action_id',
            'workflow_value_authorities.related_id as authority_related_id',
            'workflow_value_authorities.related_type as authority_related_type',
            'workflow_values_last.created_user_id as last_executed_user_id',
            'workflow_values_first.created_user_id as first_executed_user_id',
        ]);


        $subquery3->union($subquery2);

        return $subquery3;
    }
}
