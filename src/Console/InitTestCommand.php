<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowTable;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowAuthority;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Model\WorkflowConditionHeader;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Enums\CustomValueAutoShare;
use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Laravel\Passport\ClientRepository;

class InitTestCommand extends Command
{
    use CommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:inittest';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize environment for test.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        
        $this->initExmentCommand();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (!$this->confirm('Really initialize environment? All reset this environment.')) {
            return;
        }

        \Artisan::call('migrate:reset');

        System::clearCache();

        \Artisan::call('exment:install');

        System::clearCache();
        
        $this->createSystem();

        $users = $this->createUserOrg();
       
        $custom_tables = $this->createTables($users);

        $this->createPermission($custom_tables);

        $this->createWorkflow($users);

        // init api
        $clientRepository = new ClientRepository;
        $client = $clientRepository->createPasswordGrantClient(
            1,
            Define::API_FEATURE_TEST,
            'http://localhost'
        );
    }

    protected function createSystem(){
        // create system data
        $systems = [
            'initialized' => true,
            'system_admin_users' => [1],
            'api_available' => true,
        ];
        foreach($systems as $key => $value){
            System::{$key}($value);
        }
    }

    protected function createUserOrg(){
        // set users
        $values = [
            'user' => [
                'admin' => [
                    'id' => 1,
                    'value' => [
                        'user_name' => 'admin',
                        'user_code' => 'admin',
                        'email' => 'admin@admin.foobar.test',
                    ],
                    'password' => 'adminadmin',
                ],
                'user1' => [
                    'id' => 2,
                    'value' => [
                        'user_name' => 'user1',
                        'user_code' => 'user1',
                        'email' => 'user1@user.foobar.test',    
                    ],
                    'password' => 'user1user1',
                ],
                'user2' => [
                    'id' => 3,
                    'value' => [
                        'user_name' => 'user2',
                        'user_code' => 'user2',
                        'email' => 'user2@user.foobar.test',
                    ],
                    'password' => 'user2user2',
                ],
                'user3' => [
                    'id' => 4,
                    'value' => [
                        'user_name' => 'user3',
                        'user_code' => 'user3',
                        'email' => 'user2@user.foobar.test',
                    ],
                    'password' => 'user3user3',
                ],
                'company1-userA' => [
                    'id' => 5,
                    'value' => [
                        'user_name' => 'company1-userA',
                        'user_code' => 'company1-userA',
                        'email' => 'company1-userA@user.foobar.test',
                    ],
                    'password' => 'company1-userA',
                ],
                'dev-userB' => [
                    'id' => 6,
                    'value' => [
                        'user_name' => 'dev-userB',
                        'user_code' => 'dev-userB',
                        'email' => 'dev-userB@user.foobar.test',
                    ],
                    'password' => 'dev-userB',
                ],
                'dev1-userC' => [
                    'id' => 7,
                    'value' => [
                        'user_name' => 'dev1-userC',
                        'user_code' => 'dev1-userC',
                        'email' => 'dev1-userC@user.foobar.test',
                    ],
                    'password' => 'dev1-userC',
                ],
                'dev1-userD' => [
                    'id' => 8,
                    'value' => [
                        'user_name' => 'dev1-userD',
                        'user_code' => 'dev1-userD',
                        'email' => 'dev1-userD@user.foobar.test',
                    ],
                    'password' => 'dev1-userD',
                ],
                'dev2-userE' => [
                    'id' => 9,
                    'value' => [
                        'user_name' => 'dev2-userE',
                        'user_code' => 'dev2-userE',
                        'email' => 'dev2-userE@user.foobar.test',
                    ],
                    'password' => 'dev2-userE',
                ],
                'company2-userF' => [
                    'id' => 10,
                    'value' => [
                        'user_name' => 'company2-userF',
                        'user_code' => 'company2-userF',
                        'email' => 'company2-userF@user.foobar.test',
                    ],
                    'password' => 'company2-userF',
                ],
            ], 

            'organization' => [
                'company1' => [
                    'id' => 1,
                    'value' => [
                        'organization_name' => 'company1',
                        'organization_code' => 'company1',
                        'parent_organization' => null,
                    ],
                    'users' => [
                        5
                    ],
                ],
                'dev' => [
                    'id' => 2,
                    'value' => [
                        'organization_name' => 'dev',
                        'organization_code' => 'dev',
                        'parent_organization' => 1,
                    ],
                    'users' => [
                        6
                    ],
                ],
                'manage' => [
                    'id' => 3,
                    'value' => [
                        'organization_name' => 'manage',
                        'organization_code' => 'manage',
                        'parent_organization' => 1,
                    ],
                ],
                'dev1' => [
                    'id' => 4,
                    'value' => [
                        'organization_name' => 'dev1',
                        'organization_code' => 'dev1',
                        'parent_organization' => 2,
                    ],
                    'users' => [
                        7, 8
                    ],
                ],
                'dev2' => [
                    'id' => 5,
                    'value' => [
                        'organization_name' => 'dev2',
                        'organization_code' => 'dev2',
                        'parent_organization' => 2,
                    ],
                    'users' => [
                        9
                    ],
                ],
                'company2' => [
                    'id' => 6,
                    'value' => [
                        'organization_name' => 'company2',
                        'organization_code' => 'company2',
                        'parent_organization' => null,
                    ],
                    'users' => [
                        10
                    ],
                ],
                'company2-a' => [
                    'id' => 7,
                    'value' => [
                        'organization_name' => 'company2-a',
                        'organization_code' => 'company2-a',
                        'parent_organization' => 6,
                    ],
                ],
            ]
        ];

        // set rolegroups
        $rolegroups = [
            'user' => [
                'user1' => [1], //data_admin_group
                'user2' => [4], //user_group
                'user3' => [4], //user_group    
            ],
            'organization' => [
                'dev' => [4], //user_group
            ],
        ];

        $relationName = CustomRelation::getRelationNamebyTables('organization', 'user');

        foreach($values as $type => $typevalue){
            foreach($typevalue as $user_key => &$user){
                $model = CustomTable::getEloquent($type)->getValueModel();
                foreach($user['value'] as $key => $value){
                    $model->setValue($key, $value);
                }

                $model->save();

                if(array_has($user, 'password')){
                    $loginUser = new LoginUser;
                    $loginUser->base_user_id = $model->id;
                    $loginUser->password = $user['password'];
                    $loginUser->save();    
                }

                if(array_has($user, 'users')){
                    $inserts = collect($user['users'])->map(function($item) use($model){
                        return ['parent_id' => $model->id, 'child_id' => $item];
                    })->toArray();

                    \DB::table($relationName)->insert($inserts);
                }

                if (isset($rolegroups[$type][$user_key]) && is_array($rolegroups[$type][$user_key])) {
                    foreach ($rolegroups[$type][$user_key] as $rolegroup) {
                        $roleGroupUserOrg = new RoleGroupUserOrganization;
                        $roleGroupUserOrg->role_group_id = $rolegroup;
                        $roleGroupUserOrg->role_group_user_org_type = $type;
                        $roleGroupUserOrg->role_group_target_id = $model->id;
                        $roleGroupUserOrg->save();
                    }
                }
            }
        }

        return $values['user'];
    }

    protected function createTables($users){
        // create test table
        $permissions = [
            Permission::CUSTOM_VALUE_EDIT_ALL,
            Permission::CUSTOM_VALUE_VIEW_ALL,
            Permission::CUSTOM_VALUE_ACCESS_ALL,
            Permission::CUSTOM_VALUE_EDIT,
            Permission::CUSTOM_VALUE_VIEW,
        ];

        $tables = [];
        foreach($permissions as $permission){
            $custom_table = $this->createTable('roletest_' . $permission, $users);
            $tables[$permission] = $custom_table;
        }

        // NO permission
        $this->createTable('no_permission', $users);

        return $tables;
    }

    protected function createTable($keyName, $users){
        // create table
        $custom_table = new CustomTable;
        $custom_table->table_name = $keyName;
        $custom_table->table_view_name = $keyName;

        $custom_table->save();

        $custom_column = new CustomColumn;
        $custom_column->custom_table_id = $custom_table->id;
        $custom_column->column_name = 'text';
        $custom_column->column_view_name = 'text';
        $custom_column->column_type = ColumnType::TEXT;
        $custom_column->options = ['required' => '1'];
        $custom_column->save();

        $custom_column2 = new CustomColumn;
        $custom_column2->custom_table_id = $custom_table->id;
        $custom_column2->column_name = 'user';
        $custom_column2->column_view_name = 'ユーザー';
        $custom_column2->column_type = ColumnType::USER;
        $custom_column2->options = ['index_enabled' => '1'];
        $custom_column2->save();

        $custom_column3 = new CustomColumn;
        $custom_column3->custom_table_id = $custom_table->id;
        $custom_column3->column_name = 'index_text';
        $custom_column3->column_view_name = 'index_text';
        $custom_column3->column_type = ColumnType::TEXT;
        $custom_column3->options = ['index_enabled' => '1'];
        $custom_column3->save();

        $custom_form_conditions = [
            [
                'condition_type' => ConditionType::CONDITION,
                'condition_key' => 1,
                'target_column_id' => ConditionTypeDetail::ORGANIZATION,
                'condition_value' => ["2"], // dev
            ], 
            []
        ];

        foreach($custom_form_conditions as $index => $condition){
            // create form
            $custom_form = new CustomForm;
            $custom_form->custom_table_id = $custom_table->id;
            $custom_form->form_view_name = ($index === 1 ? 'form_default' : 'form');
            $custom_form->default_flg = ($index === 1);
            $custom_form->save();
        
            if(count($condition) == 0){
                continue;
            }
            
            $custom_form_priority = new CustomFormPriority;
            $custom_form_priority->custom_form_id = $custom_form->id;
            $custom_form_priority->order = $index + 1;
            $custom_form_priority->save();

            $custom_form_condition = new Condition;
            $custom_form_condition->morph_type = 'custom_form_priority';
            $custom_form_condition->morph_id = $custom_form_priority->id;
            foreach($condition as $k => $c){
                $custom_form_condition->{$k} = $c;
            }
            $custom_form_condition->save();
        }

        $notify_id = $this->createNotify($custom_table);

        System::custom_value_save_autoshare(CustomValueAutoShare::USER_ORGANIZATION);
        foreach($users as $key => $user){
            \Auth::guard('admin')->attempt([
                'username' => $key,
                'password' => array_get($user, 'password')
            ]);

            $id = array_get($user, 'id');

            for ($i = 1; $i <= 10; $i++) {
                $custom_value = $custom_table->getValueModel();
                $custom_value->setValue("text", 'test_'.$id);
                $custom_value->setValue("user", $id);
                $custom_value->setValue("index_text", 'index_'.$id.'_'.$i);
                $custom_value->created_user_id = $id;
                $custom_value->updated_user_id = $id;
    
                $custom_value->save();

                $this->createNotifyNavbar($custom_table, $notify_id, $custom_value);
            }
        }

        return $custom_table;
    }   

    protected function createPermission($custom_tables){
        foreach($custom_tables as $permission => $custom_table){
            $roleGroupPermission = new RoleGroupPermission;
            $roleGroupPermission->role_group_id = 4;
            $roleGroupPermission->role_group_permission_type = 1;
            $roleGroupPermission->role_group_target_id = $custom_table->id;
            $roleGroupPermission->permissions = [$permission];
            $roleGroupPermission->save();
        }
    }
    
    /**
     * Create Notify
     *
     * @return void
     */
    protected function createNotify($custom_table){
        $notify = new Notify;
        $notify->notify_view_name = $custom_table->table_name . '_notify';
        $notify->custom_table_id = $custom_table->id;
        $notify->notify_trigger = 2;
        $notify->action_settings = [
            "notify_action_target" => ["created_user"],
            "mail_template_id" => "6"];
        $notify->save();
        return $notify->id;
    }
    
    /**
     * Create Notify Navibar
     *
     * @return void
     */
    protected function createNotifyNavbar($custom_table, $notify_id, $custom_value){
        $notify_navbar = new NotifyNavbar;
        $notify_navbar->notify_id = $notify_id;
        $notify_navbar->parent_type = $custom_table->table_name;
        $notify_navbar->parent_id = $custom_value->id;
        $notify_navbar->target_user_id = $custom_value->created_user_id;
        $notify_navbar->trigger_user_id = 1;
        $notify_navbar->read_flg = rand(0, 1);
        $notify_navbar->notify_subject = 'notify subject test';
        $notify_navbar->notify_body = 'notify body test';
        $notify_navbar->save();
    }
    
    /**
     * Create Workflow
     *
     * @return void
     */
    protected function createWorkflow($users){
        // create workflows
        $statuses = [
            [
                'status_name' => 'middle',
                'datalock_flg' => 1,
            ],
            [
                'status_name' => 'end',
                'datalock_flg' => 1,
            ],
        ];


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
                        'datalock_flg' => 1,
                    ],
                    [
                        'status_name' => 'end',
                        'datalock_flg' => 1,
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
                        'action_name' => 'end_action',
    
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
                                'related_id' => 6, // dev-userB
                                'related_type' => 'user',    
                            ]
                        ],
                    ],
                ],
    
                'tables' => [
                    [
                        'custom_table' => 'roletest_custom_value_edit_all',
                    ],
                ],
            ],
        ];


        foreach($workflows as $workflow){
            $workflowObj = new Workflow;
            foreach($workflow['items'] as $key => $item){
                $workflowObj->{$key} = $item;
            }
            $workflowObj->start_status_name = 'start';
            $workflowObj->save();

            foreach($workflow['statuses'] as $index => &$status){
                $workflowstatus = new WorkflowStatus;
                $workflowstatus->workflow_id = $workflowObj->id;

                foreach($status as $key => $item){
                    $workflowstatus->{$key} = $item;
                }
                $workflowstatus->order = $index;

                $workflowstatus->save();
                $status['id'] = $workflowstatus->id;
                $status['index'] = $index;
            }
            
            $actionStatusFromTos = [];
            foreach($workflow['actions'] as &$action){
                $actionStatusFromTo = [];

                $workflowaction = new WorkflowAction;
                $workflowaction->workflow_id = $workflowObj->id;
                $workflowaction->action_name = $action['action_name'];

                if($action['status_from'] === 'start'){
                    $workflowaction->status_from = $action['status_from'];
                    $actionStatusFromTo['status_from'] = null;
                }else{
                    $workflowaction->status_from = $workflow['statuses'][$action['status_from']]['id'];
                    $actionStatusFromTo['status_from'] = $workflowaction->status_from;
                }

                foreach($action['options'] as $key => $item){
                    $workflowaction->setOption($key, $item);
                }
                $workflowaction->save();
                $action['id'] = $workflowaction->id;

                foreach($action['authorities'] as $key => $item){
                    $item['workflow_action_id'] = $workflowaction->id;
                    WorkflowAuthority::insert($item);
                }
                
                foreach($action['condition_headers'] as $key => $item){
                    $header = new WorkflowConditionHeader;
                    $header->enabled_flg = $item['enabled_flg'];
                    $header->workflow_action_id = $workflowaction->id;

                    if ($item['status_to'] === 'start') {
                        $header->status_to = $item['status_to'];
                        $actionStatusFromTo['status_to'] = null;
                    }else{
                        $header->status_to = $workflow['statuses'][$item['status_to']]['id'];
                        $actionStatusFromTo['status_to'] = $header->status_to;
                    }

                    $header->save();
                }

                $actionStatusFromTo['workflow_action_id'] = $workflowaction->id;
                $actionStatusFromTos[] = $actionStatusFromTo;
            }

            foreach ($workflow['tables'] as &$table) {
                $wfTable = new WorkflowTable;
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
                    'dev-userB',
                ];

                foreach($userKeys as $userKey){
                    foreach($wfValueStatuses as $index => $wfValueStatus){
                        $user = $users[$userKey];
                        \Auth::guard('admin')->attempt([
                            'username' => array_get($user, 'value.user_code'),
                            'password' => array_get($user, 'password')
                        ]);
                
                        $custom_value = CustomTable::getEloquent($table['custom_table'])->getValueModel();
                        $custom_value->setValue("text", "test_$index");
                        $custom_value->setValue("index_text", "index_$index");
                        $custom_value->id = 1000 + $index;
                        $custom_value->save();
    
                        if(!isset($wfValueStatus['id']) || $index == 0){
                            continue;
                        }
    
                        // get target $actionStatusFromTo
                        $actionStatusFromTo = collect($actionStatusFromTos)->first(function($actionStatusFromTo) use($wfValueStatuses, $index){
                            return $wfValueStatuses[$index - 1]['id'] == $actionStatusFromTo['status_from'] && $wfValueStatuses[$index]['id'] == $actionStatusFromTo['status_to'];
                        });
                        if(!isset($actionStatusFromTo)){
                            continue;
                        }
    
                        $user = $users[$wfUserKeys[$index - 1]];
                        \Auth::guard('admin')->attempt([
                            'username' => array_get($user, 'value.user_code'),
                            'password' => array_get($user, 'password')
                        ]);
            
                        $wfValue = new WorkflowValue;
                        $wfValue->workflow_id = $workflowObj->id;
                        $wfValue->morph_type = $table['custom_table'];
                        $wfValue->morph_id = $custom_value->id;
                        $wfValue->workflow_action_id = $actionStatusFromTo['workflow_action_id'];
                        $wfValue->workflow_status_from_id = $actionStatusFromTo['status_from'];
                        $wfValue->workflow_status_to_id = $actionStatusFromTo['status_to'];
                        $wfValue->action_executed_flg = 0;
                        $wfValue->latest_flg = count($wfValueStatuses) - 1 === $index;

                        $wfValue->save();
                    }
                }
            }
        }
    }
}
