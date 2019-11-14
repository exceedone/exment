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

        //TODO:api no data
        if (!isset($workflow)) {
            return abort(400);
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

        //TODO:api no data
        if (!isset($workflow)) {
            return abort(400);
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

        //TODO:api no data
        if (!isset($workflow)) {
            return abort(400);
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
            return abort(400);
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
            return abort(400);
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
        if ($request->has('all') && boolval($request->get('all'))) {
            $workflow = Workflow::query();
        } else {
            $workflow = Workflow::where('setting_completed_flg', 1);
        }

        $join_tables = $this->getJoinTables($request, 'workflow');

        foreach ($join_tables as $join_table) {
            $workflow = $workflow->with($join_table);
        }

        $workflow = $workflow->get();
        if (!isset($workflow)) {
            return abort(400);
        }
        return $workflow;
    }
}
