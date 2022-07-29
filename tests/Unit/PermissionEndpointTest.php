<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Auth\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\LoginUser;

class PermissionEndpointTest extends UnitTestBase
{
    public const EndpointsPass = [
        '',
        '/',
        'api/column',
        'api/data',
        'api/document',
        'api/files',
        'api/me',
        'api/notify',
        'api/notifyPage',
        'api/table',
        'api/target_table',
        'api/template',
        'api/user_organization',
        'api/version',
        'api/wf',
        'auth-2factor',
        'auth-2factor/google',
        'auth-2factor/logout',
        'auth-2factor/verify',
        'auth/change',
        'auth/forget',
        'auth/login',
        'auth/logout',
        'auth/reset',
        'auth/setting',
        'dashboard',
        'dashboard/create',
        'dashboard/foobar',
        'dashboardbox',
        'dashboardbox/chart_axis',
        'dashboardbox/create',
        'dashboardbox/delete',
        'dashboardbox/foobar',
        'dashboardbox/html',
        'dashboardbox/table_views',
        'files/foobar',
        'initialize',
        'initialize/filedelete',
        'install',
        'notify_navbar',
        'notify_navbar/create',
        'notify_navbar/foobar',
        'notify_navbar/rowcheck',
        'notify_navbar/rowdetail',
        'oauth/authorize',
        'oauth/clients',
        'oauth/personal-access-tokens',
        'oauth/scopes',
        'oauth/token',
        'oauth/tokens',
        'search',
        'search/header',
        'search/list',
        'search/relation',
        'webapi/column',
        'webapi/data',
        'webapi/document',
        'webapi/files',
        'webapi/foobar',
        'webapi/me',
        'webapi/menu',
        'webapi/notify',
        'webapi/notifyPage',
        'webapi/table',
        'webapi/target_table',
        'webapi/template',
        'webapi/user_organization',
        'webapi/version',
        'webapi/wf',

        'http://github.com/exceedone/exment',
        'https://github.com/exceedone/exment',
    ];

    public const EndpointsDeny = [
        'api_setting',
        'api_setting/create',
        'api_setting/foobar',
        'auth/permissions',
        'auth/roles',
        'auth/users',
        'auth/logs',
        'auth/menu',
        'backup',
        'backup/delete',
        'backup/download',
        'backup/import',
        'backup/importModal',
        'backup/restore',
        'backup/save',
        'backup/setting',
        'loginuser',
        'loginuser/1',
        'loginuser/import',
        'loginuser/importModal',
        'notify',
        'notify/create',
        'notify/foobar',
        'notify/notify_action_target',
        'notify/notify_action_target_workflow',
        'notify/notifytrigger_template',
        'notify/targetcolumn',
        'plugin',
        'plugin/create',
        'plugin/foobar',
        'plugins/foobar',
        'role',
        'role_group',
        'role_group/create',
        'role_group/foobar',
        'system',
        'system/2factor',
        'system/2factor-verify',
        'system/filedelete',
        'system/send_testmail',
        'system/update',
        'system/version',
        'table',
        'table/create',
        'template',
        'template/export',
        'template/import',
        'workflow',
        'workflow/beginning',
        'workflow/create',
        'workflow/foobar',

        'table/information',
        'column/information',
        'copy/information',
        'form/information',
        'formpriority/information',
        'operation/information',
        'relation/information',

        'data/information',
        'view/information',

        'view/mail_template',
        'data/mail_template',
    ];

    public const EndpointsPassNoPermission = [
        'data/information',
        'view/information',
    ];


    protected function init()
    {
        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
    }

    /**
     * Execute permission check
     *
     * @return void
     */
    public function testPermissionAnonymousPass()
    {
        $this->init();

        foreach (static::EndpointsPass as $endpoint) {
            $this->executeTestPermission($endpoint, true);
            $this->executeTestPermission("$endpoint?foo=1", true);
        }
    }

    public function testPermissionAnonymousDeny()
    {
        $this->init();

        foreach (static::EndpointsDeny as $endpoint) {
            $this->executeTestPermission($endpoint, false);
        }
    }

    /**
     * Execute permission check
     *
     * @return void
     */
    public function testPermissionNoPermissionPass()
    {
        $this->init();
        $this->be(LoginUser::find(9)); // dev2-userE
        $user = \Exment::user();

        $array = array_merge(
            static::EndpointsPass,
            static::EndpointsPassNoPermission
        );

        foreach ($array as $endpoint) {
            $this->executeTestPermissionUser($endpoint, true, $user);
        }
    }

    public function testPermissionNoPermissionDeny()
    {
        $this->init();
        $this->be(LoginUser::find(9)); // dev2-userE
        $user = \Exment::user();

        $array = array_remove(static::EndpointsDeny, static::EndpointsPassNoPermission);

        foreach ($array as $endpoint) {
            $this->executeTestPermissionUser($endpoint, false, $user);
        }
    }


    /**
     * Execute permission check
     *
     * @return void
     */
    public function testPermissionAllEditPass()
    {
        $this->init();
        $this->be(LoginUser::find(3)); // manage
        $user = \Exment::user();

        $array = array_merge(
            static::EndpointsPass,
            static::EndpointsPassNoPermission
        );

        foreach ($array as $endpoint) {
            $this->executeTestPermissionUser($endpoint, true, $user);
        }
    }

    public function testPermissionAllEditDeny()
    {
        $this->init();
        $this->be(LoginUser::find(3)); // manage
        $user = \Exment::user();

        $array = array_remove(static::EndpointsDeny, static::EndpointsPassNoPermission);

        foreach ($array as $endpoint) {
            $this->executeTestPermissionUser($endpoint, false, $user);
        }
    }

    /**
     * Execute permission check
     *
     * @return void
     */
    public function testPermissionAdminPass()
    {
        $this->init();
        $this->be(LoginUser::find(1)); // admin
        $user = \Exment::user();

        $array = array_merge(
            static::EndpointsPass,
            static::EndpointsDeny
        );

        foreach ($array as $endpoint) {
            $this->executeTestPermissionUser($endpoint, true, $user);
        }
    }




    protected function executeTestPermission(string $endpoint, bool $expectResult)
    {
        $permission = new Permission([
            'role_type' => RoleType::SYSTEM,
        ]);

        $isPass = $permission->shouldPassEndpoint($endpoint);
        $this->assertTrue($expectResult ? $isPass : !$isPass, "Endpoint {$endpoint}, expect Permission is " . ($expectResult ? 'Pass' : 'Deny') . ", but result is "  . ($expectResult ? 'Deny' : 'Pass'));
    }

    protected function executeTestPermissionUser(string $endpoint, bool $expectResult, LoginUser $user)
    {
        $isPass = $user->visible($endpoint);
        $this->assertTrue($expectResult ? $isPass : !$isPass, "Endpoint {$endpoint}, expect Permission is " . ($expectResult ? 'Pass' : 'Deny') . ", but result is "  . ($expectResult ? 'Deny' : 'Pass'));
    }
}
