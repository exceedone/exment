<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use ExmentAdminCore\Admin\Grid\Filter;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\ColumnItems\FormOthers;
use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\ColumnItems\CustomColumns;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Services\PartialCrudService;
use Exceedone\Exment\Services\Installer\InstalledState;
use ExmentAdminCore\Admin\Form;
use ExmentAdminCore\Admin\Widgets\Form as WidgetForm;
use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Show;
use Html;
use PDO;

/**
 * Middleware as Initialize.
 * First call. set config for exment, and set from database.
 */
class Initialize
{
    /**
     * @param \Closure(Request): mixed $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        if (!canConnection() || !hasTable(SystemTableName::SYSTEM)) {
            // An installed site whose database cannot be reached (or is empty) is
            // down: 503 for every path, the install pages too. "Installed" is read
            // from the marker file and from .env itself, not from the database nor
            // from env() (with a config cache, env() does not read .env).
            if (InstalledState::isInstalled()) {
                \Illuminate\Support\Facades\Log::error('Database connection failed during initialization. Exment is installed, but the database is unreachable or has no system table.');
                abort(503, 'Database connection failed. Please check your database settings in .env');
            }

            // Not installed yet: only the install pages
            if (!$this->isInstallPath($request)) {
                return redirect()->guest(admin_base_path('install'));
            }

            static::initializeConfig(false);
        } else {
            $initialized = System::initialized();

            if ($initialized) {
                // a site set up before the marker existed gets it here
                InstalledState::markInstalled();
            }

            // the install pages are closed on an installed site
            if ($this->isInstallPath($request) && InstalledState::isInstalled()) {
                return redirect()->guest(admin_base_path($initialized ? 'auth/login' : 'initialize'));
            }

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

        //static::requireBootstrap();

        return $next($request);
    }

    /**
     * Whether this path is "install"
     *
     * @param Request $request
     * @return boolean
     */
    protected function isInstallPath(Request $request)
    {
        // Check install directory
        return collect(['install', 'install/reset'])->contains(function ($path) use ($request) {
            $path = trim(admin_base_path($path), '/');
            return $request->is($path);
        });
    }


    /**
     * The sidebar folded or open the way this browser last left it.
     *
     * The toggle in the header saves "collapsed" or "expanded" in a cookie
     * (Define::COOKIE_SIDEBAR_STATE); the page is then drawn that way from
     * the start, rather than in the site layout's state and switched over
     * by script after it is on screen. No cookie: the site layout decides.
     *
     * Read from $_COOKIE, not the request: the cookie is written by script
     * and is not encrypted, so Laravel's cookie decryption would drop it.
     * Only the two known words are used; anything else is ignored.
     */
    protected static function applySidebarState(): void
    {
        if (app()->runningInConsole()) {
            return;
        }
        $state = $_COOKIE[Define::COOKIE_SIDEBAR_STATE] ?? null;
        if ($state !== 'collapsed' && $state !== 'expanded') {
            return;
        }

        $layout = array_values(array_diff((array) config('admin.layout', []), ['sidebar-collapse']));
        if ($state === 'collapsed') {
            $layout[] = 'sidebar-collapse';
        }
        Config::set('admin.layout', $layout);
    }

    /** The header logo of a site without its own (the logo of 2026-11, public/images). */
    public const DEFAULT_LOGO = 'images/exment_logo.svg';

    /** The same, for the side menu folded. */
    public const DEFAULT_LOGO_MINI = 'images/exment_mark.svg';

    /** The logo with light letters, for the colored bar of the classic design. */
    public const DEFAULT_LOGO_DARK = 'images/exment_logo_dark.svg';

    /** The class of the default logo: no plate behind it (modern.css). */
    public const DEFAULT_LOGO_CLASS = 'exment-default-logo';

    /**
     * The default logo for the bar it sits on: the light bar of the modern design, or
     * the white bar of the black skins of the classic one, take the colored letters;
     * the colored bar of the other skins takes the light ones.
     */
    public static function defaultLogo(): string
    {
        try {
            if (\Exceedone\Exment\Facades\ExmentFacade::isModernUi()) {
                return static::DEFAULT_LOGO;
            }
            $skin = strval(System::site_skin() ?: config('admin.skin'));
        } catch (\Throwable $e) {
            return static::DEFAULT_LOGO;
        }
        if (strpos($skin, 'skin-black') === 0 || !static::defaultLogoExists(static::DEFAULT_LOGO_DARK)) {
            return static::DEFAULT_LOGO;
        }
        return static::DEFAULT_LOGO_DARK;
    }

    protected static function defaultLogoExists(string $path): bool
    {
        return is_file(public_path($path));
    }

    /**
     * The Exment logo of the login pages, for a site without its own logo: the same file as
     * the side menu. The light letters when the page color chosen on the login setting is a
     * dark one (and no image covers it). Null when public/images is not published yet: the
     * page then shows the site name as text, as before.
     *
     * @param string|null $background_color the page color of the login setting
     * @param bool $has_background_image a background image is set
     */
    public static function loginDefaultLogo(?string $background_color = null, bool $has_background_image = false): ?string
    {
        if (!static::defaultLogoExists(static::DEFAULT_LOGO)) {
            return null;
        }
        if (!$has_background_image && static::isDarkColor($background_color) && static::defaultLogoExists(static::DEFAULT_LOGO_DARK)) {
            return asset(static::DEFAULT_LOGO_DARK);
        }
        return asset(static::DEFAULT_LOGO);
    }

    /**
     * The icons of Exment for a page of a site without its own favicon: the ones of the
     * install pages. An empty array when public/images is not published yet.
     *
     * @return array<int, array{href: string, type: string|null, sizes: string|null}>
     */
    public static function defaultFavicons(): array
    {
        $icons = [];
        if (static::defaultLogoExists('images/exment_favicon.png')) {
            $icons[] = ['href' => asset('images/exment_favicon.png'), 'type' => null, 'sizes' => '32x32'];
        }
        if (static::defaultLogoExists(static::DEFAULT_LOGO_MINI)) {
            $icons[] = ['href' => asset(static::DEFAULT_LOGO_MINI), 'type' => 'image/svg+xml', 'sizes' => null];
        }
        return $icons;
    }

    /**
     * A color "#rgb" or "#rrggbb" that dark letters cannot be read on.
     */
    protected static function isDarkColor(?string $color): bool
    {
        if (!is_string($color) || !preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($color), $m)) {
            return false;
        }
        $hex = $m[1];
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));
        // relative luminance (WCAG); below it, the light letters stand out more than the dark ones
        $channel = function ($v) {
            $v = $v / 255;
            return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        };
        $luminance = 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
        return $luminance < 0.179;
    }

    /**
     * @param bool $setDatabase
     * @return void
     */
    public static function initializeConfig($setDatabase = true)
    {
        //// set from env
        if (!is_null($env = config('exment.locale', env('APP_LOCALE')))) {
            \Illuminate\Support\Facades\App::setLocale($env);
        }
        if (!is_null($env = config('exment.timezone', env('APP_TIMEZONE')))) {
            Config::set('app.timezone', $env);
            date_default_timezone_set($env);
        }


        ///// set config

        // Only set if expire_on_close is false(default)
        if (!boolval(Config::get('session.expire_on_close', false))) {
            Config::set('session.expire_on_close', Config::get('exment.session_expire_on_close', false));
        }

        // for password reset
        if (!Config::has('auth.passwords.exment_admins')) {
            Config::set('auth.passwords.exment_admins', [
                'provider' => 'exment-auth',
                'table' => 'password_reset_tokens',
                'expire' => 720,
                'throttle' => 60,
            ]);
        }
        if (!Config::has('auth.providers.exment-auth')) {
            Config::set('auth.providers.exment-auth', [
                'driver' => 'exment-auth',
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


        // for login publicform
        Config::set('auth.guards.publicform', [
            'driver' => 'publicformtoken',
            'provider' => 'publicform-provider',
            'storage_key' => 'uuid',
        ]);
        Config::set('auth.providers.publicform-provider', [
            'driver' => 'publicform-provider-driver',
        ]);


        ///// File info
        $permissions = [
            'file' => [
                'public' => 0764,
                'private' => 0700,
            ],
            'dir' => [
                'public' => 0775,
                'private' => 0700,
            ]
        ];
        /// maybe update setting by user
        if (!Config::has('filesystems.disks.exment')) {
            Config::set('filesystems.disks.exment', [
                'driver' => 'local',
                'root' => storage_path('app/admin'),
                'url' => admin_url(),
                'permissions' => $permissions,
            ]);
        }

        if (!Config::has('filesystems.disks.backup')) {
            Config::set('filesystems.disks.backup', [
                'driver' => 'local',
                'root' => storage_path('app/backup'),
                'permissions' => $permissions,
            ]);
        }

        if (!Config::has('filesystems.disks.plugin')) {
            Config::set('filesystems.disks.plugin', [
                'driver' => 'local',
                'root' => storage_path('app/plugins'),
                'permissions' => $permissions,
            ]);
        }

        if (!Config::has('filesystems.disks.template')) {
            Config::set('filesystems.disks.template', [
                'driver' => 'local',
                'root' => storage_path('app/templates'),
                'permissions' => $permissions,
            ]);
        }

        if (!Config::has('filesystems.disks.public_form_tmp')) {
            Config::set('filesystems.disks.public_form_tmp', [
                'driver' => 'local',
                'root' => storage_path('app/public_form_tmp'),
                'permissions' => $permissions,
            ]);
        }


        /// only set by system
        Config::set('filesystems.disks.admin_tmp', [
            'driver' => 'local',
            'root' => storage_path('app/admin_tmp'),
            'permissions' => $permissions,
        ]);

        Config::set('filesystems.disks.tmpupload', [
            'driver' => 'local',
            'root' => storage_path('app/tmpupload'),
            'permissions' => $permissions,
        ]);

        Config::set('filesystems.disks.admin', [
            'driver' => 'exment-driver-exment',
            'mergeFrom' => 'exment',
            'permissions' => $permissions,
        ]);

        Config::set('filesystems.disks.plugin_sync', [
            'driver' => 'exment-driver-plugin',
            'mergeFrom' => 'plugin',
            'root' => storage_path('app/plugins'),
            'permissions' => $permissions,
        ]);

        Config::set('filesystems.disks.backup_sync', [
            'driver' => 'exment-driver-backup',
            'mergeFrom' => 'backup',
            'root' => storage_path('app/backup'),
            'permissions' => $permissions,
        ]);

        Config::set('filesystems.disks.template_sync', [
            'driver' => 'exment-driver-template',
            'mergeFrom' => 'template',
            'root' => storage_path('app/templates'),
            'permissions' => $permissions,
        ]);


        Config::set('filesystems.disks.plugin_local', [
            'driver' => 'local',
            'root' => storage_path('app/plugins'),
            'permissions' => $permissions,
        ]);
        Config::set('filesystems.disks.plugin_test', [
            'driver' => 'local',
            'root' => exment_package_path('tests/tmpfile/plugins'),
            'permissions' => $permissions,
        ]);

        // mysql setting
        if (defined('PDO::MYSQL_ATTR_LOCAL_INFILE')) {
            $options = [
                PDO::ATTR_CASE => PDO::CASE_LOWER,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
                PDO::ATTR_STRINGIFY_FETCHES => true,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::MYSQL_ATTR_LOCAL_INFILE => true,
            ];
            // Preserve the TLS CA that config/database.php already set. This
            // Config::set replaces the WHOLE options array, so without this the
            // MYSQL_ATTR_SSL_CA is dropped and connections to a server with
            // require_secure_transport=ON (e.g. AWS RDS) are rejected.
            $currentOptions = Config::get('database.connections.mysql.options', []);
            $sslCa = $currentOptions[PDO::MYSQL_ATTR_SSL_CA] ?? env('MYSQL_ATTR_SSL_CA');
            if (!empty($sslCa)) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            }
            Config::set('database.connections.mysql.strict', false);
            Config::set('database.connections.mysql.options', $options);
        }

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
            'image'      => FormOthers\Image::class,
            'hr'      => FormOthers\Hr::class,
        ];
        foreach ($map as $abstract => $class) {
            FormOtherItem::extend($abstract, $class);
        }

        if ($setDatabase) {
            // Set system setting to config --------------------------------------------------
            // Site Name
            $val = System::site_name();
            if (!is_nullorempty($val)) {
                Config::set('admin.name', $val);
                Config::set('admin.title', $val);
            }

            // Logo: the site's own; without one, the Exment logo (the site name is still
            // the page title). The site name as text only when the logo is not there
            // (public/images not published yet).
            $val = System::site_logo();
            if (!is_nullorempty($val)) {
                Config::set('admin.logo', html_image($val, 'header logo'));
            } elseif (static::defaultLogoExists(static::DEFAULT_LOGO)) {
                Config::set('admin.logo', html_image(static::defaultLogo(), esc_html(System::site_name() ?: 'Exment'), ['class' => static::DEFAULT_LOGO_CLASS]));
            } else {
                $val = System::site_name();
                if (!is_nullorempty($val)) {
                    Config::set('admin.logo', esc_html($val));
                }
            }

            // Logo(Short): the site's own; without one, the Exment mark
            $val = System::site_logo_mini();
            if (!is_nullorempty($val)) {
                Config::set('admin.logo-mini', html_image($val, 'header logo mini'));
            } elseif (static::defaultLogoExists(static::DEFAULT_LOGO_MINI)) {
                Config::set('admin.logo-mini', html_image(static::DEFAULT_LOGO_MINI, esc_html(System::site_name_short() ?: 'Exment'), ['class' => static::DEFAULT_LOGO_CLASS]));
            } else {
                $val = System::site_name_short();
                if (!is_nullorempty($val)) {
                    Config::set('admin.logo-mini', esc_html($val));
                }
            }

            // Site Skin
            $val = System::site_skin();
            if (!is_nullorempty($val)) {
                Config::set('admin.skin', esc_html($val));
            }

            // Site layout
            $val = System::site_layout();
            if (!is_nullorempty($val)) {
                Config::set('admin.layout', array_get(Define::SYSTEM_LAYOUT, $val));
            }
            static::applySidebarState();

            // Date format
            $list = null;
            $val = System::default_date_format();
            if (!is_nullorempty($val)) {
                $list = exmtrans("system.date_format_list.$val");
            }
            if (!is_nullorempty($list) && is_array($list) && count($list) > 2) {
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
                \ExmentAdminCore\Admin\Facades\Admin::setFavicon(admin_url('favicon'));
            }

            // mail setting
            if (!boolval(config('exment.mail_setting_env_force', false))) {
                // Here we will check if the "driver" key exists and if it does we will use
                // the entire mail configuration file as the "driver" config in order to
                // provide "BC" for any Laravel <= 6.x style mail configuration files.
                if (!is_nullorempty(Config::get('mail.driver'))) {
                    $keys = [
                        'system_mail_host' => 'host',
                        'system_mail_port' => 'port',
                        'system_mail_username' => 'username',
                        'system_mail_password' => 'password',
                        'system_mail_encryption' => 'encryption',
                        'system_mail_from' => ['from.address', 'from.name'],
                        'system_mail_from_view_name' => 'from.name', // If system_mail_from_view_name is not set, from.name set as system_mail_from
                    ];
                } else {
                    $keys = [
                        'system_mail_host' => 'mailers.smtp.host',
                        'system_mail_port' => 'mailers.smtp.port',
                        'system_mail_username' => 'mailers.smtp.username',
                        'system_mail_password' => 'mailers.smtp.password',
                        'system_mail_encryption' => 'mailers.smtp.encryption',
                        'system_mail_from' => ['from.address', 'from.name'],
                        'system_mail_from_view_name' => 'from.name', // If system_mail_from_view_name is not set, from.name set as system_mail_from
                    ];
                }

                foreach ($keys as $keyname => $configname) {
                    if (is_nullorempty($val = System::{$keyname}())) {
                        continue;
                    }
                    if (!is_array($configname)) {
                        $configname = [$configname];
                    }

                    foreach ($configname as $c) {
                        Config::set("mail.{$c}", $val);
                    }
                }

                // The settings above only fill the "smtp" mailer. Laravel 11+ has "log" as the default
                // mailer (config/mail.php and MAIL_MAILER of .env.example), so a mail was only written
                // to the log although the SMTP host is set on the system setting page.
                // Another mailer chosen in .env (sendmail, ses...) is kept.
                if (!is_nullorempty(System::system_mail_host()) && Config::get('mail.default') === 'log') {
                    Config::set('mail.default', 'smtp');
                }
            }

            // Google reCAPTCHA ----------------------------------------------------
            if (!is_null($val = Model\PublicForm::recaptchaSiteKey())) {
                Config::set('no-captcha.sitekey', $val);
            }
            if (!is_null($val = Model\PublicForm::recaptchaSecretKey())) {
                Config::set('no-captcha.secret', $val);
            }
            if (!is_null($val = Model\PublicForm::recaptchaVersion())) {
                Config::set('no-captcha.version', $val);
            }
        }

        // demo mode: every mail goes to the log, whatever the mail settings above say
        \Exceedone\Exment\Services\DemoMode::applyRuntimeConfig();
    }

    /**
     * @return void
     */
    public static function requireBootstrap()
    {
        $file = config('exment.bootstrap', exment_app_path('bootstrap.php'));
        if (!\Illuminate\Support\Facades\File::exists($file)) {
            return;
        }
        require_once $file;
    }

    /**
     * set laravel-admin
     * @return void
     */
    public static function registeredLaravelAdmin()
    {
        Grid::init(function (Grid $grid) {
            $grid->disableColumnSelector();

            // @phpstan-ignore-next-line
            if ($grid->model() && ($grid->model()->eloquent() instanceof Model\CustomValue)) {
                if (!is_null($value = System::grid_pager_count())) {
                    $grid->paginate($value);
                }
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
        Grid\Tools\BatchActions::$deleteBatchClassName = \Exceedone\Exment\Grid\Tools\BatchDelete::class;
        //Grid\Concerns\HasQuickSearch::setSearchKey('query');
        Grid::setSearchKey('query');

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
            'codeEditor'          => Field\CodeEditor::class,
            'image'        => Field\Image::class,
            'favicon'        => Field\Favicon::class,
            'link'           => Field\Link::class,
            'exmheader'           => Field\Header::class,
            'radio'           => Field\RadioButton::class,
            'description'           => Field\Description::class,
            'descriptionHtml'           => Field\DescriptionHtml::class,
            'internal'           => Field\Internal::class,
            'switchbool'          => Field\SwitchBoolField::class,
            'pivotMultiSelect'          => Field\PivotMultiSelect::class,
            'checkboxone'          => Field\Checkboxone::class,
            'checkboxTable'          => Field\CheckboxTable::class,
            'tile'          => Field\Tile::class,
            'hasMany'           => Field\HasMany::class,
            'hasManyTable'           => Field\HasManyTable::class,
            'hasManyJson'           => Field\HasManyJson::class,
            'hasManyJsonTable'           => Field\HasManyJsonTable::class,
            //'relationTable'          => Field\RelationTable::class,
            'embeds'          => Field\Embeds::class,
            'nestedEmbeds'          => Field\NestedEmbeds::class,
            'numberRange'           => Field\NumberRange::class,
            'valueModal'          => Field\ValueModal::class,
            'changeField'          => Field\ChangeField::class,
            'progressTracker'          => Field\ProgressTracker::class,
            'sectionNav'          => Field\SectionNav::class,
            'systemValues'          => Field\SystemValues::class,

            ///// workflow
            'workflowStatusSelects'          => Field\Workflow\StatusSelects::class,
            'workflowOptions'          => Field\Workflow\Options::class,
        ];
        foreach ($map as $abstract => $class) {
            Form::extend($abstract, $class);
        }

        Show::extend('system_values', \Exceedone\Exment\Form\Show\SystemValues::class);

        Filter::extend('betweendatetime', \Exceedone\Exment\Grid\Filter\BetweenDatetime::class);
        Filter::extend('exmwhere', \Exceedone\Exment\Grid\Filter\Where::class);
    }
}
