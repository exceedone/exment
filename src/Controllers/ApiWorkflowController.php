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
        if (($count = $this->getCount($request)) instanceof Response) {
            return $count;
        }

        $query = Workflow::query();
        if(!boolval($request->get('all', false))){
            $query->where('setting_completed_flg', 1);
        }
        
        if ($request->has('expands')){
            $expands = explode(',', $request->get('expands'));
            $this->setExpandTables($expands, $query);
        }

        return $query->paginate($count ?? config('exment.api_default_data_count'));
    }
    
    /**
     * set expand tables to query builder
     * @param array $expands
     * @param Builder $workflow
     */
    protected function setExpandTables($expands, &$query) {
        foreach($expands as $expand) {
            switch (trim($expand)) {
                case 'tables':
                    $query->with('workflow_tables');
                    break;
                case 'statuses':
                    $query->with('workflow_statuses');
                    break;
                case 'actions':
                    $query->with('workflow_actions');
                    break;
            }
        }
    }
}
