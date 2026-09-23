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
        // When the route cache is warm every pluginRoute() and
        // pluginScriptStyleRoute() call below returns immediately - the
        // routes are already compiled into the cached file. Nothing here is
        // free though: hasTable(), getByPluginTypes() and
        // getPluginScriptStyles() all reach the database, and with
        // exment.use_cache off they do so on every single request,
        // including ones that never touch a plugin. Bail out before paying
        // for work whose result is discarded.
        if ($this->app->routesAreCached()) {
            return;
        }

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
    /**
     * Middlewares a plugin is allowed to opt out of.
     *
     * This is an allowlist rather than a denylist on purpose: a plugin can
     * only ever drop middlewares that cost time, never ones that decide who
     * is allowed in. Even a malicious config.json cannot open an
     * unauthenticated hole, because nothing touching authentication,
     * permission or IP filtering appears here.
     */
    protected const OPTIONAL_MIDDLEWARE = [
        'admin.morph',
        'admin.log',
        'admin.bootstrap',
        'admin.bootstrap2',
        'admin.pjax',
        'log.exec.time',
        'check.logging.enabled',
    ];

    /**
     * Build the middleware stack for a plugin's routes.
     *
     * Without a "middleware_except" key the result is exactly the stack the
     * plugin type has always been given, so existing plugins are unaffected.
     * With one, the named groups are expanded so individual entries can be
     * removed - a route that only returns JSON has no use for the view
     * bootstrapper or the pjax handler, and paying for them shows up as
     * latency on every call.
     *
     * @param array<mixed> $json decoded config.json
     * @param bool $isApi
     * @return array<mixed>
     */
    protected function resolveRouteMiddleware($json, bool $isApi): array
    {
        $default = $isApi ? ['api', 'adminapi', 'pluginapi'] : ['adminweb', 'admin'];

        $except = array_get($json, 'middleware_except', []);
        $except = array_intersect(stringToArray($except), static::OPTIONAL_MIDDLEWARE);
        if (empty($except)) {
            return $default;
        }

        $groups = Route::getMiddlewareGroups();
        $resolved = [];
        foreach ($default as $name) {
            if (!isset($groups[$name])) {
                $resolved[] = $name;
                continue;
            }
            foreach ($groups[$name] as $middleware) {
                if (in_array($middleware, $except, true)) {
                    continue;
                }
                $resolved[] = $middleware;
            }
        }

        return $resolved;
    }

    /**
     * Middlewares a single route entry wants to skip.
     *
     * Plugin-level "middleware_except" suits a plugin whose endpoints all
     * behave alike, but a plugin that serves both HTML pages and JSON needs
     * the split: the page still wants the admin chrome that
     * admin.bootstrap builds, while a JSON endpoint paying for it gets
     * nothing back. Laravel's withoutMiddleware() applies the exclusion to
     * one route without disturbing the group.
     *
     * @param array<mixed> $route one entry of config.json "route"
     * @return array<string>
     */
    protected function resolveRouteExcept($route): array
    {
        $except = stringToArray(array_get($route, 'middleware_except', []));

        return array_values(array_intersect($except, static::OPTIONAL_MIDDLEWARE));
    }

    /**
     * Extra middleware a single route entry asks for.
     *
     * Only names already registered as aliases are accepted. A plugin can
     * ship its own middleware class, but its autoloader is registered lazily
     * (Plugin::requirePlugin), so naming the class here would resolve before
     * that happens - and not at all once the route cache is warm. Restricting
     * this to aliases keeps the failure mode out of the boot path.
     *
     * @param array<mixed> $route one entry of config.json "route"
     * @return array<string>
     */
    protected function resolveExtraMiddleware($route): array
    {
        $names = stringToArray(array_get($route, 'middleware', []));
        if (empty($names)) {
            return [];
        }

        $aliases = Route::getMiddleware();

        return collect($names)->filter(function ($name) use ($aliases) {
            return is_string($name) && isset($aliases[$name]);
        })->values()->all();
    }

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
                'middleware'    => $this->resolveRouteMiddleware($json, $isApi),
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

                /** @var array<mixed> $routes */
                // @phpstan-ignore-next-line
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
                            foreach ($this->resolveExtraMiddleware($route) as $extra) {
                                $router->middleware($extra);
                            }
                            if (!empty($skip = $this->resolveRouteExcept($route))) {
                                $router->withoutMiddleware($skip);
                            }
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
     * @param array<mixed> $routes
     * @return boolean
     */
    protected function hasPluginRouteIndex($routes)
    {
        if (empty($routes)) {
            return false;
        }

        foreach ($routes as $route) {
            // if uri is not empty, continue.
            /** @var array<mixed> $route */
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
