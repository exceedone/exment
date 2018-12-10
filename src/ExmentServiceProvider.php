<?php

namespace Exceedone\Exment;

use Storage;
use Request;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use Exceedone\Exment\Adapter\AdminLocal;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Validator\UniqueInTableValidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use League\Flysystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;

class ExmentServiceProvider extends ServiceProvider
{
    /**
     * Application Policy Map
     *
     * @var array
     */
    protected $policies = [
        'Exceedone\Exment\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * @var array commands
     */
    protected $commands = [
        'Exceedone\Exment\Console\InstallCommand',
        'Exceedone\Exment\Console\NotifyCommand',
    ];

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'admin.auth'       => \Exceedone\Exment\Middleware\Authenticate::class,
        'admin.bootstrap2'  => \Exceedone\Exment\Middleware\Bootstrap::class,
        'admin.initialize'  => \Exceedone\Exment\Middleware\Initialize::class,
        'admin.morph'  => \Exceedone\Exment\Middleware\Morph::class,
        'admin_api.auth'       => \Exceedone\Exment\Middleware\AuthenticateApi::class,
        // 'web.initialize'  => \Exceedone\Exment\Middleware\Initialize::class,
        // 'web.morph'  => \Exceedone\Exment\Middleware\Web::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'admin' => [
            'admin.auth',
            'admin.pjax',
            'admin.log',
            'admin.bootstrap',
            'admin.permission',
            'admin.bootstrap2',
            'admin.initialize',
            'admin.morph',
        ],
        'admin_anonymous' => [
            //'admin.auth',
            'admin.pjax',
            'admin.log',
            'admin.bootstrap',
            'admin.permission',
            'admin.bootstrap2',
            'admin.initialize',
            'admin.morph',
        ],
        'admin_api' => [
            'admin_api.auth',
        ],
        // 'web' => [
        //     'web.initialize',
        //     'web.morph',
        // ]
    ];

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([__DIR__.'/../config' => config_path()]);
        $this->publishes([__DIR__.'/../resources/lang_vendor' => resource_path('lang')], 'lang');
        $this->publishes([__DIR__.'/../public' => public_path('')], 'public');
        $this->publishes([__DIR__.'/../resources/views/vendor/admin' => resource_path('views/vendor/admin')], 'views_admin');
        
        $this->mergeConfigFrom(
            __DIR__.'/../config/exment.php',
            'exment'
        );
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'exment');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'exment');
        
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php', 'exment');
        
        $this->commands($this->commands);

        $this->registerPolicies();

        $this->bootSetting();

        // for api
        if (boolval(config('exment.api'))) {
            \Laravel\Passport\Passport::routes();
        }

        // $this->bootPlugin();
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        require_once(__DIR__.'/Services/Helpers.php');

        // register route middleware.
        foreach ($this->routeMiddleware as $key => $middleware) {
            app('router')->aliasMiddleware($key, $middleware);
        }

        // register middleware group.
        foreach ($this->middlewareGroups as $key => $middleware) {
            app('router')->middlewareGroup($key, $middleware);
        }
    }


    // plugin --------------------------------------------------
    
    /**
     * Check URI after '/admin/' then get plugin satisfying conditions and execute this plugin
     */
    protected function bootPlugin()
    {
        $pattern = '@plugins/([^/\?]+)@';
        preg_match($pattern, Request::url(), $matches);

        if (!isset($matches) || count($matches) <= 1) {
            return;
        }
        $pluginName = $matches[1];
        
        $plugin = $this->getPluginActivate($pluginName);
        if (!isset($plugin)) {
            return;
        }
        $base_path = path_join(app_path(), 'plugins', $plugin->plugin_name);
        if (! $this->app->routesAreCached()) {
            $config_path = path_join($base_path, 'config.json');
            if (file_exists($config_path)) {
                $json = json_decode(File::get($config_path), true);
                PluginInstaller::route($plugin, $json);
            }
        }
        $this->loadViewsFrom(path_join($base_path, 'views'), $plugin->plugin_name);
    }

    /**
     * Check plugin satisfying conditions
     */
    protected function getPluginActivate($pluginName)
    {
        if (!Schema::hasTable(Plugin::getTableName())) {
            return false;
        }

        $plugin = Plugin
            ::where('active_flg', '=', 1)
            ->where('plugin_type', '=', 'page')
            //->where('plugin_name', '=', $pluginName)
            ->where('options->uri', '=', $pluginName)
            ->first();

        if ($plugin !== null) {
            return $plugin;
        }

        return false;
    }

    protected function bootSetting()
    {
        
        // Extend --------------------------------------------------
        Storage::extend('admin-local', function ($app, $config) {
            return new Filesystem(new AdminLocal(array_get($config, 'root')));
        });
        
        // add for exment_admins
        if (!Config::has('auth.passwords.exment_admins')) {
            Config::set('auth.passwords.exment_admins', [
                'provider' => 'exment-auth',
                'table' => 'password_resets',
                'expire' => 720,
            ]);
        }
        // add for exment_admins
        if (!Config::has('auth.providers.exment-auth')) {
            Config::set('auth.providers.exment-auth', [
                'driver' => 'eloquent',
                'model' => \Exceedone\Exment\Model\LoginUser::class,
            ]);
        }
        
        // add for api passport
        Config::set('auth.guards.admin_api', [
            'driver' => 'passport',
            'provider' => 'exment-auth',
        ]);

        // set config
        if (!Config::has('filesystems.disks.admin')) {
            Config::set('filesystems.disks.admin', [
                'driver' => 'admin-local',
                'root' => storage_path('app/admin'),
                'url' => env('APP_URL').'/'.env('ADMIN_ROUTE_PREFIX'),
            ]);
        }
        //override
        Config::set('admin.database.menu_model', Exceedone\Exment\Model\Menu::class);
        Config::set('admin.enable_default_breadcrumb', false);

        Auth::provider('exment-auth', function ($app, array $config) {
            // Return an instance of Illuminate\Contracts\Auth\UserProvider...
            return new Providers\CustomUserProvider($app['hash'], \Exceedone\Exment\Model\LoginUser::class);
        });
        
        \Validator::resolver(function ($translator, $data, $rules, $messages) {
            return new UniqueInTableValidator($translator, $data, $rules, $messages);
        });
    }
}
