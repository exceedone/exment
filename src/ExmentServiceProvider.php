<?php

namespace Exceedone\Exment;

use Storage;
use Request;
use Encore\Admin\Admin;
use Encore\Admin\Middleware as AdminMiddleware;
use Encore\Admin\AdminServiceProvider as ServiceProvider;
use Exceedone\Exment\Providers as ExmentProviders;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\EnumBase;
use Exceedone\Exment\Validator\ExmentCustomValidator;
use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\Database as ExmentDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\File;
use Illuminate\Console\Scheduling\Schedule;
use League\Flysystem\Filesystem;
use Laravel\Passport\Passport;
use Laravel\Passport\Client;
use Webpatser\Uuid\Uuid;

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
        'Exceedone\Exment\Console\UpdateCommand',
        'Exceedone\Exment\Console\ScheduleCommand',
        'Exceedone\Exment\Console\BackupCommand',
        'Exceedone\Exment\Console\RestoreCommand',
        'Exceedone\Exment\Console\ClientListCommand',
        'Exceedone\Exment\Console\BulkInsertCommand',
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
        'adminapi.auth'       => \Exceedone\Exment\Middleware\AuthenticateApi::class,

        'admin.pjax'       => AdminMiddleware\Pjax::class,
        'admin.permission' => AdminMiddleware\Permission::class,
        'admin.log'        => AdminMiddleware\LogOperation::class,
        'admin.bootstrap'  => AdminMiddleware\Bootstrap::class,
        'admin.session'    => AdminMiddleware\Session::class,

        'scope' => \Exceedone\Exment\Middleware\CheckForAnyScope::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'admin' => [
            'admin.auth',
            'admin.initialize',
            'admin.morph',
            'admin.bootstrap2',
            'admin.pjax',
            'admin.log',
            'admin.bootstrap',
            'admin.permission',
            'admin.session',
        ],
        'admin_anonymous' => [
            'admin.initialize',
            'admin.morph',
            'admin.bootstrap2',
            'admin.pjax',
            'admin.log',
            'admin.bootstrap',
            'admin.permission',
            'admin.session',
        ],
        'adminapi' => [
            'adminapi.auth',
            'throttle:60,1',
            'bindings',
        ],
        'adminapi_anonymous' => [
            'throttle:60,1',
            'bindings',
        ]
    ];

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        $this->bootApp();
        $this->bootSetting();
        $this->bootDatabase();
        $this->bootDebug();

        $this->publish();
        $this->load();

        $this->registerPolicies();

        $this->bootPassport();
        $this->bootPlugin();
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        parent::register();
        require_once(__DIR__.'/Services/Helpers.php');

        // register route middleware.
        foreach ($this->routeMiddleware as $key => $middleware) {
            app('router')->aliasMiddleware($key, $middleware);
        }

        // register middleware group.
        foreach ($this->middlewareGroups as $key => $middleware) {
            app('router')->middlewareGroup($key, $middleware);
        }

        Passport::ignoreMigrations();
    }

    protected function publish()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/exment.php',
            'exment'
        );
        
        $this->publishes([__DIR__.'/../config' => config_path()]);
        $this->publishes([__DIR__.'/../resources/lang_vendor' => resource_path('lang')], 'lang');
        $this->publishes([__DIR__.'/../public' => public_path('')], 'public');
        $this->publishes([__DIR__.'/../resources/views/vendor' => resource_path('views/vendor')], 'views_vendor');
    }

    protected function load()
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'exment');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'exment');
    }

    protected function bootApp()
    {
        $this->app->register(ExmentProviders\RouteServiceProvider::class);
        $this->app->register(ExmentProviders\RouteOAuthServiceProvider::class);
        $this->app->register(ExmentProviders\PasswordResetServiceProvider::class);
        
        $this->commands($this->commands);

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('exment:schedule')->hourly();
        });
    }

    protected function bootPassport()
    {
        // adding rule for laravel-passport
        Client::creating(function (Client $client) {
            $client->incrementing = false;
            $client->id = Uuid::generate()->string;
        });
        Client::retrieved(function (Client $client) {
            $client->incrementing = false;
        });
        Passport::tokensCan(ApiScope::transArray('api.scopes'));
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

    protected function bootSetting()
    {
        // Extend --------------------------------------------------
        Auth::provider('exment-auth', function ($app, array $config) {
            // Return an instance of Illuminate\Contracts\Auth\UserProvider...
            return new Providers\CustomUserProvider($app['hash'], \Exceedone\Exment\Model\LoginUser::class);
        });
        
        \Validator::resolver(function ($translator, $data, $rules, $messages, $customAttributes) {
            return new ExmentCustomValidator($translator, $data, $rules, $messages, $customAttributes);
        });

        Storage::extend('exment-driver', function ($app, $config) {
            switch (config('exment.driver.default', 'local')) {
                case 'local':
                    $adaper = Adapter\ExmentAdapterLocal::getAdapter($app, $config);
                    break;
                case 's3':
                    $adaper = Adapter\ExmentAdapterS3::getAdapter($app, $config);
                    break;
                default:
                    $adaper = Adapter\ExmentAdapterLocal::getAdapter($app, $config);
                    break;
            }
            return new Filesystem($adaper);
        });

        Initialize::initializeConfig(false);
        
        if (method_exists("\Encore\Admin\Admin", "registered")) {
            Admin::registered(function () {
                Initialize::registeredLaravelAdmin();
            });
        } else {
            Admin::booting(function () {
                Initialize::registeredLaravelAdmin();
            });
        }
    }
    
    /**
     * Boot database for extend
     *
     * @return void
     */
    protected function bootDatabase()
    {
        Connection::resolverFor('mysql', function (...$parameters) {
            return new ExmentDatabase\MySqlConnection(...$parameters);
        });
        Connection::resolverFor('sqlsrv', function (...$parameters) {
            return new ExmentDatabase\SqlServerConnection(...$parameters);
        });
    }

    /**
     * Boot database for Debug. if config.exment.debugmode -> true, show sql to larabel.log
     *
     * @return void
     */
    protected function bootDebug()
    {
        if(!boolval(config('exment.debugmode', false))){
            return;
        }

        \DB::listen(function ($query) {
            $sql = $query->sql;
            for ($i = 0; $i < count($query->bindings); $i++) {
                $binding = $query->bindings[$i];
                if($binding instanceof \DateTime){
                    $binding = $binding->format('Y-m-d H:i:s');
                }elseif($binding instanceof EnumBase){
                    $binding = $binding->toString();
                }
                $sql = preg_replace("/\?/", "'{$binding}'", $sql, 1);
            }
            $now = \Carbon\Carbon::now();
            \Log::debug('SQL: ' . $now->format("YmdHisv")." ".$sql);
        });
    }

    /**
     * Register the application's policies.
     *
     * @return void
     */
    public function registerPolicies()
    {
        foreach ($this->policies as $key => $value) {
            Gate::policy($key, $value);
        }
    }
    
    /**
     * Get the policies defined on the provider.
     *
     * @return array
     */
    public function policies()
    {
        return $this->policies;
    }
    
    /**
     * Check plugin satisfying conditions
     */
    protected function getPluginActivate($pluginName)
    {
        $plugin = Plugin
            ::where('active_flg', 1)
            ->where('plugin_type', PluginType::PAGE)
            ->where('options->uri', $pluginName)
            ->first();

        if ($plugin !== null) {
            return $plugin;
        }

        return false;
    }
}
