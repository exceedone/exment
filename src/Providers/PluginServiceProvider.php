<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Services\Plugin\PluginPageBase;

class PluginServiceProvider extends ServiceProvider
{
    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        // load plugins
        if (!canConnection() || !hasTable(SystemTableName::PLUGIN)) {
            return;
        }

        // get plugin page's
        foreach (PluginType::PLUGIN_TYPE_PLUGIN_PAGE() as $plugin_type) {
            $pluginPages = Plugin::getByPluginTypes($plugin_type, true);
        
            // loop
            foreach ($pluginPages as $pluginPage) {
                $this->pluginRoute($plugin_type, $pluginPage);
            }
        }
    
        // get plugin script's and style's
        $pluginPublics = Plugin::getPluginScriptStyles();
        
        // loop
        foreach ($pluginPublics as $pluginScriptStyle) {
            $this->pluginScriptStyleRoute($pluginScriptStyle);
        }
    }

    /**
     * routing plugin
     *
     * @param string $plugin_type
     * @param PluginPageBase $pluginPage
     * @return void
     */
    protected function pluginRoute($plugin_type, $pluginPage)
    {
        $plugin = $pluginPage->_plugin();

        $base_path = $plugin->getFullPath();
        if ($this->app->routesAreCached()) {
            return;
        }

        $config_path = path_join($base_path, 'config.json');
        if (!file_exists($config_path)) {
            return;
        }

        $config = \File::get($config_path);
        $json = json_decode($config, true);

        if (!$plugin->matchPluginType($plugin_type)) {
            return;
        }

        $prefix = null;
        $defaultFunction = null;
        switch ($plugin_type) {
            case PluginType::PAGE:
                $prefix = $pluginPage->getRouteUri();
                $defaultFunction = 'index';
                break;
            case PluginType::API:
                $prefix = $pluginPage->getRouteUri();
                // set contains "api", and not contains "api"
                $prefix = [$prefix, url_join('api', $prefix)];
                $defaultFunction = 'index';
                break;
            case PluginType::DASHBOARD:
                $prefix = $pluginPage->getDashboardUri();
                $defaultFunction = 'body';
                break;
        }
        $isApi = $plugin_type == PluginType::API;

        foreach (stringToArray($prefix) as $p) {
            Route::group([
                'prefix'        => url_join(config('admin.route.prefix'), $p),
                'namespace'     => 'Exceedone\Exment\Services\Plugin',
                'middleware'    => $isApi ? ['api', 'adminapi', 'pluginapi'] : ['adminweb', 'admin'],
            ], function (Router $router) use ($plugin, $isApi, $defaultFunction, $json) {
                $routes = array_get($json, 'route', []);
    
                // if not has index endpoint, set.
                if (!$this->hasPluginRouteIndex($routes)) {
                    $routes[] = [
                        'method' => 'get',
                        'uri' => '',
                        'function' => $defaultFunction ?? 'index'
                    ];
                }
    
                foreach ($routes as $route) {
                    $method = array_get($route, 'method');
                    $methods = is_string($method) ? [$method] : $method;
                    $plugin_name = $isApi ? 'PluginApiController': 'PluginPageController';
                    foreach ($methods as $method) {
                        if ($method === "") {
                            $method = 'get';
                        }
                        $method = strtolower($method);
                        // call method in these http method
                        if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'])) {
                            $func = array_get($route, 'function');
                            $router = Route::{$method}(array_get($route, 'uri'), $plugin_name . '@'. $func);
                            $router->middleware(ApiScope::getScopeString($isApi, ApiScope::PLUGIN));
                            $router->name("exment.plugins.{$plugin->id}.{$method}.{$func}");
                        }
                    }
                }
            });
        }

        $this->pluginScriptStyleRoute($pluginPage);
    }

    /**
     * Check route has index.
     *
     * @param array $routes
     * @return boolean
     */
    protected function hasPluginRouteIndex($routes)
    {
        if (empty($routes)) {
            return false;
        }

        foreach ($routes as $route) {
            // if uri is not empty, continue.
            if (array_get($route, 'uri') != '') {
                continue;
            }
            
            $method = array_get($route, 'method');
            $methods = is_string($method) ? [$method] : $method;
            foreach ($methods as $method) {
                if ($method === "") {
                    $method = 'get';
                }
                $method = strtolower($method);
                
                // if not get, continue.
                if ($method != 'get') {
                    continue;
                }
                return true;
            }
        }

        return false;
    }
    
    /**
     * routing plugin
     *
     * @param PluginPageBase $pluginScriptStyle
     * @return void
     */
    protected function pluginScriptStyleRoute($pluginScriptStyle)
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'prefix'        => url_join(config('admin.route.prefix'), $pluginScriptStyle->_plugin()->getRouteUri()),
            'namespace'     => 'Exceedone\Exment\Services\Plugin',
            'middleware'    => ['adminweb', 'admin_plugin_public'],
        ], function (Router $router) {
            // for public file
            Route::get('public/{arg1?}/{arg2?}/{arg3?}/{arg4?}/{arg5?}', 'PluginPageController@_readPublicFile');
        });
    }
}
