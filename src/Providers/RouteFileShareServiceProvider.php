<?php

namespace Exceedone\Exment\Providers;

use Exceedone\Exment\Providers\ExmentRouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/**
 * File server, phase 3 (ISS00377): the addresses of the share and request
 * links, for people who do not sign in: /share/{token}/... (the first part is
 * exment.file_server_share_path).
 */
class RouteFileShareServiceProvider extends ServiceProvider
{
    /**
     * @var string
     */
    protected $namespace = 'Exceedone\Exment\Controllers';

    /**
     * @return void
     */
    public function map()
    {
        if (!boolval(config('exment.file_server_external_share', true))) {
            return;
        }
        Route::group([
            'prefix' => url_join(\Exceedone\Exment\Services\FileServer\ShareLinks::basePath(), '{token}'),
            'namespace' => $this->namespace,
            // the IP filter of the web is for the users inside; a site that wants it for the links too says so (ISS00433)
            'middleware' => array_merge(['adminweb', 'throttle:120,1'], boolval(config('exment.file_server_share_web_ip_filter', false)) ? ['admin.web-ipfilter'] : []),
            'where' => ['token' => '[A-Za-z0-9]{40}'],
        ], function (Router $router) {
            $router->get('/', 'FileShareController@show');
            $router->get('item/{node}', 'FileShareController@show')->where('node', '[0-9]+');
            $router->post('/', 'FileShareController@unlock')->middleware('throttle:10,1');
            $router->get('download', 'FileShareController@download');
            $router->get('content', 'FileShareController@content');
            $router->post('upload', 'FileShareController@upload')->middleware('throttle:30,1');
        });
    }
}
