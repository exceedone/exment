<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Plugin;
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
        // get page's plugins
        $plugins = Plugin
            ::where('active_flg', 1)
            ->where('plugin_type', PluginType::PAGE)
            ->get();

        // loop
        foreach($plugins as $plugin){
            $base_path = $plugin->getFullPath();
            if ($this->app->routesAreCached()) {
                continue;
            }

            $config_path = path_join($base_path, 'config.json');
            if (!file_exists($config_path)) {
                continue;
            }

            $config = \File::get($config_path);
            $json = json_decode($config, true);
            $this->pluginRoute($plugin, $json);
        }
    }

    /**
     * routing plugin
     *
     * @param Plugin $plugin
     * @param json $json
     * @return void
     */
    protected function pluginRoute($plugin, $json)
    {
        $namespace = $plugin->getNameSpace();
        Route::group([
            'prefix'        => config('admin.route.prefix').'/plugins/' . $plugin->plugin_name,
            'namespace'     => 'Exceedone\Exment\Services\Plugin',
            'middleware'    => config('admin.route.middleware'),
            'module'        => $namespace,
        ], function (Router $router) use ($plugin, $namespace, $json) {
            $class = $plugin->getClass();
            foreach ($json['route'] as $route) {
                $methods = is_string($route['method']) ? [$route['method']] : $route['method'];
                foreach ($methods as $method) {
                    if ($method === "") {
                        $method = 'get';
                    }
                    $method = strtolower($method);
                    // call method in these http method
                    if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'])) {
                        Route::{$method}(path_join(array_get($plugin->options, 'uri'), $route['uri']), 'PluginPageController@'.$route['function'].'');
                    }
                }
            }

            // get css and js
            $publics = ['css', 'js'];
            foreach($publics as $p){
                $items = $class->{"_$p"}();
                if(empty($items)){
                    continue;
                }

                foreach($items as $item){

                }
            }
        });
    }
}
