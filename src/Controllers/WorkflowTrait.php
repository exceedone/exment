<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\WorkflowAction;

trait WorkflowTrait
{
    // @phpstan-ignore-next-line
    protected function getProgressInfo($workflow, $action)
    {
        $id = $workflow->id ?? null;

        $steps = [];
        $hasAction = false;
        $workflow_action_url = null;
        $workflow_status_url = null;
        if (isset($id)) {
            $hasAction = WorkflowAction::where('workflow_id', $id)->count() > 0;
            /** @phpstan-ignore-next-line */
            $workflow_action_url = admin_urls('workflow', $id, 'edit?action=2');
            /** @phpstan-ignore-next-line */
            $workflow_status_url = admin_urls('workflow', $id, 'edit');
        }

        $steps[] = [
            'active' => ($action == 1),
            'complete' => false,
            'url' => ($action != 1) ? $workflow_status_url : null,
            /** @phpstan-ignore-next-line */
            'description' => exmtrans('workflow.workflow_statuses')
        ];

        $steps[] = [
            'active' => ($action == 2),
            'complete' => false,
            'url' => ($action != 2) ? $workflow_action_url : null,
            /** @phpstan-ignore-next-line */
            'description' => exmtrans('workflow.workflow_actions')
        ];

        /** @phpstan-ignore-next-line */
        if (isset($workflow) && boolval($workflow->setting_completed_flg)) {
            $steps[] = [
                'active' => ($action == 3),
                'complete' => false,
                /** @phpstan-ignore-next-line */
                'url' => ($action != 3) ? admin_urls("workflow", $workflow->id, "notify") : null,
                /** @phpstan-ignore-next-line */
                'description' => exmtrans('notify.header'),
            ];

            $steps[] = [
                'active' => ($action == 4),
                'complete' => false,
                'url' => ($action != 4) ? admin_url('workflow/beginning') : null,
                /** @phpstan-ignore-next-line */
                'description' => exmtrans('workflow.beginning'),
            ];
        }

        return $steps;
    }
}
