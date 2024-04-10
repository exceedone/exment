<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\WorkflowGetAuthorityType;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Enums\WorkflowCommentType;
use Exceedone\Exment\Enums\WorkflowNextType;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\ConditionItems\ConditionItemBase;
use Symfony\Component\HttpFoundation\Response;

/**
 * @phpstan-consistent-constructor
 * @property mixed $workflow
 * @property mixed $workflow_id
 * @property mixed $workflow_condition_headers
 * @property mixed $workflow_authorities
 * @property mixed $status_from
 * @property mixed $action_name
 * @property mixed $ignore_work
 */
class WorkflowAction extends ModelBase
{
    use Traits\DatabaseJsonOptionTrait;
    use Traits\UseRequestSessionTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\ClearCacheTrait;

    protected $appends = ['work_targets', 'comment_type', 'flow_next_type', 'flow_next_count'];
    protected $casts = ['options' => 'json'];

    protected $work_targets;
    protected $work_condition_headers;

    public function workflow()
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function workflow_authorities()
    {
        return $this->hasMany(WorkflowAuthority::class, 'workflow_action_id');
        //->with(['user_organization']);
    }

    public function workflow_condition_headers()
    {
        return $this->hasMany(WorkflowConditionHeader::class, 'workflow_action_id');
    }

    public function getWorkflowCacheAttribute()
    {
        return Workflow::getEloquent($this->workflow_id);
    }

    public function getWorkflowAuthoritiesCacheAttribute()
    {
        return $this->hasManyCache(WorkflowAuthority::class, 'workflow_action_id');
    }

    public function getWorkflowConditionHeadersCacheAttribute()
    {
        return $this->hasManyCache(WorkflowConditionHeader::class, 'workflow_action_id');
    }

    public function getWorkTargetsAttribute()
    {
        $result = [];

        $keys = ['work_target_type'];
        foreach ($keys as $key) {
            $val = $this->getOption($key);
            if (isset($val)) {
                $result[$key] = $val;
            }

            /** @phpstan-ignore-next-line Left side of && is always true. */
            if ($key == 'work_target_type' && ($val == WorkflowWorkTargetType::FIX || $val == WorkflowWorkTargetType::GET_BY_USERINFO)) {
                $authorities = WorkflowAuthority::where('workflow_action_id', $this->id)->get();
                $authorities->each(function ($v) use (&$result) {
                    $result[array_get($v, 'related_type')][] = array_get($v, 'related_id');
                });
            }
        }

        return collect($result);
    }
    public function setWorkTargetsAttribute($work_targets)
    {
        if (is_nullorempty($work_targets)) {
            return;
        }

        $this->work_targets = jsonToArray($work_targets);

        return $this;
    }

    /**
     * Get work conditions. Contains status_to, enabled_flg, workflow_conditions, etc
     *
     * @return \Illuminate\Support\Collection|\Tightenco\Collect\Support\Collection
     */
    public function getWorkConditionsAttribute()
    {
        $headers = $this->workflow_condition_headers()
            ->with('workflow_conditions')
            ->get()->toArray();

        return collect($headers)->map(function ($header) {
            $header['workflow_conditions'] = collect(array_get($header, 'workflow_conditions', []))->map(function ($h) {
                return array_only(
                    $h,
                    ['id', 'condition_target', 'condition_type', 'condition_key', 'condition_value']
                );
            })->toArray();
            return array_only(
                $header,
                ['id', 'status_to', 'enabled_flg', 'workflow_conditions', 'condition_join', 'condition_reverse']
            );
        });
    }
    public function setWorkConditionsAttribute($work_conditions)
    {
        if (is_nullorempty($work_conditions)) {
            return $this;
        }

        $work_conditions = Condition::getWorkConditions($work_conditions);

        $this->work_condition_headers = $work_conditions;

        return $this;
    }

    /**
     * Get work condition select(for common action). Only return first item's status_to
     *
     * @return array
     */
    public function getWorkConditionSelectAttribute()
    {
        $headers = $this->workflow_condition_headers;
        if (count($headers) == 0) {
            return [];
        }
        return $headers->first()->status_to;
    }

    public function setWorkConditionSelectAttribute($work_condition)
    {
        // Whether contains header check.
        $headers = $this->workflow_condition_headers;
        $header = $headers->first() ?? new WorkflowConditionHeader([
            'enabled_flg' => 1,
        ]);

        $this->work_condition_headers = [[
            'id' => array_get($header, 'id'),
            'status_to' => $work_condition,
            'enabled_flg' => 1,
        ]];

        return $this;
    }

    public function getStatusFromNameAttribute()
    {
        if (is_numeric($this->status_from)) {
            return WorkflowStatus::getEloquent($this->status_from)->status_name;
        } elseif ($this->status_from == Define::WORKFLOW_START_KEYNAME) {
            return Workflow::getEloquent($this->workflow_id)->start_status_name;
        }

        return null;
    }

    public function getCommentTypeAttribute()
    {
        return $this->getOption('comment_type');
    }
    public function setCommentTypeAttribute($comment_type)
    {
        $this->setOption('comment_type', $comment_type);
        return $this;
    }

    public function getFlowNextTypeAttribute()
    {
        return $this->getOption('flow_next_type');
    }
    public function setFlowNextTypeAttribute($flow_next_type)
    {
        $this->setOption('flow_next_type', $flow_next_type);
        return $this;
    }

    public function getFlowNextCountAttribute()
    {
        return $this->getOption('flow_next_count');
    }
    public function setFlowNextCountAttribute($flow_next_count)
    {
        $this->setOption('flow_next_count', $flow_next_count);
        return $this;
    }

    /**
     * get eloquent using Cache.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentCache($id, $withs);
    }

    /**
     * set action authority
     */
    protected function setActionAuthority()
    {
        $this->syncOriginal();

        $keys = ['work_target_type'];
        $isSave = false;
        foreach ($keys as $key) {
            $val = array_get($this->work_targets, $key);
            if (isset($val)) {
                $this->setOption($key, $val);
                array_forget($this->work_targets, $key);
                $isSave = true;
            }
        }
        if ($isSave) {
            $this->save();
        }

        // target keys
        $keys = [ConditionTypeDetail::USER()->lowerKey(),
            ConditionTypeDetail::ORGANIZATION()->lowerKey(),
            ConditionTypeDetail::COLUMN()->lowerKey(),
            ConditionTypeDetail::SYSTEM()->lowerKey(),
            ConditionTypeDetail::LOGIN_USER_COLUMN()->lowerKey(),
        ];
        foreach ($keys as $key) {
            $ids = array_get($this->work_targets, $key, []);
            $values = collect($ids)->map(function ($id) use ($key) {
                return [
                    'related_id' => $id,
                    'related_type' => $key,
                    'workflow_action_id' => $this->id
                ];
            })->toArray();

            \Schema::insertDelete(SystemTableName::WORKFLOW_AUTHORITY, $values, [
                'dbValueFilter' => function (&$model) use ($key) {
                    $model->where('workflow_action_id', $this->id)
                        ->where('related_type', $key);
                },
                'dbDeleteFilter' => function (&$model, $dbValue) use ($key) {
                    $model->where('workflow_action_id', $this->id)
                        ->where('related_id', array_get((array)$dbValue, 'related_id'))
                        ->where('related_type', $key);
                },
                'matchFilter' => function ($dbValue, $value) {
                    return array_get((array)$dbValue, 'workflow_action_id') == $value['workflow_action_id']
                        && array_get((array)$dbValue, 'related_id') == $value['related_id']
                        && array_get((array)$dbValue, 'related_type') == $value['related_type']
                    ;
                },
            ]);
        }
    }

    /**
     * set action conditions
     */
    protected function setActionCondition()
    {
        $this->workflow_condition_headers()->delete();
        if (!isset($this->work_condition_headers)) {
            return;
        }

        foreach ($this->work_condition_headers as $work_condition_header) {
            if (array_get($work_condition_header, 'enabled_flg') != '1') {
                continue;
            }
            $work_condition_header['workflow_action_id'] = $this->id;

            $conditions = array_pull($work_condition_header, 'workflow_conditions', []);
            $header = WorkflowConditionHeader::create($work_condition_header);
            $header->workflow_conditions()->createMany($conditions);
        }
    }

    /**
     * Execute workflow action
     *
     * @param CustomValue $custom_value
     * @param array $data
     * @return WorkflowValue|null
     */
    public function executeAction($custom_value, $data = [])
    {
        $custom_table = $custom_value->custom_table;

        //execute plugin
        Plugin::pluginExecuteEvent(PluginEventTrigger::WORKFLOW_ACTION_EXECUTING, $custom_table, [
            'custom_table' => $custom_table,
            'custom_value' => $custom_value,
            'workflow_action' => $this,
        ]);

        $workflow = Workflow::getEloquent(array_get($this, 'workflow_id'));
        $is_edit = boolval($workflow->workflow_edit_flg);
        $next = $this->isActionNext($custom_value);

        $workflow_value = null;
        $status_to = $this->getStatusToId($custom_value);

        \ExmentDB::transaction(function () use ($custom_value, $data, $is_edit, $next, &$workflow_value, &$status_to) {
            $workflow_value = $this->forwardWorkflowValue($custom_value, $data);

            if ($next === true) {
                // if contains next_work_users, or action is GET_BY_USERINFO, set workflow_value_authorities
                if (array_key_value_exists('next_work_users', $data)) {
                    $user_organizations = array_get($data, 'next_work_users');
                    $user_organizations = collect($user_organizations)->filter()->map(function ($user_organization) use ($workflow_value) {
                        list($authoritable_user_org_type, $authoritable_target_id) = explode('_', $user_organization);
                        return [
                            'related_id' => $authoritable_target_id,
                            'related_type' => $authoritable_user_org_type,
                            'workflow_value_id' => $workflow_value->id,
                        ];
                    });

                    WorkflowValueAuthority::insert($user_organizations->toArray());

                    // set Custom Value Authoritable
                    CustomValueAuthoritable::setAuthoritableByUserOrgArray($custom_value, $user_organizations, $is_edit);

                    $custom_value->load(['workflow_value', 'workflow_value.workflow_value_authorities']);
                } else {
                    // get this getAuthorityTargets
                    $toActionAuthorities = $this->getNextActionAuthorities($custom_value, $status_to);
                    CustomValueAuthoritable::setAuthoritableByUserOrgArray($custom_value, $toActionAuthorities, $is_edit);
                }
            }
        });

        // notify workflow
        if ($next === true && isset($workflow_value)) {
            foreach ($workflow->notifies as $notify) {
                $notify->notifyWorkflow($custom_value, $this, $workflow_value, $status_to);
            }
        }

        // execute plugin
        Plugin::pluginExecuteEvent(PluginEventTrigger::WORKFLOW_ACTION_EXECUTED, $custom_table, [
            'custom_table' => $custom_table,
            'custom_value' => $custom_value,
            'workflow_action' => $this,
        ]);

        return $workflow_value;
    }

    /**
     * Forward workflow value.
     * (1)Update old workflow value's status.
     * (2)Create new workflow status
     *
     * @param CustomValue $custom_value
     * @param array $data comment
     * @return WorkflowValue created workflow value
     */
    protected function forwardWorkflowValue(CustomValue $custom_value, array $data = []): WorkflowValue
    {
        $next = $this->isActionNext($custom_value);
        $status_to = $this->getStatusToId($custom_value);
        $status_from = $custom_value->workflow_value->workflow_status_to_id ?? null;
        $morph_type = $custom_value->custom_table->table_name;
        $morph_id = $custom_value->id;

        // update old WorkflowValue
        WorkflowValue::where([
            'morph_type' => $morph_type,
            'morph_id' => $morph_id,
            'latest_flg' => true
        ])->update(['latest_flg' => false]);

        // if next, update action_executed_flg to false
        if ($next === true) {
            WorkflowValue::where([
                'morph_type' => $morph_type,
                'morph_id' => $morph_id,
                'action_executed_flg' => true
            ])->update(['action_executed_flg' => false]);
        }

        $createData = [
            'workflow_id' => array_get($this, 'workflow_id'),
            'morph_type' => $morph_type,
            'morph_id' => $morph_id,
            'workflow_action_id' => $this->id,
            'workflow_status_from_id' => $status_from == Define::WORKFLOW_START_KEYNAME ? null : $status_from,
            'workflow_status_to_id' => $status_to == Define::WORKFLOW_START_KEYNAME ? null : $status_to,
            'latest_flg' => 1,
        ];
        $createData['comment'] = array_get($data, 'comment');

        // if not next, update action_executed_flg to true
        if ($next !== true) {
            $createData['action_executed_flg'] = true;
        }

        return WorkflowValue::create($createData);
    }

    /**
     * Check has workflow authority
     *
     * @param CustomValue|null $custom_value
     * @param CustomValue $targetUser
     * @return boolean
     */
    public function hasAuthority($custom_value, $targetUser = null)
    {
        if (!isset($targetUser)) {
            $targetUser = \Exment::user()->base_user;
        }

        // check as workflow_value_authorities
        // only execute WorkflowWorkTargetType::ACTION_SELECT
        if ($this->getOption('work_target_type') == WorkflowWorkTargetType::ACTION_SELECT) {
            if (isset($custom_value) && isset($custom_value->workflow_value)) {
                $custom_value->load(['workflow_value', 'workflow_value.workflow_value_authorities']);
                $workflow_value_authorities = $custom_value->workflow_value->getWorkflowValueAutorities();
                foreach ($workflow_value_authorities as $workflow_value_authority) {
                    $item = ConditionItemBase::getDetailItemByAuthority($custom_value->custom_table, $workflow_value_authority);
                    if (!is_nullorempty($item) && $item->hasAuthority($workflow_value_authority, $custom_value, $targetUser)) {
                        return true;
                    }
                }
            }
        } else {
            // check as workflow_authorities
            $workflow_authorities = $this->workflow_authorities_cache;
            foreach ($workflow_authorities as $workflow_authority) {
                $item = ConditionItemBase::getDetailItemByAuthority($custom_value->custom_table, $workflow_authority);
                if (!is_nullorempty($item) && $item->hasAuthority($workflow_authority, $custom_value, $targetUser)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get users or organzations on this action authority
     *
     * @param CustomValue $custom_value
     * @return \Illuminate\Support\Collection
     */
    public function getAuthorityTargets($custom_value, string $workflowGetAuthorityType, $params = [])
    {
        // Convert options
        $options = $this->getAuthorityTargetOption($workflowGetAuthorityType);
        $options = array_merge($options, $params);
        $orgAsUser = array_boolval($options, 'orgAsUser', false);
        $asNextAction = array_boolval($options, 'asNextAction', false);
        $getValueAutorities = array_boolval($options, 'getValueAutorities', false);
        $getAutorities = array_boolval($options, 'getAutorities', false);

        // get users and organizations
        $userIds = [];
        $organizationIds = [];
        $labels = [];

        // add as workflow_value_authorities
        if ($getValueAutorities) {
            if (!is_nullorempty($custom_value) && isset($custom_value->workflow_value)) {
                $workflow_value_authorities = $custom_value->workflow_value->getWorkflowValueAutorities();
                foreach ($workflow_value_authorities as $workflow_value_authority) {
                    $type = ConditionTypeDetail::getEnum($workflow_value_authority->related_type);
                    switch ($type) {
                        case ConditionTypeDetail::USER:
                            $userIds[] = $workflow_value_authority->related_id;
                            break;
                        case ConditionTypeDetail::ORGANIZATION:
                            $organizationIds[] = $workflow_value_authority->related_id;
                            break;
                    }
                }
            }
        }

        // add as workflow_authorities
        if ($getAutorities) {
            $workflow_authorities = $this->workflow_authorities_cache;

            foreach ($workflow_authorities as $workflow_authority) {
                $results = $workflow_authority->getWorkflowAuthorityUserOrgLabels($custom_value, $this, $asNextAction);
                if (array_key_value_exists('users', $results)) {
                    foreach ($results['users'] as $id) {
                        $userIds[] = $id;
                    }
                }
                if (array_key_value_exists('organizations', $results)) {
                    foreach ($results['organizations'] as $id) {
                        $organizationIds[] = $id;
                    }
                }
                if (array_key_value_exists('labels', $results)) {
                    foreach ($results['labels'] as $id) {
                        $labels[] = $id;
                    }
                }
            }
        }

        $users = collect();
        if (count($userIds) > 0) {
            $users = getModelName(SystemTableName::USER)::find(array_unique($userIds));
        }

        $orgs = new \Illuminate\Database\Eloquent\Collection();
        if (System::organization_available() && count($organizationIds) > 0) {
            $orgs = getModelName(SystemTableName::ORGANIZATION)::find(array_unique($organizationIds));
        }

        $result = collect();
        if ($orgAsUser) {
            $org_users = collect();
            if ($orgs->count() > 0) {
                $org_users = $orgs->load('users')->map(function ($org) {
                    return $org->users;
                })->flatten();
            }
            $result = \Exment::uniqueCustomValues($users, $org_users);
        } else {
            $result = \Exment::uniqueCustomValues($users, $orgs);
        }

        return $result;
    }

    /**
     * getAuthorityTargetOption by WorkflowGetAuthorityType
     *
     * @param string $workflowGetAuthorityType
     * @return array
     *     $orgAsUser if true, convert organization to users
     *     $getValueAutorities if true, get by value authority
     *     $getAutorities if true, get by authority
     *     $asNextAction if true, get as next action. If false, get as current action. For use WorkflowWorkTargetType::GET_BY_USERINFO
     */
    protected function getAuthorityTargetOption(string $workflowGetAuthorityType): array
    {
        $work_target_type = $this->getOption('work_target_type');
        switch ($workflowGetAuthorityType) {
            case WorkflowGetAuthorityType::CURRENT_WORK_USER:
                return [
                    'orgAsUser' => false,
                    'asNextAction' => false,
                    // Whether getting value autorities, only $work_target_type is ACTION_SELECT
                    'getValueAutorities' => isMatchString($work_target_type, WorkflowWorkTargetType::ACTION_SELECT),
                    // Whether getting autorities, only $work_target_type is not ACTION_SELECT
                    'getAutorities' => !isMatchString($work_target_type, WorkflowWorkTargetType::ACTION_SELECT)
                ];
            case WorkflowGetAuthorityType::CALC_NEXT_USER_COUNT:
                return [
                    'orgAsUser' => true,
                    'asNextAction' => false,
                    // Whether getting value autorities, only $work_target_type is ACTION_SELECT
                    'getValueAutorities' => isMatchString($work_target_type, WorkflowWorkTargetType::ACTION_SELECT),
                    // Whether getting autorities, only $work_target_type is not ACTION_SELECT
                    'getAutorities' => !isMatchString($work_target_type, WorkflowWorkTargetType::ACTION_SELECT)
                ];
            case WorkflowGetAuthorityType::NEXT_USER_ON_EXECUTING_MODAL:
                return [
                    'orgAsUser' => false,
                    'asNextAction' => true,
                    'getValueAutorities' => false,
                    // Whether getting autorities, $work_target_type is FIX or GET_BY_USERINFO
                    'getAutorities' => isMatchString($work_target_type, WorkflowWorkTargetType::FIX) || isMatchString($work_target_type, WorkflowWorkTargetType::GET_BY_USERINFO),
                ];
            case WorkflowGetAuthorityType::EXEXCUTE:
                return [
                    'orgAsUser' => false,
                    'asNextAction' => true,
                    'getValueAutorities' => false,
                    // Whether getting autorities, $work_target_type is FIX or GET_BY_USERINFO
                    'getAutorities' => isMatchString($work_target_type, WorkflowWorkTargetType::FIX) || isMatchString($work_target_type, WorkflowWorkTargetType::GET_BY_USERINFO),
                ];
            case WorkflowGetAuthorityType::NOTIFY:
                return [
                    'orgAsUser' => true,
                    'asNextAction' => false,
                    // Whether getting value autorities, only $work_target_type is ACTION_SELECT
                    'getValueAutorities' => isMatchString($work_target_type, WorkflowWorkTargetType::ACTION_SELECT),
                    // Whether getting autorities, only $work_target_type is not ACTION_SELECT
                    'getAutorities' => !isMatchString($work_target_type, WorkflowWorkTargetType::ACTION_SELECT)
                ];
        }
        return [];
    }

    /**
     * Get status_to id. Filtering value
     *
     * @return string|null
     */
    public function getStatusToId($custom_value)
    {
        $next = $this->isActionNext($custom_value);

        if ($next === true) {
            // get matched condition
            $condition = $this->getMatchedCondtionHeader($custom_value);
            if (is_null($condition)) {
                return null;
            }

            return $condition['status_to'];
        } else {
            return $this->status_from;
        }
    }

    /**
     * Filtering condtions. use action condtion
     *
     * @return WorkflowConditionHeader|null
     */
    public function getMatchedCondtionHeader($custom_value)
    {
        if (count($this->workflow_condition_headers_cache) == 0) {
            return null;
        }

        foreach ($this->workflow_condition_headers_cache as $workflow_condition_header) {
            if ($workflow_condition_header->isMatchCondition($custom_value)) {
                return $workflow_condition_header;
            }
        }

        return null;
    }

    /**
     * Whether this action is next.
     *
     * @param CustomValue $custom_value
     * @return boolean|array If next, return true. else, [$flow_next_count, $action_executed_count]
     */
    public function isActionNext($custom_value)
    {
        list($isNext, $flow_next_count) = $this->getActionNextParams($custom_value);

        if ($isNext) {
            return true;
        }

        // get already execution action user's count
        $action_executed_count = WorkflowValue::where([
            'morph_type' => $custom_value->custom_table->table_name,
            'morph_id' => $custom_value->id,
            'workflow_action_id' => $this->id,
            'action_executed_flg' => true,
        ])->count();

        $need_next_count = $flow_next_count;

        if (!WorkflowValue::isAlreadyExecuted($this->id, $custom_value, \Exment::user()->base_user)) {
            $need_next_count -= 1;
        }

        if ($need_next_count <= $action_executed_count) {
            return true;
        }
        return [$flow_next_count, $action_executed_count];
    }

    /**
     * Get action next params.
     *
     * @return array ["is action next", "next minimum count"]
     */
    public function getActionNextParams($custom_value)
    {
        if (($flow_next_count = $this->getOption("flow_next_count", 1)) == 1 && $this->flow_next_type == WorkflowNextType::SOME) {
            return [true, null];
        }

        if ($this->flow_next_type == WorkflowNextType::SOME) {
            return [false, $flow_next_count];
        }

        return [false, $this->getAuthorityTargets($custom_value, WorkflowGetAuthorityType::CALC_NEXT_USER_COUNT)->count()];
    }

    /**
     * Get action modal form
     *
     * @param CustomValue $custom_value
     * @return Response
     */
    public function actionModal($custom_value)
    {
        $custom_table = $custom_value->custom_table;
        $path = admin_urls('data', $custom_table->table_name, $custom_value->id, 'actionClick');

        // create form fields
        $form = new ModalForm();
        $form->action($path);

        // get suatus info
        $statusFromName = esc_html(WorkflowStatus::getWorkflowStatusName($this->status_from, $this->workflow));
        $statusTo = $this->getStatusToId($custom_value);
        $statusToName = esc_html(WorkflowStatus::getWorkflowStatusName($statusTo, $this->workflow));

        // showing status
        if ($statusFromName != $statusToName) {
            $showStatus = "$statusFromName → <span class='red bold'>$statusToName</span>";
        } else {
            $showStatus = $statusFromName;
        }

        // check already executed user
        $showSubmit = !WorkflowValue::isAlreadyExecuted($this->id, $custom_value, \Exment::user()->base_user);

        if ($showSubmit) {
            $form->descriptionHtml(exmtrans('workflow.message.action_execute'));
        }

        $form->display('action_name', exmtrans('workflow.action_name'))
            ->default($this->action_name);

        $form->display('status', exmtrans('workflow.status'))
            ->displayText($showStatus)->escape(false);

        $next = $this->isActionNext($custom_value);
        $completed = WorkflowStatus::getWorkflowStatusCompleted($statusTo);
        if ($next === true && !$completed) {
            // get next actions
            $nextActions = WorkflowStatus::getActionsByFrom($statusTo, $this->workflow);
            $normalActions = $nextActions->filter(function ($action) {
                return !boolval($action->ignore_work);
            });
            if ($normalActions->count() > 0) {
                $toActionAuthorities = $this->getNextActionAuthorities($custom_value, $statusTo, $normalActions);

                // show target users text
                $select = $nextActions->contains(function ($nextAction) {
                    return $nextAction->getOption('work_target_type') == WorkflowWorkTargetType::ACTION_SELECT;
                });
                // select hidden items
                $select_hidden = $nextActions->first(function ($nextAction) {
                    return $nextAction->getOption('work_target_type') == WorkflowWorkTargetType::GET_BY_USERINFO;
                });

                $isDisableForm = false;
                // if select, show options
                if ($select) {
                    list($options, $ajax) = CustomValueAuthoritable::getUserOrgSelectOptions($custom_table, null, true);
                    $form->multipleSelect('next_work_users', exmtrans('workflow.next_work_users'))
                        ->options($options)
                        ->ajax($ajax)
                        ->required();

                    // If not ajax and $options is empty, disable form.
                    if (is_nullorempty($options) && is_nullorempty($ajax)) {
                        $showSubmit = false;
                    }
                } else {
                    // only display
                    $displayText = $toActionAuthorities->count() == 0 ? "<span class='red bold'>" .exmtrans('workflow.message.nextuser_not_found') . "</span>" : $toActionAuthorities->map(function ($toActionAuthority) {
                        return $toActionAuthority->getUrl([
                            'tag' => true,
                            'only_avatar' => true,
                        ]);
                    })->implode(exmtrans('common.separate_word'));
                    $form->display('next_work_users_display', exmtrans('workflow.next_work_users'))
                        ->displayText($displayText)->escape(false);

                    // if has $select_hidden, set as hidden item
                    if (!is_nullorempty($select_hidden)) {
                        $form->hidden('get_by_userinfo_action')->default($select_hidden->id);
                    }

                    if (is_nullorempty($toActionAuthorities)) {
                        $showSubmit = false;
                    }
                }
            }
        }

        // not next, showing message
        elseif ($next !== true) {
            list($flow_next_count, $action_executed_count) = $next;

            $form->display('flow_executed_user_count', exmtrans('workflow.flow_executed_user_count'))
            ->help(exmtrans('workflow.help.flow_executed_user_count'))
            ->default(exmtrans('workflow.flow_executed_user_count_format', $action_executed_count, $flow_next_count));
        }

        // check already executed user
        if ($showSubmit) {
            if ($this->comment_type != WorkflowCommentType::NOTUSE) {
                $field = $form->textarea('comment', exmtrans('common.comment'));
                // check required
                if ($this->comment_type == WorkflowCommentType::REQUIRED) {
                    $field->required();
                }
            }
        }

        $form->hidden('action_id')->default($this->id);

        $form->setWidth(9, 3);

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => $this->action_name,
            'showSubmit' => $showSubmit,
        ]);
    }

    /**
     * get next action Authorities
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getNextActionAuthorities($custom_value, $statusTo, $nextActions = null)
    {
        // get next actions
        $toActionAuthorities = collect();

        if (is_null($nextActions)) {
            $nextActions = WorkflowStatus::getActionsByFrom($statusTo, $this->workflow, true);
        }
        $nextActions->each(function ($workflow_action) use (&$toActionAuthorities, $custom_value) {
            $is_select = $workflow_action->getOption('work_target_type') == WorkflowWorkTargetType::ACTION_SELECT;
            // "getAuthorityTargets" set $getValueAutorities i false, because getting next action
            $toActionAuthorities = \Exment::uniqueCustomValues(
                $toActionAuthorities,
                $workflow_action->getAuthorityTargets($custom_value, WorkflowGetAuthorityType::NEXT_USER_ON_EXECUTING_MODAL)
            );
        });

        return $toActionAuthorities;
    }

    protected static function boot()
    {
        parent::boot();

        static::saved(function ($model) {
            $model->setActionAuthority();
            $model->setActionCondition();
        });

        static::deleting(function ($model) {
            $model->deletingChildren();
        });
    }

    public function deletingChildren()
    {
        $keys = ['workflow_authorities', 'workflow_condition_headers'];
        $this->load($keys);
        foreach ($keys as $key) {
            foreach ($this->{$key} as $item) {
                if (!method_exists($item, 'deletingChildren')) {
                    continue;
                }
                $item->deletingChildren();
            }

            $this->{$key}()->delete();
        }
    }


    /**
     * Append workflow status from query.
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param string $workflow_status
     * @return void
     */
    public static function appendStatusFromQuery($query, $workflow_status)
    {
        // if sql server, append cast
        if (\Exment::isSqlServer()) {
            // create where raw query
            /** @phpstan-ignore-next-line */
            $column = \DB::getQueryGrammar()->getCastColumn(DatabaseDataType::TYPE_STRING, SystemTableName::WORKFLOW_ACTION . '.status_from');
            $whereStatusStart = $column . ' = ' . \Exment::wrapValue($workflow_status);
            $query->whereRaw($whereStatusStart);
        } else {
            $query->where(SystemTableName::WORKFLOW_ACTION . '.status_from', $workflow_status);
        }
    }

    /**
     * Append workflow status from and join workflow_status_to_id query.
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @return void
     */
    public static function appendStatusFromJoinQuery($query)
    {
        // if sql server, append cast
        if (\Exment::isSqlServer()) {
            // create where raw query
            /** @phpstan-ignore-next-line */
            $whereStatusStart = \Exment::wrapColumn(SystemTableName::WORKFLOW_ACTION . '.status_from') . ' = ' . \DB::getQueryGrammar()->getCastColumn(DatabaseDataType::TYPE_STRING, SystemTableName::WORKFLOW_VALUE . '.workflow_status_to_id');
            $query->whereRaw($whereStatusStart);
        } else {
            $query->whereColumn(SystemTableName::WORKFLOW_ACTION . '.status_from', SystemTableName::WORKFLOW_VALUE . '.workflow_status_to_id');
        }
    }
}
