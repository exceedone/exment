<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Layout\Content;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowAuthority;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowTable;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\FilterKind;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\WorkflowType;
use Exceedone\Exment\Enums\WorkflowTargetSystem;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Form\Tools\ConditionHasManyTable;
use Exceedone\Exment\Form\Tools;
use \Carbon\Carbon;

trait WorkflowTrait
{
    protected function getProgressInfo($workflow, $action)
    {
        $id = $workflow->id ?? null;

        $steps = [];
        $hasAction = false;
        $workflow_action_url = null;
        $workflow_status_url = null;
        if (isset($id)) {
            $hasAction = WorkflowAction::where('workflow_id', $id)->count() > 0;
            $workflow_action_url = admin_urls('workflow', $id, 'edit?action=2');
            $workflow_status_url = admin_urls('workflow', $id, 'edit');
        }
        
        $steps[] = [
            'active' => ($action == 1),
            'complete' => false,
            'url' => ($action != 1) ? $workflow_status_url: null,
            'description' => exmtrans('workflow.workflow_statuses')
        ];

        $steps[] = [
            'active' => ($action == 2),
            'complete' => false,
            'url' => ($action != 2)? $workflow_action_url: null,
            'description' => exmtrans('workflow.workflow_actions')
        ];

        if (isset($workflow) && boolval($workflow->setting_completed_flg)) {
            $steps[] = [
                'active' => ($action == 3),
                'complete' => false,
                'url' => ($action != 3) ? admin_urls("workflow", $workflow->id, "notify") : null,
                'description' => exmtrans('notify.header'),
            ];

            $steps[] = [
                'active' => ($action == 4),
                'complete' => false,
                'url' => ($action != 4) ? admin_url('workflow/beginning') : null,
                'description' => exmtrans('workflow.beginning'),
            ];
        }
        
        return $steps;
    }
}
