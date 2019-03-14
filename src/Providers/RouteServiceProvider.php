<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Controllers\BackupController;

class RouteServiceProvider extends ServiceProvider
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
        $this->mapExmentWebRotes();
        $this->mapExmentAnonymousWebRotes();
        $this->mapExmentApiRotes();
    }

    /**
     * Web web routes
     */
    protected function mapExmentWebRotes()
    {
        Route::group([
            'prefix'        => config('admin.route.prefix'),
            'namespace'     => $this->namespace,
            'middleware'    => config('admin.route.middleware'),
        ], function (Router $router) {
            $router->get('/', 'DashboardController@home');
            $router->get('dashboardbox/html/{suuid}', 'DashboardBoxController@getHtml');
            $router->delete('dashboardbox/delete/{suuid}', 'DashboardBoxController@delete');
            $router->resource('dashboard', 'DashboardController');
            $router->get("dashboardbox/table_views", 'DashboardBoxController@tableViews');
            $router->get("dashboardbox/chart_axis/{axis_type}", 'DashboardBoxController@chartAxis');
            $router->resource('dashboardbox', 'DashboardBoxController');

            $router->resource('auth/menu', 'MenuController', ['except' => ['create']]);
            $router->get('auth/setting', 'AuthController@getSetting');
            $router->put('auth/setting', 'AuthController@putSetting');
        
            $router->get('system', 'SystemController@index');
            $router->post('system', 'SystemController@post');
            $router->get('system/update', 'SystemController@updatePackage');
            $router->get('system/version', 'SystemController@version');
            
            $router->get('template', 'TemplateController@index');
            $router->post('template/import', 'TemplateController@import');
            $router->post('template/export', 'TemplateController@export');
            $router->get('template/import', function () {
                return redirect(admin_url('template'));
            });
            $router->get('template/export', function () {
                return redirect(admin_url('template'));
            });
            
            $router->get('notify/targetcolumn', 'NotifyController@targetcolumn');
            $router->get('notify/notify_action_target', 'NotifyController@notify_action_target');
            $router->resource('notify', 'NotifyController', ['except' => ['show']]);

            $router->resource('plugin', 'PluginController', ['except' => ['show']]);
            $router->resource('role', 'RoleController', ['except' => ['show']]);
            $router->resource('table', 'CustomTableController', ['except' => ['show']]);
            $router->post("loginuser/import", 'LoginUserController@import');
            $router->resource('loginuser', 'LoginUserController', ['except'=> ['create']]);
            $router->resource('mail', 'MailTemplateController', ['except' => ['show']]);
        
            $router->get('search', 'SearchController@index');
            $router->post('search/list', 'SearchController@getList');
            $router->post('search/header', 'SearchController@header');
            $router->post('search/relation', 'SearchController@getRelationList');
        
            $router->get('backup', 'BackupController@index');
            $router->delete('backup/delete', 'BackupController@delete');
            $router->post('backup/restore', 'BackupController@restore');
            $router->post('backup/save', 'BackupController@save');
            $router->post('backup/setting', 'BackupController@postSetting');
            $router->post('backup/import', 'BackupController@import');
            $router->get('backup/download/{ymdhms}', function ($ymdhms) {
                return BackupController::download($ymdhms);
            });
            // set static name. because this function is called composer install.
            try {
                if (hasTable(SystemTableName::CUSTOM_TABLE)) {
                    foreach (CustomTable::all()->pluck('table_name') as $value) {
                        $router->post("data/{$value}/import", 'CustomValueController@import');
                        $router->post("data/{$value}/pluginClick", 'CustomValueController@pluginClick');
                        $router->get("data/{$value}/{id}/compare", 'CustomValueController@compare');
                        $router->get("data/{$value}/{id}/compareitem", 'CustomValueController@compareitem');
                        $router->post("data/{$value}/{id}/compare", 'CustomValueController@restoreRevision');
                        $router->post("data/{$value}/{id}/pluginClick", 'CustomValueController@pluginClick');
                        $router->post("data/{$value}/{id}/copyClick", 'CustomValueController@copyClick');
                        $router->put("data/{$value}/{id}/filedelete", 'CustomValueController@filedelete');
                        $router->post("data/{$value}/{id}/fileupload", 'CustomValueController@fileupload');
                        $router->resource("data/{$value}", 'CustomValueController');
                        
                        $router->resource("column/{$value}", 'CustomColumnController', ['except' => ['show']]);
                        
                        $router->resource("form/{$value}", 'CustomFormController', ['except' => ['show']]);
                        
                        $router->post("view/{$value}/filterDialog", 'CustomViewController@getFilterDialogHtml');
                        $router->get("view/{$value}/filter-condition", 'CustomViewController@getFilterCondition');
                        $router->get("view/{$value}/summary-condition", 'CustomViewController@getSummaryCondition');
                        $router->resource("view/{$value}", 'CustomViewController', ['except' => ['show']]);
                        
                        $router->resource("relation/{$value}", 'CustomRelationController', ['except' => ['show']]);
                        
                        $router->resource("copy/{$value}", 'CustomCopyController', ['except' => ['show']]);
                        
                        $router->get("navisearch/data/{$value}", 'NaviSearchController@getNaviData');
                        $router->post("navisearch/result/{$value}", 'NaviSearchController@getNaviResult');
                    }
                }
            } catch (\Exception $e) {
            }
        
            $router->get('api/table/{id}', 'ApiController@table');
            $router->get("api/target_table/columns/{id}", 'ApiController@targetBelongsColumns');
        
            $router->get('files/{uuid}', function ($uuid) {
                return File::downloadFile($uuid);
            });
            $router->delete('files/{uuid}', function ($uuid) {
                return File::deleteFile($uuid);
            });
            
            $router->get('webapi/menu/menutype', 'MenuController@menutype');
            $router->post('webapi/menu/menutargetvalue', 'MenuController@menutargetvalue');
        });
    }

    
    protected function mapExmentAnonymousWebRotes()
    {
        Route::group([
            'prefix'        => config('admin.route.prefix'),
            'namespace'     => $this->namespace,
            'middleware'    => ['web', 'admin_anonymous'],
        ], function (Router $router) {
            $router->get('initialize', 'InitializeController@index');
            $router->post('initialize', 'InitializeController@post');
            $router->get('auth/login', 'AuthController@getLoginExment');
            $router->get('auth/forget', 'ForgetPasswordController@showLinkRequestForm');
            $router->post('auth/forget', 'ForgetPasswordController@sendResetLinkEmail')->name('password.email');
            $router->get('auth/reset/{token}', 'ResetPasswordController@showResetForm');
            $router->post('auth/reset/{token}', 'ResetPasswordController@reset')->name('password.request');
            $router->post('template/search', 'TemplateController@searchTemplate');

            // get config about login provider
            $login_providers = config('exment.login_providers');
            if (!is_nullorempty($login_providers)) {
                $router->get('auth/login/{provider}', 'AuthController@getLoginProvider');
                $router->get('auth/login/{provider}/callback', 'AuthController@callbackLoginProvider');
            }
        });
    }
    
    protected function mapExmentApiRotes()
    {
        // define adminapi(for webapi), api(for web)
        $routes = [
            ['prefix' => url_join(config('admin.route.prefix'), 'webapi'), 'middleware' => ['web', 'adminapi']],
        ];
        
        if (boolval(config('exment.api'))) {
            $routes[] = ['prefix' => url_join(config('admin.route.prefix'), 'api'), 'middleware' => ['api', 'adminapi']];
        }

        foreach ($routes as $route) {
            Route::group([
                'prefix' => array_get($route, 'prefix'),
                'namespace'     => $this->namespace,
                'middleware'    => array_get($route, 'middleware'),
            ], function (Router $router) {
                // set static name. because this function is called composer install.
                try {
                    if (hasTable(SystemTableName::CUSTOM_TABLE)) {
                        foreach (CustomTable::all()->pluck('table_name') as $value) {
                            $router->get("data/{$value}", 'ApiTableController@list');
                            $router->post("data/{$value}", 'ApiTableController@createData');
                            $router->put("data/{$value}/{key}", 'ApiTableController@updateData');
                            $router->get("data/{$value}/search", 'ApiTableController@search');
                            $router->get("data/{$value}/relatedLinkage", 'ApiTableController@relatedLinkage');
                            $router->get("data/{$value}/{id}", 'ApiTableController@find');
                            $router->post("data/{$value}/{id}", 'ApiTableController@find');
                        }
                    }
                } catch (\Exception $e) {
                }
    
                $router->get("version", function () {
                    return (new \Exceedone\Exment\Exment)->version();
                });
                $router->get("table/{id}", 'ApiController@table');
                $router->get("target_table/columns/{id}", 'ApiController@targetBelongsColumns');
            });
        }
    }
}
