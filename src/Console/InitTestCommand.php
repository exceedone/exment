<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
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
       
        $custom_tables = $this->createTables();

        $this->createUserOrg($custom_tables);

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

    protected function createUserOrg($custom_tables){
        // set users
        $users = [
            'admin' => [
                'value' => [
                    'user_name' => 'admin',
                    'user_code' => 'admin',
                    'email' => 'admin@admin.foobar.test',
                ],
                'password' => 'adminadmin',
                'role_groups' => [],
            ],
            'user1' => [
                'value' => [
                    'user_name' => 'user1',
                    'user_code' => 'user1',
                    'email' => 'user1@user.foobar.test',    
                ],
                'password' => 'user1user1',
                'role_groups' => [],
            ],
            'user2' => [
                'value' => [
                    'user_name' => 'user2',
                    'user_code' => 'user2',
                    'email' => 'user2@user.foobar.test',
                ],
                'password' => 'user2user2',
                'role_groups' => [],
            ],
        ];

        // set rolegroups
        $rolegroups = [
            'user1' => [1], //data_admin_group
            'user2' => [4], //user_group
        ];

        foreach($users as $user_key => $user){
            $model = CustomTable::getEloquent('user')->getValueModel();
            foreach($user['value'] as $key => $value){
                $model->setValue($key, $value);
            }

            $model->save();

            $loginUser = new LoginUser;
            $loginUser->base_user_id = $model->id;
            $loginUser->password = $user['password'];
            $loginUser->save();

            if (isset($rolegroups[$user_key]) && is_array($rolegroups[$user_key])) {
                foreach ($rolegroups[$user_key] as $rolegroup) {
                    $roleGroupUserOrg = new RoleGroupUserOrganization;
                    $roleGroupUserOrg->role_group_id = $rolegroup;
                    $roleGroupUserOrg->role_group_user_org_type = 'user';
                    $roleGroupUserOrg->role_group_target_id = $model->id;
                    $roleGroupUserOrg->save();
                }
            }
        }

        foreach($custom_tables as $permission => $custom_table){
            $roleGroupPermission = new RoleGroupPermission;
            $roleGroupPermission->role_group_id = 4;
            $roleGroupPermission->role_group_permission_type = 1;
            $roleGroupPermission->role_group_target_id = $custom_table->id;
            $roleGroupPermission->permissions = [$permission];
            $roleGroupPermission->save();
        }
    }

    protected function createTables(){
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
            $custom_table = $this->createTable('roletest_' . $permission);
            $tables[$permission] = $custom_table;
        }

        // NO permission
        $this->createTable('no_permission');

        return $tables;
    }

    protected function createTable($keyName){
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

        foreach(range(0, 10) as $index){
            $custom_value = $custom_table->getValueModel();
            $custom_value->setValue("test_$index");

            $custom_value->save();
        }

        return $custom_table;
    }   
}
