<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Enums\SystemTableName;

class RoutePublicFormServiceProvider extends ServiceProvider
{
    use PluginPublicTrait;

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
        $this->mapExmentPublicFormWebRotes();
    }

    protected function mapExmentPublicFormWebRotes()
    {
        if (!canConnection() || !hasTable(SystemTableName::SYSTEM) || !System::publicform_available()) {
            return;
        }

        $prefix = public_form_base_path();
        Route::group([
            'prefix'        => url_join($prefix, '{form_key}'),
            'namespace'     => $this->namespace,
            'middleware'    => ['adminweb', 'publicform'],
        ], function (Router $router) {
            $router->get('/', 'PublicFormController@index');
            $router->post('/', 'PublicFormController@backed');
            $router->get('/confirm', 'PublicFormController@redirect');
            $router->post('/confirm', 'PublicFormController@confirm');
            $router->get('/create', 'PublicFormController@redirect');
            $router->post('/create', 'PublicFormController@create');
            $router->get('files/{uuid}', 'FileController@downloadPublicForm');
            $router->post('tmpimages', 'FileController@uploadTempImagePublicForm');
            $router->get('tmpfiles/{uuid}', 'FileController@downloadTempFilePublicForm');
        });


        // Append Plugin public ----------------------------------------------------
        $public_form = PublicForm::getPublicFormByRequest();
        if (!$public_form) {
            return;
        }

        foreach ($public_form->getCssJsPlugins() as $plugin) {
            $this->pluginScriptStyleRoute($plugin, $public_form->getBasePath(), 'publicform_plugin_public');
        }
    }
}
