<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Exceedone\Exment\Model\Define;

class Login2factorServiceProvider extends BaseServiceProvider
{
    protected $keyName;
    
    protected $dir;

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->load();
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        // register route middleware.
        foreach ($this->routeMiddleware as $key => $middleware) {
            app('router')->aliasMiddleware($key, $middleware);
        }
        
        // register middleware group.
        foreach ($this->middlewareGroups as $key => $middleware) {
            foreach($middleware as $m){
                app('router')->pushMiddlewareToGroup($key, $m);
            }
        }
    }

    protected function publish()
    {
        $this->publishes([$this->dir.'/../config' => config_path()]);
    }

    protected function load()
    {
        $this->loadMigrationsFrom($this->dir.'/../database/migrations');
        $this->loadViewsFrom($this->dir.'/../resources/views', $this->keyName);
        $this->loadTranslationsFrom($this->dir.'/../resources/lang', $this->keyName);
    }
}
