<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowTable;
use Exceedone\Exment\Model\WorkflowAction;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Enums\ConditionTypeDetail;

class WorkflowService
{
    /**
     * Create a workflow for a table name.
     *
     * @param string $table_name
     * @param string $suuid
     * @param string $start_status_name
     * @param array $status_list (each item: ['status_name' => ..., 'status_type' => ..., 'order' => ..., ...])
     * @return Workflow|null
     */
    public static function createWorkflowForTable($table_name, $suuid, $start_status_name, $status_list)
    {
        return DB::transaction(function () use ($table_name, $suuid, $start_status_name, $status_list) {
            // 1. Find CustomTable
            $customTable = CustomTable::where('table_name', $table_name)->first();
            if (!$customTable) {
                return null;
            }

            // Check for existing completed workflow
            $exists = Workflow::where('suuid', $suuid)
                ->first();
            if ($exists) {
                return null;
            }

            // 2. Create Workflow
            $workflow = new Workflow();
            $workflow->suuid = $suuid;
            $workflow->workflow_type = 1; // TABLE type
            $workflow->workflow_view_name = $suuid;
            $workflow->start_status_name = $start_status_name;
            $workflow->setting_completed_flg = 0;
            $workflow->save();

            // 3. Create WorkflowStatus
            $count = count($status_list);
            $order = 1;
            foreach ($status_list as $index => $status) {
                $workflowStatus = new WorkflowStatus();
                $workflowStatus->workflow_id = $workflow->id;
                $workflowStatus->status_name = $status['status_name'];
                $workflowStatus->status_type = $status['status_type'] ?? 0;
                $workflowStatus->order = $order;
                $workflowStatus->datalock_flg = $status['datalock_flg'] ?? 0;
                $workflowStatus->completed_flg = ($index === $count - 1) ? 1 : 0;
                $workflowStatus->save();
                $order++;
            }

            // 4. Create WorkflowTable
            $workflowTable = new WorkflowTable();
            $workflowTable->workflow_id = $workflow->id;
            $workflowTable->custom_table_id = $customTable->id;
            $workflowTable->active_flg = 1;
            $workflowTable->save();
            return $workflow;
        });
    }

    /**
     * Insert actions for a workflow and set setting_completed_flg = 1
     *
     * @param Workflow $workflow
     * @param array $actions (each item: ['action_name' => ..., 'status_from' => ..., 'status_to' => ...])
     * @return bool
     */
    public static function insertActionsAndCompleteSetting($workflow, $actions)
    {
        return DB::transaction(function () use ($workflow, $actions) {
            $workflow_id = $workflow->id;
            foreach ($actions as $action) {
                // Find status_from id by text
                $statusFrom = $action['status_from'];
                $statusFromId = null;
                if (is_numeric($statusFrom)) {
                    $statusFromId = 'start';
                } else {
                    $status = \Exceedone\Exment\Model\WorkflowStatus::where('workflow_id', $workflow_id)
                        ->where('status_name', $statusFrom)->first();
                    if (!$status) return false;
                    $statusFromId = $status->id;
                }
                $workflowAction = new WorkflowAction();
                $workflowAction->workflow_id = $workflow_id;
                $workflowAction->action_name = $action['action_name'];
                $workflowAction->status_from = $statusFromId;

                // Build options array from extra fields
                $options = ['comment_type' => 'nullable', 'flow_next_type' => 'some', 'flow_next_count' => 1, 'work_target_type' => 'fix'];
                if (isset($action['comment_type'])) $options['comment_type'] = $action['comment_type'];
                if (isset($action['flow_next_type'])) $options['flow_next_type'] = $action['flow_next_type'];
                if (isset($action['flow_next_count'])) $options['flow_next_count'] = $action['flow_next_count'];
                if (isset($action['work_target_type'])) $options['work_target_type'] = $action['work_target_type'];
                $workflowAction->options = $options;

                $workflowAction->save();

                // Insert WorkflowAuthority if user_id is provided
                if (!empty($action['user_id'])) {
                    $userIds = is_array($action['user_id']) ? $action['user_id'] : [$action['user_id']];
                    foreach ($userIds as $userId) {
                        $authority = new \Exceedone\Exment\Model\WorkflowAuthority();
                        $authority->workflow_action_id = $workflowAction->id;
                        $authority->related_id = $userId;
                        $authority->related_type = 'user';
                        $authority->save();
                        $authority = new \Exceedone\Exment\Model\WorkflowAuthority();
                        $authority->workflow_action_id = $workflowAction->id;
                        $authority->related_id = 0;
                        $authority->related_type = 'system';
                        $authority->save();
                    }
                }

                // Insert WorkflowConditionHeader if work_conditions is provided
                if (!empty($action['work_conditions'])) {
                    $workConditions = is_array($action['work_conditions']) ? $action['work_conditions'] : json_decode($action['work_conditions'], true);
                    if ($workConditions && isset($workConditions['status_to_0'])) {
                        // Resolve status_to from text to id if needed
                        $statusTo = $workConditions['status_to_0'];
                        $statusToId = null;
                        if (is_numeric($statusTo)) {
                            $statusToId = $statusTo;
                        } else {
                            $statusToModel = \Exceedone\Exment\Model\WorkflowStatus::where('workflow_id', $workflow_id)
                                ->where('status_name', $statusTo)->first();
                            if (!$statusToModel) return false;
                            $statusToId = $statusToModel->id;
                        }
                        $header = new \Exceedone\Exment\Model\WorkflowConditionHeader();
                        $header->workflow_action_id = $workflowAction->id;
                        $header->enabled_flg = $workConditions['enabled_flg_0'] ?? 1;
                        $header->status_to = $statusToId;
                        $header->setOption('condition_join', $workConditions['condition_join_0'] ?? 'and');
                        $header->save();
                    }
                }
            }
            $workflow->setting_completed_flg = 1;
            $workflow->save();

            return true;
        });
    }
}
