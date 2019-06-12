<?php
namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\DatabaseType;
use Exceedone\Exment\Enums\InitializeStatus;

/**
 * 
 */
class DatabaseForm
{
    use InstallFormTrait;

    protected $database_default = null;

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

        $args = [
            'connection_options' => Define::DATABASE_TYPE,
            'connection_default' => $database_default,
        ];

        foreach(static::settings as $s){
            $args[$s] = array_get($database_connection, $s);
        }

        return view('exment::install.database', $args);
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
            return back()->withInput()->withErrors([
                'database_canconnection' => exmtrans('install.error.database_canconnection'),
            ]);
        }
        
        if(($result = $this->checkDatabaseMatch()) !== true){
            return back()->withInput()->withErrors([
                'database_canconnection' => $result,
            ]);
        }
        
        if(($result = $this->checkDatabaseVersion()) !== true){
            return back()->withInput()->withErrors([
                'database_canconnection' => $result,
            ]);
        }

        $inputs = [];
        foreach(static::settings as $s){
            $inputs['DB_' . strtoupper($s)] = $request->get($s);
        }

        $this->setEnv($inputs);

        InstallService::setInitializeStatus(InitializeStatus::DATABASE);

        \Artisan::call('cache:clear');
        \Artisan::call('config:clear');

        return redirect(admin_url('install'));
    }

    /**
     * Check Database Connection
     *
     * @param [type] $request
     * @return boolean is connect database
     */
    protected function canDatabaseConnection($request){
        $inputs = $request->all(static::settings);
        // check connection
        $database_default = $inputs['connection'];
        $this->database_default = $database_default;

        $newConfig = config("database.connections.$database_default");
        $newConfig = array_merge($newConfig, $inputs);

        // set config
        config(["database.connections.$database_default" => $newConfig]);

        try{
            $this->connection()->disconnect();
            $this->connection()->reconnect();
        }
        catch (\Exception $exception) {
            return false;
        }

        return $this->connection()->canConnection();
    }

    /**
     * Check database minimum version.
     *
     * @return void
     */
    protected function checkDatabaseVersion(){
        $version = $this->connection()->getVersion();

        if(version_compare($version, Define::DATABASE_MIN_VERSION[$this->database_default]) >= 0){
            return true;
        }

        return exmtrans('install.error.not_require_database_version', Define::DATABASE_TYPE[$this->database_default], Define::DATABASE_MIN_VERSION[$this->database_default], $version);
    }
    
    /**
     * Check database mariadb and mysql mistake/
     *
     * @return void
     */
    protected function checkDatabaseMatch(){
        switch($this->database_default){
            case DatabaseType::SQLSRV:
                return true;
            case DatabaseType::MYSQL:
                if(!$this->connection()->isMariaDB() === true){
                    return true;
                }
                return exmtrans('install.error.mistake_mysql_mariadb', Define::DATABASE_TYPE[DatabaseType::MARIADB], Define::DATABASE_TYPE[DatabaseType::MYSQL]);
            case DatabaseType::MARIADB:
                if($this->connection()->isMariaDB() === true){
                    return true;
                }
                return exmtrans('install.error.mistake_mysql_mariadb', Define::DATABASE_TYPE[DatabaseType::MYSQL], Define::DATABASE_TYPE[DatabaseType::MARIADB]);
        }

        return false;
    }

    protected function connection(){
        return \DB::connection($this->database_default);
    }
}
