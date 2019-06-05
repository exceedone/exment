<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Controllers\BackupController;
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
        if (!$this->app->routesAreCached()) {
            $config_path = path_join($base_path, 'config.json');
            if (file_exists($config_path)) {
                $json = json_decode(File::get($config_path), true);
                $this->pluginRoute($plugin, $json);
            }
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
            'prefix'        => config('admin.route.prefix').'/plugins',
            'namespace'     => $namespace,
            'middleware'    => config('admin.route.middleware'),
            'module'        => $namespace,
        ], function (Router $router) use ($plugin, $namespace, $json) {
            foreach ($json['route'] as $route) {
                $methods = is_string($route['method']) ? [$route['method']] : $route['method'];
                foreach ($methods as $method) {
                    if ($method === "") {
                        $method = 'get';
                    }
                    $method = strtolower($method);
                    // call method in these http method
                    if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'])) {
                        //Route::{$method}(path_join(array_get($plugin->options, 'uri'), $route['uri']), $json['controller'].'@'.$route['function'].'');
                        Route::{$method}(url_join(array_get($plugin->options, 'uri'), $route['uri']), 'Office365UserController@'.$route['function']);
                    }
                }
            }
        });
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
