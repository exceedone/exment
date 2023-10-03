<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Routing\Router;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\Plugin\PluginPageBase;

trait PluginPublicTrait
{
    /**
     * routing plugin
     *
     * @param Plugin $plugin
     * @param string $prefix
     * @param string $middleware
     * @return void
     */
    protected function pluginScriptStyleRoute(Plugin $plugin, string $prefix, string $middleware)
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'prefix'        => url_join($prefix, $plugin->getRouteUri()),
            'namespace'     => 'Exceedone\Exment\Services\Plugin',
            'middleware'    => ['adminweb', $middleware],
        ], function (Router $router) {
            // for public file
            Route::get('public/{arg1?}/{arg2?}/{arg3?}/{arg4?}/{arg5?}', 'PluginPageController@_readPublicFile');
        });
    }
}
