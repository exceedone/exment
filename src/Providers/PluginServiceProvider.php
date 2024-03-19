<?php

namespace Exceedone\Exment\Providers;

use Exceedone\Exment\Services\Plugin\PluginCrudBase;
use Exceedone\Exment\Services\Plugin\PluginDashboardBase;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Services\Plugin\PluginPageBase;

class PluginServiceProvider extends ServiceProvider
{
    use PluginPublicTrait;

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
            $this->pluginScriptStyleRoute($pluginScriptStyle->_plugin(), config('admin.route.prefix'), 'admin_plugin_public');
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
        $json = json_decode_ex($config, true);

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
            case PluginType::VIEW:
                $prefix = $plugin->getRouteUri();
                $defaultFunction = 'grid';
                break;
            case PluginType::API:
                $prefix = $pluginPage->getRouteUri();
                // set contains "api", and not contains "api"
                $prefix = [$prefix, url_join('api', $prefix)];
                $defaultFunction = 'index';
                break;
            case PluginType::DASHBOARD:
                /** @var PluginDashboardBase $pluginPage */
                $prefix = $pluginPage->getDashboardUri();
                $defaultFunction = 'body';
                break;
            case PluginType::CRUD:
                $prefix = $pluginPage->getRouteUri();
                $defaultFunction = 'index';
                break;
        }
        $isApi = $plugin_type == PluginType::API;

        foreach (stringToArray($prefix) as $p) {
            Route::group([
                'prefix'        => url_join(config('admin.route.prefix'), $p),
                'namespace'     => 'Exceedone\Exment\Services\Plugin',
                'middleware'    => $isApi ? ['api', 'adminapi', 'pluginapi'] : ['adminweb', 'admin'],
            ], function (Router $router) use ($plugin, $isApi, $defaultFunction, $pluginPage, $plugin_type, $json) {
                // if crud, set crud routing
                if ($plugin_type == PluginType::CRUD) {
                    $router->get("oauth", "PluginCrudController@oauth");
                    $router->get("oauthcallback", "PluginCrudController@oauthcallback");
                    $router->get("oauthlogout", "PluginCrudController@oauthlogout");
                    $router->get("noauth", "PluginCrudController@noauth");

                    /** @var PluginCrudBase $pluginPage */
                    $endpoints = $pluginPage->getAllEndpoints();
                    $key = is_nullorempty($endpoints) ? "" : "{endpoint}";

                    $router->get("{$key}", "PluginCrudController@index");
                    $router->get("{$key}/create", "PluginCrudController@create");
                    $router->post("{$key}", "PluginCrudController@store");
                    $router->get("{$key}/{id}/edit", "PluginCrudController@edit");
                    $router->put("{$key}/{id}", "PluginCrudController@update");
                    $router->patch("{$key}/{id}", "PluginCrudController@update");
                    $router->delete("{$key}/{id}", "PluginCrudController@destroy");
                    $router->get("{$key}/{id}", "PluginCrudController@show");
                    return;
                }

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
                    $plugin_name = $isApi ? 'PluginApiController' : 'PluginPageController';
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

        $this->pluginScriptStyleRoute($plugin, config('admin.route.prefix'), 'admin_plugin_public');
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
}
