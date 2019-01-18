<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Items\CustomItem;
use Exceedone\Exment\Items\CustomColumns;
use Encore\Admin\Form;
use \Html;
use PDO;

class Initialize
{
    public function handle(Request $request, \Closure $next)
    {
        // Get System config
        $initialized = System::initialized();

        // if path is not "initialize" and not installed, then redirect to initialize
        if (!$this->shouldPassThrough($request) && !$initialized) {
            $request->session()->invalidate();
            return redirect()->guest(admin_base_path('initialize'));
        }
        // if path is "initialize" and installed, redirect to login
        elseif ($this->shouldPassThrough($request) && $initialized) {
            return redirect()->guest(admin_base_path('auth/login'));
        }

        static::initializeConfig();

        return $next($request);
    }

    /**
     * Determine if the request has a URI that should pass through verification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function shouldPassThrough($request)
    {
        $excepts = [
            //admin_base_path('auth/login'),
            //admin_base_path('auth/logout'),
            admin_base_path('initialize')
        ];

        foreach ($excepts as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if ($request->is($except)) {
                return true;
            }
        }

        return false;
    }

    public static function initializeConfig($setDatabase = true)
    {
        ///// set config
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
        Config::set('auth.defaults.guard', 'admin');
        Config::set('auth.guards.adminapi', [
            'driver' => 'passport',
            'provider' => 'exment-auth',
        ]);
        // TODO:need.why??
        Config::set('auth.guards.api', [
            'driver' => 'passport',
            'provider' => 'exment-auth',
        ]);
    
        if (!Config::has('filesystems.disks.admin')) {
            Config::set('filesystems.disks.admin', [
                'driver' => 'exment-driver',
                'root' => storage_path('app/admin'),
                'url' => config('app.url').'/'.config('admin.route.prefix'),
            ]);
        }
        if (!Config::has('filesystems.disks.backup')) {
            Config::set('filesystems.disks.backup', [
                'driver' => config('exment.driver.tmp', 'local'),
                'root' => storage_path('app/backup'),
            ]);
        }

        Config::set('database.connections.mysql.strict', false);
        Config::set('database.connections.mysql.options', [
            PDO::ATTR_CASE => PDO::CASE_LOWER,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::MYSQL_ATTR_LOCAL_INFILE => true,
        ]);

        //override
        Config::set('admin.database.menu_model', Exceedone\Exment\Model\Menu::class);
        Config::set('admin.enable_default_breadcrumb', false);
        Config::set('admin.show_environment', false);


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
        }
    }

    /**
     * set laravel-admin form field
     */
    public static function initializeFormField(){
        $map = [
            'number'        => Field\Number::class,
            'editor'        => Field\Tinymce::class,
            'image'        => Field\Image::class,
            'display'        => Field\Display::class,
            'link'           => Field\Link::class,
            'header'           => Field\Header::class,
            'description'           => Field\Description::class,
            'switchbool'          => Field\SwitchBoolField::class,
            'pivotMultiSelect'          => Field\PivotMultiSelect::class,
            'checkboxone'          => Field\Checkboxone::class,
            'tile'          => Field\Tile::class,
            'hasMany'           => Field\HasMany::class,
            'hasManyTable'           => Field\HasManyTable::class,
            'relationTable'          => Field\RelationTable::class,
            'embeds'          => Field\Embeds::class,
            'nestedEmbeds'          => Field\NestedEmbeds::class,
            'valueModal'          => Field\ValueModal::class,
        ];
        foreach ($map as $abstract => $class) {
            Form::extend($abstract, $class);
        }
    }
}
