<?php
namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Encore\Admin\Widgets\Form as WidgetForm;

/**
 * 
 */
class DatabaseForm
{
    use InstallFormTrait;

    protected const settings = [
        'connection',
        'host',
        'port',
        'database',
        'username',
        'password',
    ];
    
    public function index(){

        $database_default = config('database.default', 'mysql');
        $database_connection = config("database.connections.$database_default");

        return view('exment::install.database', [
            'connection_options' => ['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'sqlsrv' => 'SQL Server (β)'],
            'connection_default' => $database_default,
            'database_connection' => $database_connection,
        ]);

        // $form = new WidgetForm();
        // $form->disableReset();
        // $form->disablePjax();
        // $form->action(admin_url('initialize'));


        // $form->header('データベース設定');

        // $form->select('connection', '種類')
        //     ->required()
        //     ->config('allowClear', false)
        //     ->options(['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'sqlsrv' => 'SQL Server (β)'])
        //     ->default($database_default);

        // $form->text('host', 'ホスト')
        //     ->required()
        //     ->default(array_get($database_connection, 'host'));
            
        // $form->text('port', 'ポート')
        //     ->required()
        //     ->default(array_get($database_connection, 'port'));

        // $form->text('database', 'データベース名')
        //     ->required()
        //     ->default(array_get($database_connection, 'database'));
        
        // $form->text('username', 'ユーザー名')
        //     ->required()
        //     ->default(array_get($database_connection, 'username'));

        // $form->text('password', 'パスワード')
        //     ->required()
        //     ->default(array_get($database_connection, 'password'));

    }
    
    public function post(){
        $request = request();

        $rules = [];
        foreach(static::settings as $s){
            $rules[$s] = 'required';
        }
        
        $validation = \Validator::make($request->all(), $rules);
        if ($validation->fails()) {
            return back()->withInput()->withErrors($validation);
        }

        if(!$this->canDatabaseConnection($request)){
            return back()->withInput();
        }
        
        $inputs = [];
        foreach(static::settings as $s){
            $inputs['DB_' . strtoupper($s)] = $request->get($s);
        }
        $inputs[Define::ENV_EXMENT_INITIALIZE] = 1;

        $this->setEnv($inputs);

        \Artisan::call('config:clear');

        return redirect(admin_url('install'));
    }

    protected function canDatabaseConnection($request){
        $inputs = $request->all(static::settings);
        // check connection
        $database_default = $inputs['connection'];

        $newConfig = config("database.connections.$database_default");
        $newConfig = array_merge($newConfig, $inputs);

        // set config
        config(["database.connections.$database_default" =>  $newConfig]);
        \DB::reconnect($database_default);

        return \DB::canConnection();
    }
}
