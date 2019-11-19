<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Enums\ErrorCode;

/**
 * Api about workflow
 */
class ApiWorkflowController extends AdminControllerBase
{
    use ApiTrait;
 
    /**
     * get workflow data by id
     * @param mixed $id
     * @return mixed
     */
    public function get($id, Request $request)
    {
        $join_tables = $this->getJoinTables($request, 'workflow');

        $workflow = Workflow::getEloquent($id, $join_tables);

        if (!isset($workflow)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }
        return $workflow;
    }
    
    /**
     * get workflow status by id
     * @param mixed $id
     * @return mixed
     */
    public function status($id, Request $request)
    {
        $workflow = WorkflowStatus::getEloquent($id);

        if (!isset($workflow)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }

        return $workflow;
    }
    
    /**
     * get workflow action by id
     * @param mixed $id
     * @return mixed
     */
    public function action($id, Request $request)
    {
        $workflow = WorkflowAction::getEloquent($id);

        if (!isset($workflow)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }

        return $workflow;
    }
    
    /**
     * get workflow status list by workflow_id
     * @param mixed $id
     * @return mixed
     */
    public function workflowStatus($id, Request $request)
    {
        $workflow = WorkflowStatus::where('workflow_id', $id)->get();

        if (!isset($workflow)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }

        return $workflow;
    }
    
    /**
     * get workflow action list by workflow_id
     * @param mixed $id
     * @return mixed
     */
    public function workflowAction($id, Request $request)
    {
        $workflow = WorkflowAction::where('workflow_id', $id)->get();

        if (!isset($workflow)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }

        return $workflow;
    }

    /**
     * get workflow list
     * @param Request $request
     * @return mixed
     */
    public function getList(Request $request)
    {
        if (($count = $this->getCount($request)) instanceof Response) {
            return $count;
        }

        $query = Workflow::query();
        if(!boolval($request->get('all', false))){
            $query->where('setting_completed_flg', 1);
        }
        
        $join_tables = $this->getJoinTables($request, 'workflow');

        foreach ($join_tables as $join_table) {
            $query->with($join_table);
        }

        return $query->paginate($count ?? config('exment.api_default_data_count'));
    }
 
    /**
     * get workflow value by custom_value
     * @param mixed $tableKey
     * @param mixed $id
     * @return mixed
     */
    public function getValue($tableKey, $id, Request $request)
    {
        $custom_value = getModelName($tableKey)::find($id);
        // no custom data
        if (!isset($custom_value)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }

        $workflow_value = $custom_value->workflow_value;

        // no workflow data
        if (!isset($workflow_value)) {
            return abortJson(400, ErrorCode::WORKFLOW_NOSTART());
        }

        if ($request->has('expands')){
            $join_tables = collect(explode(',', $request->get('expands')))
                ->map(function($expand) {
                    $expand = trim($expand);
                    switch ($expand) {
                        case 'actions':
                            return 'workflow_action';
                        case 'status_from':
                            return 'workflow_status_from';
                        case 'status_to':
                            return 'workflow_status';
                    }
                })->filter()->toArray();
            $workflow_value->load($join_tables);
        }

        return $workflow_value;
    }
 
    /**
     * get workflow users by custom_value
     * @param mixed $tableKey
     * @param mixed $id
     * @return mixed
     */
    public function getWorkUsers($tableKey, $id, Request $request)
    {
        $custom_value = getModelName($tableKey)::find($id);
        // no custom data
        if (!isset($custom_value)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }

        $workflow_actions = $custom_value->getWorkflowActions(false, true);

        // no workflow users data
        if (!isset($workflow_actions) || count($workflow_actions) == 0) {
            return abortJson(400, ErrorCode::WORKFLOW_END());
        }

        $orgAsUser = boolval($request->get('as_user', false));

        $result = collect();
        foreach ($workflow_actions as $workflow_action) {
            $result = $workflow_action->getAuthorityTargets($this, $orgAsUser)->merge($result);
        }

        return $result->unique();
    }
 
    /**
     * get workflow actions by custom_value
     * @param mixed $tableKey
     * @param mixed $id
     * @return mixed
     */
    public function getActions($tableKey, $id, Request $request)
    {
        $custom_value = getModelName($tableKey)::find($id);
        // no custom data
        if (!isset($custom_value)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }

        $is_all = boolval($request->get('all', false));

        $workflow_actions = $custom_value->getWorkflowActions(!$is_all, true);

        return $workflow_actions;
    }
}
