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
    use EnvTrait;

    protected $database_default = null;

    public const settings = [
        'connection',
        'host',
        'port',
        'database',
        'username',
        'password',
    ];

    public function index()
    {
        $database_default = config('database.default', 'mysql');
        $database_connection = config("database.connections.$database_default");

        $args = [
            'connection_options' => Define::DATABASE_TYPE,
            'connection_default' => $database_default,
        ];

        foreach (static::settings as $s) {
            $args[$s] = array_get($database_connection, $s);
        }

        return view('exment::install.database', $args);
    }

    public function post()
    {
        $request = request();

        $rules = [];
        foreach (static::settings as $s) {
            if ($s == 'password') {
                continue;
            }

            $rules[$s] = 'required';
        }

        $validation = \Validator::make($request->all(), $rules);
        if ($validation->fails()) {
            return back()->withInput()->withErrors($validation);
        }

        if (($result = $this->checkPhpVersion()) !== true) {
            return back()->withInput()->withErrors([
                'database_canconnection' => $result,
            ]);
        }

        if (!$this->canDatabaseConnection($request)) {
            return back()->withInput()->withErrors([
                'database_canconnection' => exmtrans('install.error.database_canconnection'),
            ]);
        }

        if (($result = $this->checkDatabaseMatch()) !== true) {
            return back()->withInput()->withErrors([
                'database_canconnection' => $result,
            ]);
        }

        if (($result = $this->checkDatabaseVersion()) !== true) {
            return back()->withInput()->withErrors([
                'database_canconnection' => $result,
            ]);
        }

        $inputs = [];
        foreach (static::settings as $s) {
            $inputs['DB_' . strtoupper($s)] = $request->get($s);
        }


        // try {
        //     $this->setEnv($inputs);
        // } catch (\Exception $ex) {
        //     return back()->withInput()->withErrors([
        //         'database_canconnection' => exmtrans('install.error.cannot_write_env'),
        //     ]);
        // }

        InstallService::setInputParams($inputs);
        InstallService::setInitializeStatus(InitializeStatus::DATABASE);

        // \Artisan::call('cache:clear');
        // \Artisan::call('config:clear');

        return redirect(admin_url('install'));
    }

    /**
     * Check Database Connection
     *
     * @param \Illuminate\Http\Request $request
     * @return boolean is connect database
     */
    protected function canDatabaseConnection($request)
    {
        $inputs = $request->all(static::settings);
        // check connection
        $database_default = $inputs['connection'];
        $this->database_default = $database_default;

        $newConfig = config("database.connections.$database_default");
        $newConfig = array_merge($newConfig, $inputs);

        // set config
        config(["database.connections.$database_default" => $newConfig]);

        try {
            $this->connection()->disconnect();
            $this->connection()->reconnect();
        } catch (\Exception $exception) {
            return false;
        }

        return $this->connection()->canConnection();
    }

    /**
     * Check database minimum version.
     *
     * @return bool|string if true, success, if false, return message.
     */
    protected function checkDatabaseVersion()
    {
        $version = $this->connection()->getVersion();

        $database_version = Define::DATABASE_VERSION[$this->database_default];

        $result = true;
        $message_lt = false;
        // check min
        if (version_compare($version, $database_version['min']) < 0) {
            $result = false;
        }

        // check max(less than)
        if (array_has($database_version, 'max_lt')) {
            $message_lt = true;
            if (version_compare($version, $database_version['max_lt']) >= 0) {
                $result = false;
            }
        }

        if ($result) {
            return true;
        }

        $errorMessage = exmtrans('install.error.not_require_database_version_' . ($message_lt ? 'min_maxlt' : 'min'), [
            'min' => $database_version['min'],
            'max_lt' => ($message_lt ? $database_version['max_lt'] : null),
            'database' => Define::DATABASE_TYPE[$this->database_default],
            'current' => $version
        ]);

        return $errorMessage;
    }

    /**
     * Check database mariadb and mysql mistake/
     *
     * @return bool|string if true, success, if false, return message.
     */
    protected function checkDatabaseMatch()
    {
        switch ($this->database_default) {
            case DatabaseType::SQLSRV:
                return true;
            case DatabaseType::MYSQL:
                if (!$this->connection()->isMariaDB() === true) {
                    return true;
                }
                $current = Define::DATABASE_TYPE[DatabaseType::MARIADB];
                $select = Define::DATABASE_TYPE[DatabaseType::MYSQL];
                break;
            case DatabaseType::MARIADB:
                if ($this->connection()->isMariaDB() === true) {
                    return true;
                }
                $current = Define::DATABASE_TYPE[DatabaseType::MYSQL];
                $select = Define::DATABASE_TYPE[DatabaseType::MARIADB];
                break;
        }
        return exmtrans('install.error.mistake_mysql_mariadb', [
            'database' => $current ?? null,
            'database_select' => $select ?? null,
        ]);
    }

    /**
     * Check PHP version
     *
     * @return bool|string if true, success, if false, return message.
     */
    protected function checkPhpVersion()
    {
        $version = phpversion();

        $errorMessage = exmtrans('install.error.not_require_php_version', [
            'min' => Define::PHP_VERSION[0],
            'max' => Define::PHP_VERSION[1],
            'current' => $version
        ]);
        if (version_compare($version, Define::PHP_VERSION[0]) < 0) {
            return $errorMessage;
        }
        if (version_compare($version, Define::PHP_VERSION[1]) >= 0) {
            return $errorMessage;
        }
        return true;
    }

    protected function connection()
    {
        return \DB::connection($this->database_default);
    }
}
