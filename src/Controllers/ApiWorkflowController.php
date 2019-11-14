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
        $workflow = Workflow::where('id', $id);

        if ($request->has('expands')){
            $expands = explode(',', $request->get('expands'));
            $this->setExpandTables($expands, $workflow);
        }

        $workflow = $workflow->first();

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
            $workflow = Workflow::whereRaw('1 = 1');
        } else {
            $workflow = Workflow::where('setting_completed_flg', 1);
        }

        if ($request->has('expands')){
            $expands = explode(',', $request->get('expands'));
            $this->setExpandTables($expands, $workflow);
        }

        $workflow = $workflow->get();
        if (!isset($workflow)) {
            return abort(400);
        }
        return $workflow;
    }
    
    /**
     * set expand tables to query builder
     * @param array $expands
     * @param Builder $workflow
     */
    protected function setExpandTables($expands, &$workflow) {
        foreach($expands as $expand) {
            switch (trim($expand)) {
                case 'tables':
                    $workflow = $workflow->with('workflow_tables');
                    break;
                case 'statuses':
                    $workflow = $workflow->with('workflow_statuses');
                    break;
                case 'actions':
                    $workflow = $workflow->with('workflow_actions');
                    break;
            }
        }
    }
}
