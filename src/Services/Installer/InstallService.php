<?php

namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\InitializeStatus;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;

/**
 *
 */
class InstallService
{
    public static function index()
    {
        static::setSettingTmp();
        $status = static::getStatus();

        if (($response = static::redirect($status)) instanceof \Illuminate\Http\RedirectResponse) {
            return $response;
        }

        $form = static::getForm($status);

        return $form->index();
    }

    public static function post()
    {
        static::setSettingTmp();
        $status = static::getStatus();

        if (($response = static::redirect($status)) instanceof \Illuminate\Http\RedirectResponse) {
            return $response;
        }

        $form = static::getForm($status);

        return $form->post();
    }

    public static function getStatus()
    {
        static::setSettingTmp();

        if (\ExmentDB::canConnection() && \Schema::hasTable(SystemTableName::SYSTEM) && CustomTable::count() > 0) {
            return InitializeStatus::INITIALIZE;
        }

        if (is_null($status = static::getInitializeStatus())) {
            return InitializeStatus::LANG;
        }

        // if (!\ExmentDB::canConnection()) {
        //     return InitializeStatus::DATABASE;
        // }

        if ($status == InitializeStatus::LANG) {
            return InitializeStatus::DATABASE;
        }

        if ($status == InitializeStatus::DATABASE) {
            return InitializeStatus::SYSTEM_REQUIRE;
        }

        if (!\ExmentDB::canConnection() || !\Schema::hasTable(SystemTableName::SYSTEM) || CustomTable::count() == 0) {
            return InitializeStatus::INSTALLING;
        }

        if ($status == InitializeStatus::SYSTEM_REQUIRE) {
            return InitializeStatus::INSTALLING;
        }

        static::forgetInitializeStatus();

        return InitializeStatus::INITIALIZE;
    }

    public static function redirect($status)
    {
        $isInstallPath = collect(explode('/', request()->getRequestUri()))->last() == 'install';
        switch ($status) {
            case InitializeStatus::LANG:
                if (!$isInstallPath) {
                    return redirect(admin_url('install'));
                }
                return new LangForm();
            case InitializeStatus::DATABASE:
                if (!$isInstallPath) {
                    return redirect(admin_url('install'));
                }
                return new DatabaseForm();
            case InitializeStatus::SYSTEM_REQUIRE:
                if (!$isInstallPath) {
                    return redirect(admin_url('install'));
                }
                return new SystemRequireForm();
            case InitializeStatus::INSTALLING:
                if (!$isInstallPath) {
                    return redirect(admin_url('install'));
                }
                return new InstallingForm();
            case InitializeStatus::INITIALIZE:
                if ($isInstallPath) {
                    return redirect(admin_url('initialize'));
                }
                return new InitializeForm();
        }
    }

    public static function getForm($status)
    {
        switch ($status) {
            case InitializeStatus::LANG:
                return new LangForm();
            case InitializeStatus::DATABASE:
                return new DatabaseForm();
            case InitializeStatus::SYSTEM_REQUIRE:
                return new SystemRequireForm();
            case InitializeStatus::INSTALLING:
                return new InstallingForm();
            case InitializeStatus::INITIALIZE:
                return new InitializeForm();
        }

        return new InitializeForm();
    }

    public static function getInitializeStatus()
    {
        return session(Define::SYSTEM_KEY_SESSION_INITIALIZE);
    }

    public static function setInitializeStatus($status)
    {
        session([Define::SYSTEM_KEY_SESSION_INITIALIZE => $status]);
    }

    public static function forgetInitializeStatus()
    {
        session()->forget(Define::SYSTEM_KEY_SESSION_INITIALIZE);
    }



    public static function setInputParams(array $inputs)
    {
        $session_inputs = session(Define::SYSTEM_KEY_SESSION_INITIALIZE_INPUTS, []);
        foreach ($inputs as $key => $input) {
            $session_inputs[$key] = $input;
        }
        session([Define::SYSTEM_KEY_SESSION_INITIALIZE_INPUTS => $session_inputs]);
    }

    public static function getInputParams(): array
    {
        return session(Define::SYSTEM_KEY_SESSION_INITIALIZE_INPUTS, []);
    }

    public static function forgetInputParams()
    {
        session()->forget(Define::SYSTEM_KEY_SESSION_INITIALIZE_INPUTS);
    }


    /**
     * input parameters info tmp
     *
     * @return void
     */
    public static function setSettingTmp()
    {
        $inputs = static::getInputParams();
        //// set from env
        if (!is_null($env = array_get($inputs, 'APP_LOCALE'))) {
            \App::setLocale($env);
        }
        if (!is_null($env = array_get($inputs, 'APP_TIMEZONE'))) {
            \Config::set('app.timezone', $env);
            date_default_timezone_set($env);
        }

        ////// set db setting
        // set database.default
        $val = array_get($inputs, 'DB_CONNECTION');
        if (!is_nullorempty($val)) {
            \Config::set("database.default", $val);
        }
        $database_default = config('database.default', 'mysql');
        $config_keyname = "database.connections.$database_default";
        $database_connection = config($config_keyname);
        $hasDbSetting = false;

        foreach (DatabaseForm::settings as $s) {
            $db_input = array_get($inputs, 'DB_' . strtoupper($s));
            if (\is_nullorempty($db_input)) {
                continue;
            }
            \Config::set("{$config_keyname}.{$s}", $db_input);
            $hasDbSetting = true;
        }

        if ($hasDbSetting) {
            try {
                \DB::reconnect();
            } catch (\Exception $ex) {
            }
        }
    }
}
