<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

use Exceedone\Exment\Model\Workflow as WorkflowModel;
use Exceedone\Exment\Model\WorkflowStatus;

/**
 * replace value
 */
class Workflow extends ItemBase
{
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    public function replace($format, $options = [])
    {
        // get workflow action and value
        $workflow_action = array_get($options, 'workflow_action');
        $workflow_value = array_get($options, 'workflow_value');

        if (!isset($workflow_action) || !isset($workflow_value)) {
            return null;
        }

        $workflow = WorkflowModel::getEloquentDefault(array_get($workflow_value, 'workflow_id'));

        $subkey = count($this->length_array) > 1 ? $this->length_array[1] : null;
        if (is_nullorempty($subkey)) {
            return null;
        }

        switch ($subkey) {
            case 'action_user':
                return $workflow_value->created_user;
            case 'action_name':
                return $workflow_action->action_name;
            case 'status_name':
                $statusTo = $workflow_action->getStatusToId($this->custom_value);
                $statusToName = esc_html(WorkflowStatus::getWorkflowStatusName($statusTo, $workflow));
                return $statusToName;
            case 'status_from_name':
                return esc_html(WorkflowStatus::getWorkflowStatusName($workflow_action->status_from, $workflow));
            case 'comment':
                return $workflow_value->comment;
        }

        return null;
    }
}
