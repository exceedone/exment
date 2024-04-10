<?php

namespace Exceedone\Exment\Database\Seeder;

use Illuminate\Database\Seeder;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowTable;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowAuthority;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Model\WorkflowConditionHeader;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomValueAuthoritable;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Enums\WorkflowGetAuthorityType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterOption;

class WorkflowTestDataSeeder extends Seeder
{
    use TestDataTrait;

    /**
     * Run testdata.
     *
     * @return void
     */
    public function run()
    {
        $users = $this->getUsersAndOrgs()['user'];

        $this->createWorkflow($users);
    }


    /**
     * Create Workflow
     *
     * @return void
     */
    protected function createWorkflow($users)
    {
        // get user's "boss" column.
        $boss = CustomColumn::getEloquent('boss', 'user');

        // create workflows
        $workflows = [
            [
                'items' => [
                    'workflow_view_name' => 'workflow_common_company',
                    'workflow_type' => 0,
                    'setting_completed_flg' => 1,
                ],

                'statuses' => [
                    [
                        'status_name' => 'middle',
                        'datalock_flg' => 0,
                    ],
                    [
                        'status_name' => 'temp',
                        'datalock_flg' => 1,
                    ],
                    [
                        'status_name' => 'end',
                        'datalock_flg' => 1,
                        'completed_flg' => 1,
                    ],
                ],

                'actions' => [
                    [
                        'status_from' => 'start',
                        'action_name' => 'middle_action',

                        'options' => [
                            'comment_type' => 'nullable',
                            'flow_next_type' => 'some',
                            'flow_next_count' => '1',
                            'work_target_type' => WorkflowWorkTargetType::FIX,
                        ],

                        'condition_headers' => [
                            [
                                'status_to' => 0,
                                'enabled_flg' => true,
                            ],
                        ],

                        'authorities' => [
                            [
                                'related_id' => 0,
                                'related_type' => 'system',
                            ]
                        ],
                    ],

                    [
                        'status_from' => 0,
                        'action_name' => 'temp_action',

                        'options' => [
                            'comment_type' => 'nullable',
                            'flow_next_type' => 'some',
                            'flow_next_count' => '1',
                            'work_target_type' => WorkflowWorkTargetType::FIX,
                        ],

                        'condition_headers' => [
                            [
                                'status_to' => 1,
                                'enabled_flg' => true,
                            ],
                        ],

                        'authorities' => [
                            [
                                'related_id' => 6, // dev0-userB
                                'related_type' => 'user',
                            ]
                        ],
                    ],
                    [
                        'status_from' => 1,
                        'action_name' => 'end_action',

                        'options' => [
                            'comment_type' => 'required',
                            'flow_next_type' => 'some',
                            'flow_next_count' => '2',
                            'work_target_type' => WorkflowWorkTargetType::ACTION_SELECT,
                        ],

                        'condition_headers' => [
                            [
                                'status_to' => 2,
                                'enabled_flg' => true,
                            ],
                        ],

                        'authorities' => [
                        ],
                    ],
                ],

                'tables' => [
                    [
                        'custom_table' => 'custom_value_edit_all',
                    ],
                    [
                        'custom_table' => 'no_permission',
                    ],
                ],
            ],
            [
                'items' => [
                    'workflow_view_name' => 'workflow_common_no_complete',
                    'workflow_type' => 0,
                    'setting_completed_flg' => 0,
                ],

                'statuses' => [
                    [
                        'status_name' => 'waiting',
                        'datalock_flg' => 0,
                    ],
                    [
                        'status_name' => 'completed',
                        'datalock_flg' => 1,
                        'completed_flg' => 1,
                    ],
                ],

                'actions' => [
                    [
                        'status_from' => 'start',
                        'action_name' => 'send',

                        'options' => [
                            'comment_type' => 'nullable',
                            'flow_next_type' => 'some',
                            'flow_next_count' => '1',
                            'work_target_type' => WorkflowWorkTargetType::FIX,
                        ],

                        'condition_headers' => [
                            [
                                'status_to' => 0,
                                'enabled_flg' => true,
                            ],
                        ],

                        'authorities' => [
                            [
                                'related_id' => 0,
                                'related_type' => 'system',
                            ]
                        ],
                    ],

                    [
                        'status_from' => 0,
                        'action_name' => 'complete',

                        'options' => [
                            'comment_type' => 'nullable',
                            'flow_next_type' => 'some',
                            'flow_next_count' => '1',
                            'work_target_type' => WorkflowWorkTargetType::FIX,
                        ],

                        'condition_headers' => [
                            [
                                'status_to' => 1,
                                'enabled_flg' => true,
                            ],
                        ],

                        'authorities' => [
                            [
                                'related_id' => 6, // dev0-userB
                                'related_type' => 'user',
                            ]
                        ],
                    ],
                ],

                'tables' => [
                    [
                        'custom_table' => 'custom_value_access_all',
                    ],
                ],
            ],
            [
                'items' => [
                    'workflow_view_name' => 'workflow_for_individual_table',
                    'workflow_type' => 1,
                    'setting_completed_flg' => 1,

                    'options' => [
                        'workflow_edit_flg' => '1',
                    ],
                ],

                'statuses' => [
                    [
                        'status_name' => 'status1',
                        'datalock_flg' => 0,
                    ],
                    [
                        'status_name' => 'status2',
                        'datalock_flg' => 1,
                        'completed_flg' => 1,
                    ],
                ],

                'actions' => [
                    [
                        'status_from' => 'start',
                        'action_name' => 'action1',

                        'options' => [
                            'comment_type' => 'nullable',
                            'flow_next_type' => 'some',
                            'flow_next_count' => '1',
                            'work_target_type' => WorkflowWorkTargetType::FIX,
                        ],

                        'condition_headers' => [
                            [
                                'status_to' => 0,
                                'enabled_flg' => true,
                                'options' => [
                                    'condition_reverse' => '1',
                                ],
                                'conditions' => [
                                    'condition_type' => 0,
                                    'condition_key' => FilterOption::EQ,
                                    'target_column_id' => 'multiples_of_3',
                                    'condition_value' => 1,
                                ]
                            ],
                            [
                                'status_to' => 1,
                                'enabled_flg' => true,
                            ],
                        ],

                        'authorities' => [
                            [
                                'related_id' => 0,
                                'related_type' => 'system',
                            ]
                        ],
                    ],

                    [
                        'status_from' => 0,
                        'action_name' => 'action2',

                        'options' => [
                            'comment_type' => 'nullable',
                            'flow_next_type' => 'some',
                            'flow_next_count' => '1',
                            'work_target_type' => WorkflowWorkTargetType::FIX,
                        ],

                        'condition_headers' => [
                            [
                                'status_to' => 1,
                                'enabled_flg' => true,
                            ],
                        ],

                        'authorities' => [
                            [
                                'related_id' => 2, // dev
                                'related_type' => 'organization',
                            ]
                        ],
                    ],
                    [
                        'status_from' => 0,
                        'action_name' => 'action3',
                        'ignore_work' => 1,

                        'options' => [
                            'comment_type' => 'nullable',
                            'flow_next_type' => 'some',
                            'flow_next_count' => '1',
                            'work_target_type' => WorkflowWorkTargetType::FIX,
                        ],

                        'condition_headers' => [
                            [
                                'status_to' => 'start',
                                'enabled_flg' => true,
                            ],
                        ],

                        'authorities' => [
                            [
                                'related_id' => 0,
                                'related_type' => 'system',
                            ]
                        ],
                    ],
                ],

                'tables' => [
                    [
                        'custom_table' => 'custom_value_edit',
                    ],
                ],
            ],

            [
                'items' => [
                    'workflow_view_name' => 'workflow_common_userinfo',
                    'workflow_type' => 0,
                    'setting_completed_flg' => 1,
                ],

                'statuses' => [
                    [
                        'status_name' => 'waiting',
                        'datalock_flg' => 0,
                    ],
                    [
                        'status_name' => 'waiting2',
                        'datalock_flg' => 1,
                    ],
                    [
                        'status_name' => 'completed',
                        'datalock_flg' => 1,
                        'completed_flg' => 1,
                    ],
                ],

                'actions' => [
                    [
                        'status_from' => 'start',
                        'action_name' => 'send',

                        'options' => [
                            'comment_type' => 'nullable',
                            'flow_next_type' => 'some',
                            'flow_next_count' => '1',
                            'work_target_type' => WorkflowWorkTargetType::FIX,
                        ],

                        'condition_headers' => [
                            [
                                'status_to' => 0,
                                'enabled_flg' => true,
                            ],
                        ],

                        'authorities' => [
                            [
                                'related_id' => 0,
                                'related_type' => 'system',
                            ]
                        ],
                    ],
                    [
                        'status_from' => 0,
                        'action_name' => 'send2',

                        'options' => [
                            'comment_type' => 'nullable',
                            'flow_next_type' => 'some',
                            'flow_next_count' => '1',
                            'work_target_type' => WorkflowWorkTargetType::GET_BY_USERINFO,
                        ],

                        'condition_headers' => [
                            [
                                'status_to' => 1,
                                'enabled_flg' => true,
                            ],
                        ],

                        'authorities' => [
                            [
                                'related_id' => $boss->id,
                                'related_type' => ConditionTypeDetail::LOGIN_USER_COLUMN()->lowerKey(),
                            ]
                        ],
                    ],
                    [
                        'status_from' => 1,
                        'action_name' => 'complete',

                        'options' => [
                            'comment_type' => 'nullable',
                            'flow_next_type' => 'some',
                            'flow_next_count' => '1',
                            'work_target_type' => WorkflowWorkTargetType::GET_BY_USERINFO,
                        ],

                        'condition_headers' => [
                            [
                                'status_to' => 2,
                                'enabled_flg' => true,
                            ],
                        ],

                        'authorities' => [
                            [
                                'related_id' => $boss->id,
                                'related_type' => ConditionTypeDetail::LOGIN_USER_COLUMN()->lowerKey(),
                            ]
                        ],
                    ],
                ],
                'tables' => [
                    [
                        'custom_table' => 'workflow1',
                    ],
                ],
            ],
        ];


        foreach ($workflows as $workflow) {
            $workflowObj = new Workflow();
            foreach ($workflow['items'] as $key => $item) {
                $workflowObj->{$key} = $item;
            }
            $workflowObj->start_status_name = 'start';
            $workflowObj->save();

            foreach ($workflow['statuses'] as $index => &$status) {
                $workflowstatus = new WorkflowStatus();
                $workflowstatus->workflow_id = $workflowObj->id;

                foreach ($status as $key => $item) {
                    $workflowstatus->{$key} = $item;
                }
                $workflowstatus->order = $index;

                $workflowstatus->save();
                $status['id'] = $workflowstatus->id;
                $status['index'] = $index;
            }

            $actionStatusFromTos = [];
            foreach ($workflow['actions'] as &$action) {
                $actionStatusFromTo = [];

                $workflowaction = new WorkflowAction();
                $workflowaction->workflow_id = $workflowObj->id;
                $workflowaction->action_name = $action['action_name'];
                $workflowaction->ignore_work = $action['ignore_work']?? 0;

                if ($action['status_from'] === 'start') {
                    $workflowaction->status_from = $action['status_from'];
                    $actionStatusFromTo['status_from'] = null;
                } else {
                    /** @phpstan-ignore-next-line  Because an error occurs in SQLServer's UT when the 'id' is set */
                    $workflowaction->status_from = $workflow['statuses'][$action['status_from']]['id'];
                    $actionStatusFromTo['status_from'] = $workflowaction->status_from;
                }

                foreach ($action['options'] as $key => $item) {
                    $workflowaction->setOption($key, $item);
                }
                $workflowaction->save();
                $action['id'] = $workflowaction->id;

                foreach ($action['authorities'] as $key => $item) {
                    $item['workflow_action_id'] = $workflowaction->id;
                    if ($item['related_type'] == 'column') {
                        $custom_column = CustomColumn::getEloquent($item['related_id'], $workflow['tables'][0]['custom_table']);
                        $item['related_id'] = $custom_column->id;
                    }
                    WorkflowAuthority::insert($item);
                }

                foreach ($action['condition_headers'] as $key => $item) {
                    $header = new WorkflowConditionHeader();
                    $header->enabled_flg = $item['enabled_flg'];
                    $header->workflow_action_id = $workflowaction->id;

                    if ($item['status_to'] === 'start') {
                        $header->status_to = $item['status_to'];
                        $actionStatusFromTo['status_to'] = null;
                    } else {
                        /** @phpstan-ignore-next-line  Because an error occurs in SQLServer's UT when the 'id' is set */
                        $header->status_to = $workflow['statuses'][$item['status_to']]['id'];
                        $actionStatusFromTo['status_to'] = $header->status_to;
                    }

                    if (isset($item['options'])) {
                        $header->options = $item['options'];
                    } 

                    $header->save();

                    if (isset($item['conditions'])) {
                        $conditions = $item['conditions'];
                        $conditions['morph_type'] = 'workflow_condition_header';
                        $conditions['morph_id'] = $header->id;
                        // @phpstan-ignore-next-line
                        if (isset($conditions['target_column_id'])) {
                            \Log::debug($workflow['tables'][0]['custom_table']);
                            $target_column = CustomColumn::getEloquent($conditions['target_column_id'], $workflow['tables'][0]['custom_table']);
                            $conditions['target_column_id'] = $target_column->id;
                        }
                        Condition::create($conditions);
                    } 
                }

                $actionStatusFromTo['workflow_action_id'] = $workflowaction->id;
                $actionStatusFromTos[] = $actionStatusFromTo;
            }

            foreach ($workflow['tables'] as &$table) {
                $wfTable = new WorkflowTable();
                $wfTable->workflow_id = $workflowObj->id;
                $wfTable->custom_table_id = CustomTable::getEloquent($table['custom_table'])->id;
                $wfTable->active_flg = true;
                $wfTable->save();

                // create workflow value
                $wfValueStatuses = array_merge(
                    [['id' => null]],
                    $workflow['statuses']
                );

                $userKeys = [
                    'dev1-userD',
                ];

                $wfUserKeys = [
                    'dev1-userD',
                    'dev0-userB',
                    'dev1-userC',
                ];

                foreach ($userKeys as $userKey) {
                    $user = $users[$userKey];
                    \Auth::guard('admin')->attempt([
                        'username' => array_get($user, 'value.user_code'),
                        'password' => array_get($user, 'password')
                    ]);

                    $custom_value = CustomTable::getEloquent($table['custom_table'])->getValueModel();
                    $custom_value->setValue("text", "test_$userKey");
                    $custom_value->setValue("index_text", "index_$userKey");
                    $custom_value->setValue("date", \Carbon\Carbon::today());
                    $custom_value->id = 1000;
                    $custom_value->save();

                    foreach ($wfValueStatuses as $index => $wfValueStatus) {
                        if (!isset($wfValueStatus['id']) || $index == 0) {
                            continue;
                        }

                        $latest_flg = count($wfValueStatuses) - 1 === $index;

                        if ($table['custom_table'] == 'custom_value_edit_all') {
                            if ($index === 1) {
                                $latest_flg = true;
                            } else {
                                continue;
                            }
                        }

                        // get target $actionStatusFromTo
                        $actionStatusFromTo = collect($actionStatusFromTos)->first(function ($actionStatusFromTo) use ($wfValueStatuses, $index) {
                            return $wfValueStatuses[$index - 1]['id'] == $actionStatusFromTo['status_from'] && $wfValueStatuses[$index]['id'] == $actionStatusFromTo['status_to'];
                        });
                        if (!isset($actionStatusFromTo)) {
                            continue;
                        }

                        $user = $users[$wfUserKeys[$index - 1]];
                        \Auth::guard('admin')->attempt([
                            'username' => array_get($user, 'value.user_code'),
                            'password' => array_get($user, 'password')
                        ]);

                        $wfValue = new WorkflowValue();
                        $wfValue->workflow_id = $workflowObj->id;
                        $wfValue->morph_type = $table['custom_table'];
                        $wfValue->morph_id = $custom_value->id;
                        $wfValue->workflow_action_id = $actionStatusFromTo['workflow_action_id'];
                        $wfValue->workflow_status_from_id = $actionStatusFromTo['status_from'];
                        $wfValue->workflow_status_to_id = $actionStatusFromTo['status_to'];
                        $wfValue->action_executed_flg = 0;
                        $wfValue->latest_flg = $latest_flg;

                        $this->saveWorkflowValue($wfValue, $workflowObj, $custom_value);
                    }
                }
            }

            $this->createNotify($workflowObj);
        }

        $user = $users['admin'];
        \Auth::guard('admin')->attempt([
            'username' => array_get($user, 'value.user_code'),
            'password' => array_get($user, 'password')
        ]);

        // add for organization work user
        $workflowObj = Workflow::getEloquent(3); // workflow_for_individual_table
        $wfValue = new WorkflowValue();
        $wfValue->workflow_id = $workflowObj->id;
        $wfValue->morph_type = 'custom_value_edit';
        $wfValue->morph_id = 1;
        $wfValue->workflow_action_id = 6;
        $wfValue->workflow_status_to_id = 6;
        $wfValue->action_executed_flg = 0;
        $wfValue->latest_flg = 1;
        $this->saveWorkflowValue($wfValue, $workflowObj);

        // add for summary view test
        $wfValue = new WorkflowValue();
        $wfValue->workflow_id = $workflowObj->id;
        $wfValue->morph_type = 'custom_value_edit';
        $wfValue->morph_id = 3;
        $wfValue->workflow_action_id = 6;
        $wfValue->workflow_status_to_id = 6;
        $wfValue->action_executed_flg = 0;
        $wfValue->latest_flg = 1;
        $this->saveWorkflowValue($wfValue, $workflowObj);
    }

    protected function saveWorkflowValue($wfValue, $workflowObj, $custom_value = null)
    {
        $wfValue->save();

        $is_edit = boolval($workflowObj->workflow_edit_flg);

        if (is_null($custom_value)) {
            $custom_value = CustomTable::getEloquent($wfValue->morph_type)->getValueModel()->find($wfValue->morph_id);
        }

        // get this getAuthorityTargets
        $toActionAuthorities = $this->getNextActionAuthorities($workflowObj, $custom_value, $wfValue->workflow_status_to_id);
        CustomValueAuthoritable::setAuthoritableByUserOrgArray($custom_value, $toActionAuthorities, $is_edit);
    }

    /**
     * get next action Authorities
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getNextActionAuthorities($workflow, $custom_value, $statusTo, $nextActions = null)
    {
        // get next actions
        $toActionAuthorities = collect();

        if (is_null($nextActions)) {
            $nextActions = WorkflowStatus::getActionsByFrom($statusTo, $workflow, true);
        }
        $nextActions->each(function ($workflow_action) use (&$toActionAuthorities, $custom_value) {
            // "getAuthorityTargets" set $getValueAutorities i false, because getting next action
            $toActionAuthorities = \Exment::uniqueCustomValues(
                $toActionAuthorities,
                $workflow_action->getAuthorityTargets($custom_value, WorkflowGetAuthorityType::NEXT_USER_ON_EXECUTING_MODAL)
            );
        });

        return $toActionAuthorities;
    }


    /**
     * Create workflow notify
     *
     * @param Workflow $workflow
     * @return false|void
     * @throws \Exceedone\Exment\Exceptions\NoMailTemplateException
     */
    protected function createNotify(Workflow $workflow)
    {
        if (!boolval($workflow->setting_completed_flg)) {
            return false;
        }
        $notify = new Notify();
        $notify->notify_view_name = $workflow->workflow_view_name;
        $notify->target_id = $workflow->id;
        $notify->notify_trigger = Enums\NotifyTrigger::WORKFLOW;
        $notify->mail_template_id = $this->getMailTemplateFromKey(Enums\MailKeyName::WORKFLOW_NOTIFY)->id;
        $notify->action_settings = [[
            "notify_action" => Enums\NotifyAction::SHOW_PAGE,
            "notify_action_target" => [Enums\NotifyActionTarget::CREATED_USER, Enums\NotifyActionTarget::WORK_USER],
        ]];
        $notify->save();
    }
}
