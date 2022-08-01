<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\WorkflowCommentType;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Enums\WorkflowGetAuthorityType;
use Validator;

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

        if ($workflow instanceof Workflow) {
            if (in_array('workflow_statuses', $join_tables)) {
                return $workflow->appendStartStatus();
            }

            return $workflow;
        }

        return abortJson(400, ErrorCode::DATA_NOT_FOUND());
    }

    /**
     * get workflow status by id
     * @param mixed $id
     * @return mixed
     */
    public function status($id, Request $request)
    {
        $workflow = WorkflowStatus::getEloquent($id);

        if ($workflow instanceof WorkflowStatus) {
            return $workflow;
        }
        return abortJson(400, ErrorCode::DATA_NOT_FOUND());
    }

    /**
     * get workflow action by id
     * @param mixed $id
     * @return mixed
     */
    public function action($id, Request $request)
    {
        $workflow = WorkflowAction::getEloquent($id);

        if ($workflow instanceof WorkflowAction) {
            return $workflow;
        }
        return abortJson(400, ErrorCode::DATA_NOT_FOUND());
    }

    /**
     * get workflow status list by workflow_id
     * @param mixed $id
     * @return mixed
     */
    public function workflowStatus($id, Request $request)
    {
        $workflow = Workflow::getEloquent($id, ['workflow_statuses']);

        if (!isset($workflow)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }

        $workflow = $workflow->appendStartStatus();

        return $workflow->workflow_statuses;
    }

    /**
     * get workflow action list by workflow_id
     * @param mixed $id
     * @return mixed
     */
    public function workflowAction($id, Request $request)
    {
        $workflow = Workflow::getEloquent($id, ['workflow_actions']);

        if (!isset($workflow)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }

        return $workflow->workflow_actions;
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

        // filterd by id
        if ($request->has('id')) {
            $ids = explode(',', $request->get('id'));
            $query->whereIn('id', $ids);
        } elseif (!boolval($request->get('all', false))) {
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
        if (is_null($custom_table = CustomTable::getEloquent($tableKey))) {
            return abortJson(400, ErrorCode::INVALID_PARAMS());
        }

        if (($code = $custom_table->enableAccess()) !== true) {
            return abortJson(403, trans('admin.deny'), $code);
        }

        if (($custom_value = $this->getCustomValue($custom_table, $id)) instanceof Response) {
            return $custom_value;
        }
        $workflow_value = $custom_value->workflow_value;

        // no workflow data
        if (!isset($workflow_value)) {
            return abortJson(400, ErrorCode::WORKFLOW_NOSTART());
        }

        $join_tables = $this->getJoinTables($request, 'workflow');
        if (!is_nullorempty($join_tables)) {
            $workflow_value->load($join_tables);
        }

        $result = $workflow_value->toArray();
        if (in_array('workflow_status_from', $join_tables) && is_null($workflow_value->workflow_status_from_id)) {
            $result['workflow_status_from'] = WorkflowStatus::getWorkflowStartStatus($workflow_value->workflow_cache);
        }

        if (in_array('workflow_status_to', $join_tables) && is_null($workflow_value->workflow_status_to_id)) {
            $result['workflow_status_to'] = WorkflowStatus::getWorkflowStartStatus($workflow_value->workflow_cache);
        }

        return $result;
    }

    /**
     * get workflow users by custom_value
     * @param mixed $tableKey
     * @param mixed $id
     * @return mixed
     */
    public function getWorkUsers($tableKey, $id, Request $request)
    {
        if (is_null($custom_table = CustomTable::getEloquent($tableKey))) {
            return abortJson(400, ErrorCode::INVALID_PARAMS());
        }

        if (($code = $custom_table->enableAccess()) !== true) {
            return abortJson(403, trans('admin.deny'), $code);
        }

        if (($custom_value = $this->getCustomValue($custom_table, $id)) instanceof Response) {
            return $custom_value;
        }

        // check if workflow is completed
        if ($custom_value->isWorkflowCompleted()) {
            return abortJson(400, ErrorCode::WORKFLOW_END());
        }

        $orgAsUser = boolval($request->get('as_user', false));
        $is_all = boolval($request->get('all', false));

        $workflow_actions = $custom_value->getWorkflowActions(false, !$is_all);

        $result = collect();
        foreach ($workflow_actions as $workflow_action) {
            $result = \Exment::uniqueCustomValues($result, $workflow_action->getAuthorityTargets(
                $custom_value,
                WorkflowGetAuthorityType::CURRENT_WORK_USER,
                [
                    'orgAsUser' => $orgAsUser
                ]
            ));
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
        if (is_null($custom_table = CustomTable::getEloquent($tableKey))) {
            return abortJson(400, ErrorCode::INVALID_PARAMS());
        }

        if (($code = $custom_table->enableAccess()) !== true) {
            return abortJson(403, trans('admin.deny'), $code);
        }

        if (($custom_value = $this->getCustomValue($custom_table, $id)) instanceof Response) {
            return $custom_value;
        }

        // check if workflow is completed
        if ($custom_value->isWorkflowCompleted()) {
            return abortJson(400, ErrorCode::WORKFLOW_END());
        }

        $is_all = boolval($request->get('all', false));

        $workflow_actions = $custom_value->getWorkflowActions(!$is_all);

        return $workflow_actions;
    }

    /**
     * get workflow histories by custom_value
     * @param mixed $tableKey
     * @param mixed $id
     * @return mixed
     */
    public function getHistories($tableKey, $id, Request $request)
    {
        if (is_null($custom_table = CustomTable::getEloquent($tableKey))) {
            return abortJson(400, ErrorCode::INVALID_PARAMS());
        }

        if (($code = $custom_table->enableAccess()) !== true) {
            return abortJson(403, trans('admin.deny'), $code);
        }

        if (($custom_value = $this->getCustomValue($custom_table, $id)) instanceof Response) {
            return $custom_value;
        }

        $workflow_histories = $custom_value->getWorkflowHistories(false);

        return $workflow_histories;
    }

    /**
     * execute workflow process
     * @param mixed $tableKey
     * @param mixed $id
     * @return mixed
     */
    public function execute($tableKey, $id, Request $request)
    {
        // check workflow_action_id is required
        $validator = Validator::make($request->all(), [
            'workflow_action_id' => 'required',
        ]);
        if ($validator->fails()) {
            return abortJson(400, [
                'errors' => $this->getErrorMessages($validator)
            ], ErrorCode::VALIDATION_ERROR());
        }

        if (is_null($custom_table = CustomTable::getEloquent($tableKey))) {
            return abortJson(400, ErrorCode::INVALID_PARAMS());
        }

        if (($code = $custom_table->enableAccess()) !== true) {
            return abortJson(403, trans('admin.deny'), $code);
        }

        if (($custom_value = $this->getCustomValue($custom_table, $id)) instanceof Response) {
            return $custom_value;
        }

        // get and filter workflow action
        $workflow_action_id = $request->get('workflow_action_id');
        $workflow_action = $custom_value->getWorkflowActions(true, false)->filter(function ($value) use ($workflow_action_id) {
            return $value->id == $workflow_action_id;
        })->first();

        // workflow action not found or no authority
        if (!isset($workflow_action)) {
            return abortJson(400, ErrorCode::WORKFLOW_ACTION_DISABLED());
        }

        // check options required
        $rules = [];
        if ($workflow_action->comment_type == WorkflowCommentType::REQUIRED) {
            $rules['comment'] = 'required';
        }
        $statusTo = $workflow_action->getStatusToId($custom_value);
        $currentTo = isset($custom_value->workflow_value) ? $custom_value->workflow_value->workflow_status_to_id : null;

        $next_get_by_userinfo = null;
        if ($currentTo != $statusTo) {
            $nextActions = WorkflowStatus::getActionsByFrom($statusTo, $workflow_action->workflow);
            $need_next = $nextActions->contains(function ($workflow_action) {
                return $workflow_action->getOption('work_target_type') == WorkflowWorkTargetType::ACTION_SELECT;
            });
            $next_get_by_userinfo = $nextActions->first(function ($workflow_action) {
                return $workflow_action->getOption('work_target_type') == WorkflowWorkTargetType::GET_BY_USERINFO;
            });
            if ($need_next) {
                $rules['next_users'] = 'required_without:next_organizations';
                $rules['next_organizations'] = 'required_without:next_users';
            }
        }
        if (!empty($rules)) {
            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return abortJson(400, [
                    'errors' => $this->getErrorMessages($validator)
                ], ErrorCode::VALIDATION_ERROR());
            }
        }

        if (($params = $this->getExecuteParams($request)) instanceof Response) {
            return $params;
        }

        // If has $next_get_by_userinfo, set get_by_userinfo_action
        if (!is_nullorempty($next_get_by_userinfo)) {
            // if WorkflowWorkTargetType::GET_BY_USERINFO, check has next user. If not has, throw error.
            $nextUserAndOrgs = $next_get_by_userinfo->getAuthorityTargets($custom_value, WorkflowGetAuthorityType::EXEXCUTE);
            if (is_nullorempty($nextUserAndOrgs) && $next_get_by_userinfo->isActionNext($custom_value)) {
                return abortJson(400, ErrorCode::WORKFLOW_NOT_HAS_NEXT_USER());
            }

            $params['get_by_userinfo_action'] = $next_get_by_userinfo->id;
        }

        // execute workflow action
        $workflow_value = $workflow_action->executeAction($custom_value, $params);

        return $workflow_value;
    }

    /**
     * create execute workflow params by request
     */
    protected function getExecuteParams(Request $request)
    {
        $params = [];
        $next_work_users = [];
        $errors = [];

        if ($request->has('comment')) {
            $params['comment'] = $request->get('comment');
        }

        if ($request->has('next_users')) {
            $next_users = explode(',', $request->get('next_users'));
            foreach ($next_users as $next_user) {
                if (getModelName(SystemTableName::USER)::where('id', $next_user)->exists()) {
                    $next_work_users[] = "user_$next_user";
                } else {
                    $errors[] = exmtrans('api.errors.invalid_user', $next_user);
                }
            }
        }

        if ($request->has('next_organizations')) {
            $next_organizations = explode(',', $request->get('next_organizations'));
            foreach ($next_organizations as $next_organization) {
                if (getModelName(SystemTableName::ORGANIZATION)::where('id', $next_organization)->exists()) {
                    $next_work_users[] = "organization_$next_organization";
                } else {
                    $errors[] = exmtrans('api.errors.invalid_organization', $next_organization);
                }
            }
        }

        if (!empty($errors)) {
            return abortJson(400, [
                'errors' => $errors
            ], ErrorCode::VALIDATION_ERROR());
        }

        if (!empty($next_work_users)) {
            $params['next_work_users'] = $next_work_users;
        }

        return $params;
    }
}
