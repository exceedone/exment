<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\PluginType;
use Request;

class PluginServiceProvider extends ServiceProvider
{
    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        // get plugin page's
        $pluginPages = Plugin::getPluginPages();
        
        // loop
        foreach($pluginPages as $pluginPage){
            $base_path = $pluginPage->_plugin()->getFullPath();
            if ($this->app->routesAreCached()) {
                continue;
            }

            $config_path = path_join($base_path, 'config.json');
            if (!file_exists($config_path)) {
                continue;
            }

            $config = \File::get($config_path);
            $json = json_decode($config, true);
            $this->pluginRoute($pluginPage, $json);
        }
    }

    /**
     * routing plugin
     *
     * @param Plugin $plugin
     * @param json $json
     * @return void
     */
    protected function pluginRoute($pluginPage, $json)
    {
        Route::group([
            'prefix'        => url_join(config('admin.route.prefix'), $pluginPage->_plugin()->getRouteUri()),
            'namespace'     => 'Exceedone\Exment\Services\Plugin',
            'middleware'    => config('admin.route.middleware'),
        ], function (Router $router) use ($pluginPage, $json) {
            foreach ($json['route'] as $route) {
                $methods = is_string($route['method']) ? [$route['method']] : $route['method'];
                foreach ($methods as $method) {
                    if ($method === "") {
                        $method = 'get';
                    }
                    $method = strtolower($method);
                    // call method in these http method
                    if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'])) {
                        Route::{$method}(url_join(array_get($pluginPage->_plugin()->options, 'uri'), $route['uri']), 'PluginPageController@'.$route['function']);
                    }
                }
            }

            // get css and js
            $publics = ['css', 'js'];
            foreach($publics as $p){
                $items = $pluginPage->{"_$p"}();
                if(empty($items)){
                    continue;
                }

                foreach($items as $item){
                    Route::get(url_join($p, $item), 'PluginPageController@_readPublicFile');
                }
            }

            Route::get('css/', 'PluginPageController@_readPublicFile');
            //Route::get('css/{cssfile?}', 'PluginPageController@_readPublicFile');
            //Route::get('css/{cssfile?}/{aaa?}', 'PluginPageController@_readPublicFile');
        });
    }
}
