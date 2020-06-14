<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;
use Exceedone\Exment\Model\Define;

class Route2factorServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'Exceedone\Exment\Controllers';

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        Route::group([
            'prefix'        => config('admin.route.prefix'),
            'namespace'     => $this->namespace,
            'middleware'    => ['adminweb', 'admin'],
        ], function (Router $router) {
            $router->get('auth-2factor', 'Auth2factorController@index');
            $router->post('auth-2factor/verify', 'Auth2factorController@verify');
            $router->get('auth-2factor/logout', 'Auth2factorController@logout');

            $router->get('auth-2factor/google/sendmail', 'Auth2factorController@sendmail');
            $router->get('auth-2factor/google/register', 'Auth2factorController@register');
        });
    }
}
