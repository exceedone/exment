<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\SystemTableName;

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
        $this->mapExmentInstallWebRotes();
        $this->mapExmentApiRotes();
        $this->mapExmentAnonymousApiRotes();
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
            $router->get("dashboardbox/table_views/{dashboard_type}", 'DashboardBoxController@tableViews');
            $router->get("dashboardbox/chart_axis/{axis_type}", 'DashboardBoxController@chartAxis');
            $router->resource('dashboardbox', 'DashboardBoxController');
        
            $router->resource('auth/logs', 'LogController', ['except' => ['create', 'edit']]);
            $router->resource('auth/menu', 'MenuController', ['except' => ['create']]);
            $router->put('auth/setting/filedelete', 'AuthController@filedelete');
            $router->get('auth/setting', 'AuthController@getSetting');
            $router->put('auth/setting', 'AuthController@putSetting');
        
            $router->get('system', 'SystemController@index');
            $router->post('system', 'SystemController@post');
            $router->get('system/update', 'SystemController@updatePackage');
            $router->put('system/filedelete', 'SystemController@filedelete');
            $router->get('system/version', 'SystemController@version');
            $router->post('system/2factor-verify', 'SystemController@auth_2factor_verify');
            $router->post('system/2factor', 'SystemController@post2factor');
            $router->post('system/send_testmail', 'SystemController@sendTestMail');
            
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
            $router->get('notify/notify_action_target_workflow', 'NotifyController@notify_action_target_workflow');
            $router->post('notify/notifytrigger_template', 'NotifyController@getNotifyTriggerTemplate');
            $router->resource('notify', 'NotifyController', ['except' => ['show']]);
            $router->resource('notify_navbar', 'NotifyNavbarController', ['except' => ['edit']]);
            $router->get("notify_navbar/rowdetail/{id}", 'NotifyNavbarController@redirectTargetData');
            $router->post("notify_navbar/rowcheck/{id}", 'NotifyNavbarController@rowCheck');

            $router->resource('login_setting', 'LoginSettingController', ['except' => ['show']]);
            $router->post('login_setting/postglobal', 'LoginSettingController@postGlobal');
            $router->resource('api_setting', 'ApiSettingController', ['except' => ['show']]);
            $router->resource('plugin', 'PluginController', ['except' => ['show']]);
            $router->resource('role_group', 'RoleGroupController', ['except' => ['show']]);
            $router->resource('table', 'CustomTableController', ['except' => ['show']]);
            
            $router->resource('workflow', 'WorkflowController', ['except' => ['show']]);
            $router->get("workflow/beginning", 'WorkflowController@beginningForm');
            $router->post("workflow/beginning", 'WorkflowController@beginningPost');
            $router->post('workflow/{id}/modal/target', 'WorkflowController@targetModal');
            $router->post('workflow/{id}/modal/condition', 'WorkflowController@conditionModal');
            $router->get("workflow/{id}/filter-value", 'WorkflowController@getFilterValue');
            $router->post('workflow/{id}/activate', 'WorkflowController@activate');
            $router->get('workflow/{id}/activateModal', 'WorkflowController@activateModal');

            $router->get("loginuser/importModal", 'LoginUserController@importModal');
            $router->post("loginuser/import", 'LoginUserController@import');
            $router->resource('loginuser', 'LoginUserController', ['except'=> ['create']]);
            
            $router->get('role', function () {
                return redirect(admin_urls('role_group'));
            });
            
            $router->get('search', 'SearchController@index');
            $router->get('search/list', 'SearchController@getList');
            $router->get('search/header', 'SearchController@header');
            $router->get('search/relation', 'SearchController@getRelationList');
        
            $router->get('backup', 'BackupController@index');
            $router->delete('backup/delete', 'BackupController@delete');
            $router->post('backup/restore', 'BackupController@restore');
            $router->post('backup/save', 'BackupController@save');
            $router->post('backup/setting', 'BackupController@postSetting');
            $router->post('backup/import', 'BackupController@import');
            $router->get('backup/importModal', 'BackupController@importModal');
            $router->get('backup/download/{ymdhms}', 'BackupController@download');
        
            $router->get("data/{tableKey}/importModal", 'CustomValueController@importModal');
            $router->post("data/{tableKey}/import", 'CustomValueController@import');
            $router->post("data/{tableKey}/pluginClick", 'CustomValueController@pluginClick');
            $router->get("data/{tableKey}/{id}/compare", 'CustomValueController@compare');
            $router->get("data/{tableKey}/{id}/compareitem", 'CustomValueController@compareitem');
            $router->post("data/{tableKey}/{id}/compare", 'CustomValueController@restoreRevision');
            $router->post("data/{tableKey}/{id}/pluginClick", 'CustomValueController@pluginClick');
            $router->get("data/{tableKey}/{id}/actionModal", 'CustomValueController@actionModal');
            $router->post("data/{tableKey}/{id}/actionClick", 'CustomValueController@actionClick');
            $router->get("data/{tableKey}/{id}/notifyClick", 'CustomValueController@notifyClick');
            $router->get("data/{tableKey}/{id}/shareClick", 'CustomValueController@shareClick');
            $router->get("data/{tableKey}/{id}/workflowHistoryModal", 'CustomValueController@workflowHistoryModal');
            $router->post("data/{tableKey}/{id}/sendMail", 'CustomValueController@sendMail');
            $router->post("data/{tableKey}/{id}/sendTargetUsers", 'CustomValueController@sendTargetUsers');
            $router->post("data/{tableKey}/{id}/sendShares", 'CustomValueController@sendShares');
            $router->get("data/{tableKey}/{id}/copyModal", 'CustomValueController@copyModal');
            $router->post("data/{tableKey}/{id}/copyClick", 'CustomValueController@copyClick');
            $router->get("data/{tableKey}/{id}/restoreClick", 'CustomValueController@restoreClick');
            $router->post("data/{tableKey}/rowRestore", 'CustomValueController@rowRestore');
            $router->put("data/{tableKey}/{id}/filedelete", 'CustomValueController@filedelete');
            $router->post("data/{tableKey}/{id}/fileupload", 'CustomValueController@fileupload');
            $router->post("data/{tableKey}/{id}/addcomment", 'CustomValueController@addComment');
            $router->post("data/{tableKey}/{id}/rowUpdate/{rowid}", 'CustomValueController@rowUpdate');

            $router->get("view/{tableKey}/filter-condition", 'CustomViewController@getFilterCondition');
            $router->get("view/{tableKey}/summary-condition", 'CustomViewController@getSummaryCondition');
            $router->get("view/{tableKey}/group-condition", 'CustomViewController@getGroupCondition');
            $router->get("view/{tableKey}/filter-value", 'CustomViewController@getFilterValue');

            $router->post("column/{tableKey}/calcModal", 'CustomColumnController@calcModal');
            $router->post("column/{tableKey}/{id}/calcModal", 'CustomColumnController@calcModal');
            $router->get("copy/{tableKey}/newModal", 'CustomCopyController@newModal');

            $router->get("operation/{tableKey}/filter-value", 'CustomOperationController@getFilterValue');
        
            $router->get('files/{uuid}', 'FileController@download');
            $router->get('files/{tableKey}/{uuid}', 'FileController@downloadTable');
            
            $router->delete('files/{uuid}', 'FileController@delete');
            $router->delete('files/{tableKey}/{uuid}', 'FileController@deleteTable');
            
            $this->setTableResouce($router, 'data', 'CustomValueController', true);
            $this->setTableResouce($router, 'column', 'CustomColumnController');
            $this->setTableResouce($router, 'form', 'CustomFormController');
            $this->setTableResouce($router, 'formpriority', 'CustomFormPriorityController');
            $this->setTableResouce($router, 'view', 'CustomViewController');
            $this->setTableResouce($router, 'relation', 'CustomRelationController');
            $this->setTableResouce($router, 'copy', 'CustomCopyController');
            $this->setTableResouce($router, 'operation', 'CustomOperationController');

            // only webapi api function
            $router->get('webapi/menu/menutype', 'MenuController@menutype');
            $router->post('webapi/menu/menutargetvalue', 'MenuController@menutargetvalue');
            $router->get('webapi/menu/menutargetview', 'MenuController@menutargetview');

            $router->get("webapi/{tableKey}/filter-condition", 'ApiTableController@getFilterCondition');
            $router->get("webapi/{tableKey}/filter-value", 'ApiTableController@getFilterValue');
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
            $router->put('initialize/filedelete', 'InitializeController@filedelete');
            $router->get('auth/login', 'AuthController@getLoginExment');
            $router->get('auth/logout', 'AuthController@getLogout')->name('logout');
            $router->post('auth/login', 'AuthController@postLogin');
            $router->get('auth/forget', 'ForgetPasswordController@showLinkRequestForm');
            $router->post('auth/forget', 'ForgetPasswordController@sendResetLinkEmail')->name('password.email');
            $router->get('auth/reset/{token}', 'ResetPasswordController@showResetForm');
            $router->post('auth/reset/{token}', 'ResetPasswordController@reset')->name('password.request');
            $router->get('auth/change', 'ChangePasswordController@showChangeForm');
            $router->post('auth/change', 'ChangePasswordController@change');
            $router->get('favicon', 'FileController@downloadFavicon');

            // get config about login provider
            if (canConnection() && hasTable(SystemTableName::LOGIN_SETTINGS)) {
                if (LoginSetting::getOAuthSettings()->count() > 0) {
                    $router->get('auth/login/{provider}', 'AuthOAuthController@getLoginProvider')->name('oauth_login');
                    $router->get('auth/login/{provider}/callback', 'AuthOAuthController@callback')->name('oauth_callback');
                }
                // get config about login provider
                if (LoginSetting::getSamlSettings()->count() > 0) {
                    $router->get('saml/logout', 'AuthSamlController@sls')->name('saml_sls');
                    $router->get('saml/login/{provider}', 'AuthSamlController@login')->name('saml_login');
                    $router->get('saml/login/{provider}/metadata', 'AuthSamlController@metadata');
                    $router->post('saml/login/{provider}/acs', 'AuthSamlController@acs')->name('saml_acs');
                }
            }
        });
    }
    
    protected function mapExmentInstallWebRotes()
    {
        Route::group([
            'prefix'        => config('admin.route.prefix'),
            'namespace'     => $this->namespace,
            'middleware'    => ['web', 'admin_install'],
        ], function (Router $router) {
            $router->get('install', 'InstallController@index');
            $router->post('install', 'InstallController@post');
        });
    }
    
    protected function mapExmentApiRotes()
    {
        // define adminapi(for webapi), api(for web)
        $routes = [
            ['type' => 'webapi', 'prefix' => url_join(config('admin.route.prefix'), 'webapi'), 'middleware' => ['web', 'adminwebapi'], 'addScope' => false],
        ];
        
        if (canConnection() && hasTable(SystemTableName::SYSTEM) && System::api_available()) {
            $routes[] = ['type' => 'api', 'prefix' => url_join(config('admin.route.prefix'), 'api'), 'middleware' => ['api', 'adminapi'], 'addScope' => true];
        }

        foreach ($routes as $route) {
            Route::group([
                'prefix' => array_get($route, 'prefix'),
                'namespace'     => $this->namespace,
                'middleware'    => array_get($route, 'middleware'),
            ], function (Router $router) use ($route) {
                // value --------------------------------------------------
                $router->get("data/{tableKey}", 'ApiTableController@dataList')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_READ, ApiScope::VALUE_WRITE));
                $router->get("data/{tableKey}/query-column", 'ApiTableController@dataQueryColumn')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_READ, ApiScope::VALUE_WRITE));
                $router->get("data/{tableKey}/query", 'ApiTableController@dataQuery')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_READ, ApiScope::VALUE_WRITE));
                $router->get("data/{tableKey}/select", 'ApiTableController@dataSelect')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_READ, ApiScope::VALUE_WRITE));
                $router->get("data/{tableKey}/relatedLinkage", 'ApiTableController@relatedLinkage')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_READ, ApiScope::VALUE_WRITE));
                $router->get("data/{tableKey}/calendar", 'ApiTableController@calendarList')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_READ, ApiScope::VALUE_WRITE));
                $router->get("data/{tableKey}/{id}", 'ApiTableController@dataFind')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_READ, ApiScope::VALUE_WRITE));
                $router->post("data/{tableKey}/{id}", 'ApiTableController@dataFind')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_READ, ApiScope::VALUE_WRITE));
                $router->post("data/{tableKey}", 'ApiTableController@dataCreate')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_WRITE));
                $router->put("data/{tableKey}/{id}", 'ApiTableController@dataUpdate')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_WRITE));
                $router->delete("data/{tableKey}/{id}", 'ApiTableController@dataDelete')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_WRITE));
                $router->get("data/{tableKey}/column/{column_name}", 'ApiTableController@columnData')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_READ, ApiScope::VALUE_WRITE));


                // file, document --------------------------------------------------
                $router->get('files/{uuid}', 'FileController@downloadApi')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_READ, ApiScope::VALUE_WRITE));
                $router->get('files/{tableKey}/{uuid}', 'FileController@downloadTableApi')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_READ, ApiScope::VALUE_WRITE));
                $router->delete('files/{uuid}', 'FileController@deleteApi')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_WRITE));
                $router->delete('files/{tableKey}/{uuid}', 'FileController@deleteTableApi')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_WRITE));

                $router->get("document/{tableKey}/{id}", 'ApiTableController@getDocuments')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_READ, ApiScope::VALUE_WRITE));
                $router->post("document/{tableKey}/{id}", 'ApiTableController@createDocument')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_WRITE));


                // table --------------------------------------------------
                $router->get("table", 'ApiController@tablelist')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::TABLE_READ));
                $router->get("table/columns", 'ApiController@columns')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::TABLE_READ));
                $router->get("table/indexcolumns", 'ApiController@indexcolumns')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::TABLE_READ));
                $router->get("table/filterviews", 'ApiController@filterviews')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::TABLE_READ));
                $router->get("table/{tableKey}", 'ApiController@table')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::TABLE_READ));
                $router->get("table/{tableKey}/columns", 'ApiTableController@tableColumns')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::TABLE_READ));
                $router->get("column/{id}", 'ApiController@column')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::TABLE_READ));
                $router->get("target_table/columns/{id}", 'ApiController@targetBelongsColumns')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::TABLE_READ));
                
                // System --------------------------------------------------
                $router->get("version", 'ApiController@version');

                $router->get("notifyPage", 'ApiController@notifyPage')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::NOTIFY_READ));
                $router->get("notify", 'ApiController@notifyList')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::NOTIFY_READ, ApiScope::NOTIFY_WRITE));
                $router->post("notify", 'ApiController@notifyCreate')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::NOTIFY_WRITE));

                // User, LoginUser --------------------------------------------------
                $router->get("me", 'ApiController@me')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::ME));

                // User, Organization --------------------------------------------------
                $router->get("user_organization/select", 'ApiController@userOrganizationSelect')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::VALUE_READ, ApiScope::VALUE_WRITE));
                
                // Workflow --------------------------------------------------
                $router->get("wf/workflow", 'ApiWorkflowController@getList')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::WORKFLOW_READ, ApiScope::WORKFLOW_EXECUTE));
                $router->get("wf/workflow/{id}", 'ApiWorkflowController@get')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::WORKFLOW_READ, ApiScope::WORKFLOW_EXECUTE));
                $router->get("wf/workflow/{id}/statuses", 'ApiWorkflowController@workflowStatus')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::WORKFLOW_READ, ApiScope::WORKFLOW_EXECUTE));
                $router->get("wf/workflow/{id}/actions", 'ApiWorkflowController@workflowAction')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::WORKFLOW_READ, ApiScope::WORKFLOW_EXECUTE));
                $router->get("wf/status/{id}", 'ApiWorkflowController@status')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::WORKFLOW_READ, ApiScope::WORKFLOW_EXECUTE));
                $router->get("wf/action/{id}", 'ApiWorkflowController@action')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::WORKFLOW_READ, ApiScope::WORKFLOW_EXECUTE));
                $router->get("wf/data/{tableKey}/{id}/value", 'ApiWorkflowController@getValue')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::WORKFLOW_READ, ApiScope::WORKFLOW_EXECUTE));
                $router->get("wf/data/{tableKey}/{id}/work_users", 'ApiWorkflowController@getWorkUsers')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::WORKFLOW_READ, ApiScope::WORKFLOW_EXECUTE));
                $router->get("wf/data/{tableKey}/{id}/actions", 'ApiWorkflowController@getActions')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::WORKFLOW_READ, ApiScope::WORKFLOW_EXECUTE));
                $router->get("wf/data/{tableKey}/{id}/histories", 'ApiWorkflowController@getHistories')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::WORKFLOW_READ, ApiScope::WORKFLOW_EXECUTE));
                $router->post("wf/data/{tableKey}/{id}/value", 'ApiWorkflowController@execute')->middleware(ApiScope::getScopeString($route['addScope'], ApiScope::WORKFLOW_EXECUTE));
            });
        }
    }

    /**
     * define api and anonynous routes
     */
    protected function mapExmentAnonymousApiRotes()
    {
        // define adminapi(for webapi), api(for web)
        $routes = [
            ['prefix' => url_join(config('admin.route.prefix'), 'webapi'), 'middleware' => ['web', 'adminapi_anonymous']],
            ['prefix' => url_join(config('admin.route.prefix'), 'api'), 'middleware' => ['api', 'adminapi_anonymous']],
        ];
        
        foreach ($routes as $route) {
            Route::group([
                'prefix' => array_get($route, 'prefix'),
                'namespace'     => $this->namespace,
                'middleware'    => array_get($route, 'middleware'),
            ], function (Router $router) use ($route) {
                $router->post('template/search', 'TemplateController@searchTemplate');
                $router->delete('template/delete', 'TemplateController@delete');
            });
        }
    }
    
    /**
     * set table resource.
     * (We cannot create endpoint using resouce function if contains {tableKey}).
     */
    protected function setTableResouce($router, $endpointName, $controllerName, $isShow = false)
    {
        $router->get("{$endpointName}/{tableKey}", "$controllerName@index");
        $router->get("{$endpointName}/{tableKey}/create", "$controllerName@create");
        $router->post("{$endpointName}/{tableKey}", "$controllerName@store");
        $router->get("{$endpointName}/{tableKey}/{id}/edit", "$controllerName@edit");
        $router->put("{$endpointName}/{tableKey}/{id}", "$controllerName@update");
        $router->patch("{$endpointName}/{tableKey}/{id}", "$controllerName@update");
        $router->delete("{$endpointName}/{tableKey}/{id}", "$controllerName@destroy");

        if ($isShow) {
            $router->get("{$endpointName}/{tableKey}/{id}", "$controllerName@show");
        }
    }
}
