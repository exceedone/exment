<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Encore\Admin\Grid\Filter;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\EnumBase;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\ColumnItems\FormOthers;
use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\ColumnItems\CustomColumns;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Services\PartialCrudService;
use Encore\Admin\Form;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use \Html;
use PDO;

/**
 * Middleware as Initialize.
 * First call. set config for exment, and set from database.
 */
class Initialize
{
    public function handle(Request $request, \Closure $next)
    {
        if (!canConnection() || !hasTable(SystemTableName::SYSTEM)) {
            $path = trim(admin_base_path('install'), '/');
            if (!$request->is($path)) {
                return redirect()->guest(admin_base_path('install'));
            }
            static::initializeConfig(false);
        } else {
            $initialized = System::initialized();

            // if path is not "initialize" and not installed, then redirect to initialize
            if (!shouldPassThrough(true) && !$initialized) {
                $request->session()->invalidate();
                return redirect()->guest(admin_base_path('initialize'));
            }
            // if path is "initialize" and installed, redirect to login
            elseif (shouldPassThrough(true) && $initialized) {
                return redirect()->guest(admin_base_path('auth/login'));
            }
    
            static::initializeConfig();
        }
        
        static::requireBootstrap();

        return $next($request);
    }

    public static function initializeConfig($setDatabase = true)
    {
        //// set from env
        if (!is_null($env = config('exment.locale', env('APP_LOCALE')))) {
            \App::setLocale($env);
        }
        if (!is_null($env = config('exment.timezone', env('APP_TIMEZONE')))) {
            Config::set('app.timezone', $env);
            date_default_timezone_set($env);
        }


        ///// set config

        // for password reset
        if (!Config::has('auth.passwords.exment_admins')) {
            Config::set('auth.passwords.exment_admins', [
                'provider' => 'exment-auth',
                'table' => 'password_resets',
                'expire' => 720,
            ]);
        }
        if (!Config::has('auth.providers.exment-auth')) {
            Config::set('auth.providers.exment-auth', [
                'driver' => 'eloquent',
                'model' => \Exceedone\Exment\Model\LoginUser::class,
            ]);
        }

        // for api auth
        Config::set('auth.defaults.guard', 'admin');
        Config::set('auth.guards.adminapi', [
            'driver' => 'passport',
            'provider' => 'exment-auth',
        ]);
        Config::set('auth.guards.api', [
            'driver' => 'passport',
            'provider' => 'exment-auth',
        ]);

        // for login
        Config::set('auth.guards.admin.provider', 'exment-auth-login');
        Config::set('auth.providers.exment-auth-login', [
            'driver' => 'exment-auth',
        ]);
    

        ///// File info
        /// maybe update setting by user
        if (!Config::has('filesystems.disks.exment')) {
            Config::set('filesystems.disks.exment', [
                'driver' => 'local',
                'root' => storage_path('app/admin'),
                'url' => admin_url(),
            ]);
        }
        
        if (!Config::has('filesystems.disks.backup')) {
            Config::set('filesystems.disks.backup', [
                'driver' => 'local',
                'root' => storage_path('app/backup'),
            ]);
        }

        if (!Config::has('filesystems.disks.plugin')) {
            Config::set('filesystems.disks.plugin', [
                'driver' => 'local',
                'root' => storage_path('app/plugins'),
            ]);
        }
        
        if (!Config::has('filesystems.disks.template')) {
            Config::set('filesystems.disks.template', [
                'driver' => 'local',
                'root' => storage_path('app/templates'),
            ]);
        }


        /// only set by system
        Config::set('filesystems.disks.admin_tmp', [
            'driver' => 'local',
            'root' => storage_path('app/admin_tmp'),
        ]);

        Config::set('filesystems.disks.admin', [
            'driver' => 'exment-driver-exment',
            'mergeFrom' => 'exment',
        ]);
        
        Config::set('filesystems.disks.plugin_sync', [
            'driver' => 'exment-driver-plugin',
            'mergeFrom' => 'plugin',
            'root' => storage_path('app/plugins'),
        ]);

        Config::set('filesystems.disks.backup_sync', [
            'driver' => 'exment-driver-backup',
            'mergeFrom' => 'backup',
            'root' => storage_path('app/backup'),
        ]);
        
        Config::set('filesystems.disks.template_sync', [
            'driver' => 'exment-driver-template',
            'mergeFrom' => 'template',
            'root' => storage_path('app/templates'),
        ]);


        Config::set('filesystems.disks.plugin_local', [
            'driver' => 'local',
            'root' => storage_path('app/plugins'),
        ]);

        // mysql setting
        Config::set('database.connections.mysql.strict', false);
        Config::set('database.connections.mysql.options', [
            PDO::ATTR_CASE => PDO::CASE_LOWER,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::MYSQL_ATTR_LOCAL_INFILE => true,
        ]);

        // mariadb setting
        Config::set('database.connections.mariadb', Config::get('database.connections.mysql'));
        Config::set('database.connections.mariadb.driver', 'mariadb');

        //override
        Config::set('admin.database.menu_model', \Exceedone\Exment\Model\Menu::class);
        Config::set('admin.database.users_table', \Exceedone\Exment\Model\LoginUser::getTableName());
        Config::set('admin.database.users_model', \Exceedone\Exment\Model\LoginUser::class);
        Config::set('admin.enable_default_breadcrumb', false);
        Config::set('session.show_environment', false);


        ///// set Exment-item class
        $map = [
            'auto_number'        => CustomColumns\AutoNumber::class,
            'boolean'        => CustomColumns\Boolean::class,
            'currency'        => CustomColumns\Currency::class,
            'date'        => CustomColumns\Date::class,
            'datetime'        => CustomColumns\Datetime::class,
            'decimal'        => CustomColumns\Decimal::class,
            'editor'        => CustomColumns\Editor::class,
            'email'        => CustomColumns\Email::class,
            'file'        => CustomColumns\File::class,
            'hidden'        => CustomColumns\Hidden::class,
            'image'        => CustomColumns\Image::class,
            'integer'        => CustomColumns\Integer::class,
            'organization'        => CustomColumns\Organization::class,
            'select'        => CustomColumns\Select::class,
            'select_table'        => CustomColumns\SelectTable::class,
            'select_valtext'        => CustomColumns\SelectValtext::class,
            'text'        => CustomColumns\Text::class,
            'textarea'        => CustomColumns\Textarea::class,
            'time'        => CustomColumns\Time::class,
            'url'        => CustomColumns\Url::class,
            'user'        => CustomColumns\User::class,
            'yesno'        => CustomColumns\Yesno::class,
        ];
        foreach ($map as $abstract => $class) {
            CustomItem::extend($abstract, $class);
        }

        ///// set Exment-item class
        $map = [
            'header'        => FormOthers\Header::class,
            'explain'        => FormOthers\Explain::class,
            'html'        => FormOthers\Html::class,
            'exhtml'      => FormOthers\ExHtml::class,
        ];
        foreach ($map as $abstract => $class) {
            FormOtherItem::extend($abstract, $class);
        }

        if ($setDatabase) {
            // Set system setting to config --------------------------------------------------
            // Site Name
            $val = System::site_name();
            if (isset($val)) {
                Config::set('admin.name', $val);
                Config::set('admin.title', $val);
            }

            // Logo
            $val = System::site_logo();
            if (isset($val)) {
                Config::set('admin.logo', Html::image($val, 'header logo'));
            } else {
                $val = System::site_name();
                if (isset($val)) {
                    Config::set('admin.logo', esc_html($val));
                }
            }

            // Logo(Short)
            $val = System::site_logo_mini();
            if (isset($val)) {
                Config::set('admin.logo-mini', Html::image($val, 'header logo mini'));
            } else {
                $val = System::site_name_short();
                if (isset($val)) {
                    Config::set('admin.logo-mini', esc_html($val));
                }
            }

            // Site Skin
            $val = System::site_skin();
            if (isset($val)) {
                Config::set('admin.skin', esc_html($val));
            }

            // Site layout
            $val = System::site_layout();
            if (isset($val)) {
                Config::set('admin.layout', array_get(Define::SYSTEM_LAYOUT, $val));
            }

            // Date format
            $val = System::default_date_format();
            if (isset($val)) {
                $list = exmtrans("system.date_format_list.$val");
            }
            if (isset($list) && is_array($list) && count($list) > 2) {
                Config::set('admin.date_format', $list[0]);
                Config::set('admin.datetime_format', $list[1]);
                Config::set('admin.time_format', $list[2]);
                \Carbon\Carbon::setToStringFormat(config('admin.datetime_format'));
            } else {
                Config::set('admin.date_format', 'Y-m-d');
                Config::set('admin.datetime_format', 'Y-m-d H:i:s');
                Config::set('admin.time_format', 'H:i:s');
            }
        
            // favicon
            if (!is_null(System::site_favicon())) {
                \Admin::setFavicon(admin_url('favicon'));
            }

            // mail setting
            if (!boolval(config('exment.mail_setting_env_force', false))) {
                $keys = [
                    'system_mail_host' => 'host',
                    'system_mail_port' => 'port',
                    'system_mail_username' => 'username',
                    'system_mail_password' => 'password',
                    'system_mail_encryption' => 'encryption',
                    'system_mail_from' => ['from.address', 'from.name'],
                ];

                foreach ($keys as $keyname => $configname) {
                    if (!is_null($val = System::{$keyname}())) {
                        if (!is_array($configname)) {
                            $configname = [$configname];
                        }

                        foreach ($configname as $c) {
                            Config::set("mail.{$c}", $val);
                        }
                    }
                }
            }
        }
    }

    protected static function requireBootstrap()
    {
        $file = config('exment.bootstrap', exment_app_path('bootstrap.php'));
        if (!\File::exists($file)) {
            return;
        }
        require_once $file;
    }

    /**
     * set laravel-admin
     */
    public static function registeredLaravelAdmin()
    {
        Grid::init(function (Grid $grid) {
            $grid->disableColumnSelector();

            if (!is_null($value = System::grid_pager_count())) {
                $grid->paginate($value);
            }
        });

        Form::init(function (Form $form) {
            $form->disableEditingCheck();
            $form->disableCreatingCheck();
            $form->disableViewCheck();
            $form->disableReset();
            $form->tools(function (Form\Tools $tools) {
                $tools->disableView();
            });
        });
        Form\Footer::$defaultSubmitLabel = trans('admin.save');
        WidgetForm::$defaultSubmitLabel = trans('admin.save');

        Grid\Tools::$defaultPosition = 'right';
        Grid\Concerns\HasQuickSearch::$searchKey = 'query';
        Grid::$searchKey = 'query';

        Auth2factorService::providers('email', \Exceedone\Exment\Services\Auth2factor\Providers\Email::class);
        Auth2factorService::providers('google', \Exceedone\Exment\Services\Auth2factor\Providers\Google::class);

        PartialCrudService::providers('user_belong_organization', [
            'target_tables' => [SystemTableName::USER],
            'classname' => \Exceedone\Exment\PartialCrudItems\Providers\UserBelongOrganizationItem::class,
        ]);
        PartialCrudService::providers('user_org_role_group', [
            'target_tables' => [SystemTableName::USER, SystemTableName::ORGANIZATION],
            'classname' => \Exceedone\Exment\PartialCrudItems\Providers\UserOrgRoleGroupItem::class,
        ]);
        PartialCrudService::providers('login_user', [
            'target_tables' => [SystemTableName::USER],
            'classname' => \Exceedone\Exment\PartialCrudItems\Providers\LoginUserItem::class,
        ]);
        PartialCrudService::providers('orgazanization_tree', [
            'target_tables' => [SystemTableName::ORGANIZATION],
            'classname' => \Exceedone\Exment\PartialCrudItems\Providers\OrgazanizationTreeItem::class,
        ]);

        $map = [
            'ajaxButton'        => Field\AjaxButton::class,
            'text'          => Field\Text::class,
            'password'          => Field\Password::class,
            'encpassword'          => Field\EncPassword::class,
            'bcrpassword'          => Field\BcrPassword::class,
            'number'        => Field\Number::class,
            'tinymce'        => Field\Tinymce::class,
            'image'        => Field\Image::class,
            'display'        => Field\Display::class,
            'link'           => Field\Link::class,
            'exmheader'           => Field\Header::class,
            'description'           => Field\Description::class,
            'switchbool'          => Field\SwitchBoolField::class,
            'pivotMultiSelect'          => Field\PivotMultiSelect::class,
            'checkboxone'          => Field\Checkboxone::class,
            'checkboxTable'          => Field\CheckboxTable::class,
            'tile'          => Field\Tile::class,
            'hasMany'           => Field\HasMany::class,
            'hasManyTable'           => Field\HasManyTable::class,
            //'relationTable'          => Field\RelationTable::class,
            'embeds'          => Field\Embeds::class,
            'nestedEmbeds'          => Field\NestedEmbeds::class,
            'valueModal'          => Field\ValueModal::class,
            'changeField'          => Field\ChangeField::class,
            'progressTracker'          => Field\ProgressTracker::class,
            'systemValues'          => Field\SystemValues::class,
            
            ///// workflow
            'workflowStatusSelects'          => Field\WorkFlow\StatusSelects::class,
            'workflowOptions'          => Field\WorkFlow\Options::class,
        ];
        foreach ($map as $abstract => $class) {
            Form::extend($abstract, $class);
        }

        Show::extend('system_values', \Exceedone\Exment\Form\Show\SystemValues::class);

        Filter::extend('betweendatetime', \Exceedone\Exment\Grid\Filter\BetweenDatetime::class);
    }

    public static function logDatabase()
    {
        \DB::listen(function ($query) {
            $sql = $query->sql;
            for ($i = 0; $i < count($query->bindings); $i++) {
                $binding = $query->bindings[$i];
                if ($binding instanceof \DateTime) {
                    $binding = $binding->format('Y-m-d H:i:s');
                } elseif ($binding instanceof EnumBase) {
                    $binding = $binding->toString();
                }
                $sql = preg_replace("/\?/", "'{$binding}'", $sql, 1);
            }

            $log_string = "TIME:{$query->time}ms;    SQL: $sql";
            if (boolval(config('exment.debugmode_sqlfunction', false))) {
                $function = static::getFunctionName();
                $log_string .= ";    function: $function";
            } elseif (boolval(config('exment.debugmode_sqlfunction1', false))) {
                $function = static::getFunctionName(true);
                $log_string .= ";    function: $function";
            }

            exmDebugLog($log_string);
        });
    }

    protected static function getFunctionName($oneFunction = false)
    {
        $bt = debug_backtrace();
        $functions = [];
        $i = 0;
        foreach ($bt as $b) {
            if ($i > 1 && strpos(array_get($b, 'class'), 'Exceedone') !== false) {
                $functions[] = $b['class'] . '->' . $b['function'] . '.' . array_get($b, 'line');
            }

            if ($oneFunction && count($functions) >= 1) {
                break;
            }

            $i++;
        }
        return implode(" < ", $functions);
    }
}
