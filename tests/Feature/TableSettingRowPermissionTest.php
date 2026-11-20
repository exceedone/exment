<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The settings of a table (columns, relations, copies, operations, forms, public forms, notifies,
 * views) are changed and deleted through a URL with the table in it: admin/{setting}/{table}/{id}.
 * The route checks the permission on the table of the URL; the row has to be of that table too,
 * and a view for everyone (system view) needs the permission of system views.
 *
 * user2 of the test data may only read the records of custom_value_view_all.
 */
class TableSettingRowPermissionTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    /**
     * Laravel keeps the controller it made for a route; Exment's controllers read the table of
     * the request when they are made. Every request gets new controllers here, as in production.
     *
     * @param string $method
     * @param string $uri
     * @param array<mixed> $parameters
     * @param array<mixed> $cookies
     * @param array<mixed> $files
     * @param array<mixed> $server
     * @param string|null $content
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $route->flushController();
        }
        System::clearRequestSession();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function actAs(string $login_id): void
    {
        $user = LoginUser::find($login_id);
        $this->assertInstanceOf(LoginUser::class, $user);
        $this->be($user);
        System::clearCache();
        System::clearRequestSession();
    }

    protected function makeView(string $table_name, int $view_type): CustomView
    {
        return CustomView::create([
            'custom_table_id' => CustomTable::getEloquent($table_name)->id,
            'view_view_name' => 'row check view',
            'view_type' => $view_type,
            'view_kind_type' => ViewKindType::DEFAULT,
        ]);
    }

    public function testAViewOfAnotherTableIsNotDeletedThroughThisTable(): void
    {
        $other = $this->makeView(TestDefine::TESTDATA_TABLE_NO_PERMISSION, ViewType::SYSTEM);

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->delete(admin_urls('view', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $other->id), [], static::AJAX);

        $this->assertNotNull(CustomView::find($other->id));
    }

    public function testASystemViewIsNotDeletedByAUserWhoMayOnlyReadTheTable(): void
    {
        $view = $this->makeView(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, ViewType::SYSTEM);

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->delete(admin_urls('view', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $view->id), [], static::AJAX);

        $this->assertNotNull(CustomView::find($view->id));
    }

    public function testTheAdministratorStillDeletesAView(): void
    {
        $view = $this->makeView(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, ViewType::SYSTEM);

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->delete(admin_urls('view', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $view->id), [], static::AJAX)
            ->assertStatus(200);

        $this->assertNull(CustomView::find($view->id));
    }

    public function testANotifyOfAnotherTableIsNotDeletedThroughATableTheUserManages(): void
    {
        // user2 manages custom_value_edit_all (the table setting permission) and nothing else
        $role_group = new RoleGroup();
        $role_group->role_group_name = 'row_check_table_admin';
        $role_group->role_group_view_name = 'row check table admin';
        $role_group->save();
        RoleGroupPermission::create([
            'role_group_id' => $role_group->id,
            'role_group_permission_type' => RoleType::TABLE,
            'role_group_target_id' => CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)->id,
            'permissions' => ['custom_table'],
        ]);
        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);
        RoleGroupUserOrganization::create([
            'role_group_id' => $role_group->id,
            'role_group_user_org_type' => SystemTableName::USER,
            'role_group_target_id' => $user->base_user_id,
        ]);

        $notify = new Notify();
        $notify->notify_view_name = 'row check notify';
        $notify->notify_trigger = NotifyTrigger::BUTTON;
        $notify->target_id = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NO_PERMISSION)->id;
        $notify->action_settings = [];
        $notify->save();

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->delete(admin_urls('notify', TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL, $notify->id), [], static::AJAX);

        $this->assertNotNull(Notify::find($notify->id));
    }
}
