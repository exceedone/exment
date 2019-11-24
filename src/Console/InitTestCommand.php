<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Enums\CustomValueAutoShare;
use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
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
        $custom_table = new CustomTable;
        $custom_table->table_name = $keyName;
        $custom_table->table_view_name = $keyName;

        $custom_table->save();

        $custom_column = new CustomColumn;
        $custom_column->custom_table_id = $custom_table->id;
        $custom_column->column_name = 'text';
        $custom_column->column_view_name = 'text';
        $custom_column->column_type = ColumnType::TEXT;

        $custom_column->save();

        System::custom_value_save_autoshare(CustomValueAutoShare::USER_ORGANIZATION);
        foreach($users as $key => $user){
            \Auth::guard('admin')->attempt([
                'username' => $key,
                'password' => array_get($user, 'password')
            ]);

            $id = array_get($user, 'id');
            $custom_value = $custom_table->getValueModel();
            $custom_value->setValue("text", "test_$id");
            $custom_value->created_user_id = $id;
            $custom_value->updated_user_id = $id;

            $custom_value->save();
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
    
    protected function createWorkflow(){

    }
}
