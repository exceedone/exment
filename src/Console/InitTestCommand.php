<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Enums\BackupTarget;
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
        
        
        // create system data
        $systems = [
            'initialized' => true,
            'system_admin_users' => [1],
            'api_available' => true,
        ];
        foreach($systems as $key => $value){
            System::{$key}($value);
        }

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
            'user1' => [1],
            'user2' => [4],
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
        

        // TODO:add role group


        // init api
        $clientRepository = new ClientRepository;
        $client = $clientRepository->createPasswordGrantClient(
            1,
            Define::API_FEATURE_TEST,
            'http://localhost'
        );
    }
}
