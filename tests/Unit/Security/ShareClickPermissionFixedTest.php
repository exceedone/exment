<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\ShareTargetType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * FIXED: GET .../shareClick — the 共有 dialog of a dashboard, a view or a record — answered
 * anyone who may view the target with the dialog, which lists the users and organizations the
 * target is shared with and their permission, while the dialog's save (sendShares) was for
 * those who may edit the target. The dialog is now for them alone, like the save.
 *
 * The data is rolled back: LoginUser 1 (system admin) owns the targets; a login user created
 * here, with a role group that may only view the table (custom_value_view), is the viewer.
 */
class ShareClickPermissionFixedTest extends SecurityRegressionTestCase
{
    public function test_the_share_dialog_of_a_dashboard_is_for_its_editors_only(): void
    {
        $viewer = $this->viewerLogin();
        $this->asUser(LoginUser::find(1));
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::USER;
        $dashboard->dashboard_name = 'sharedlg_' . short_uuid();
        $dashboard->dashboard_view_name = 'share dialog';
        $dashboard->default_flg = false;
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();
        $url = $this->url('dashboard/' . $dashboard->id . '/shareClick');
        $this->share(ShareTargetType::DASHBOARD, $dashboard->id, Permission::DATA_SHARE_VIEW, $viewer);

        // the owner opens the dialog, as before
        $this->assertDialog($this->get($url), 'data_share_edit');

        // shared for viewing: no dialog
        $this->asUser($viewer);
        $this->assertTrue(Dashboard::find($dashboard->id) !== null, 'the viewer may view the dashboard');
        $this->assertDenied($this->get($url));

        // shared for editing: the dialog again
        $this->share(ShareTargetType::DASHBOARD, $dashboard->id, Permission::DATA_SHARE_EDIT, $viewer);
        $this->asUser($viewer);
        $this->assertDialog($this->get($url), 'data_share_edit');
    }

    public function test_the_share_dialog_of_a_view_is_for_its_editors_only(): void
    {
        $viewer = $this->viewerLogin();
        [$table] = $this->tableWithRecord();
        $this->asUser(LoginUser::find(1));
        $view = new CustomView();
        $view->custom_table_id = $table->id;
        $view->view_view_name = 'share dialog';
        $view->view_type = ViewType::USER;
        $view->view_kind_type = ViewKindType::DEFAULT;
        $view->save();
        $url = $this->url('view/' . $table->table_name . '/' . $view->id . '/shareClick');
        $this->share(ShareTargetType::VIEW, $view->id, Permission::DATA_SHARE_VIEW, $viewer);

        $this->assertDialog($this->get($url), 'data_share_edit');

        $this->asUser($viewer);
        $this->assertTrue(CustomView::find($view->id) !== null, 'the viewer may view the view');
        $this->assertDenied($this->get($url));

        // a view of another table is not this table's to share (as sendShares answers)
        $this->asUser(LoginUser::find(1));
        $this->assertDenied($this->get($this->url('view/' . SystemTableName::USER . '/' . $view->id . '/shareClick')));

        $this->share(ShareTargetType::VIEW, $view->id, Permission::DATA_SHARE_EDIT, $viewer);
        $this->asUser($viewer);
        $this->assertDialog($this->get($url), 'data_share_edit');
    }

    public function test_the_share_dialog_of_a_record_is_for_those_who_may_edit_and_share_it(): void
    {
        $viewer = $this->viewerLogin();
        [$table, $record] = $this->tableWithRecord();
        $this->grantView($viewer, $table);
        $url = $this->url('data/' . $table->table_name . '/' . $record->id . '/shareClick');

        $this->asUser(LoginUser::find(1));
        $this->assertDialog($this->get($url), 'custom_value_edit');

        $this->asUser($viewer);
        $this->assertTrue($table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE), 'the viewer may view the table');
        $this->assertFalse($table->hasPermissionEditData($record->id), 'but not edit the record');
        $this->assertDenied($this->get($url));
    }

    /**
     * The dialog came back: its form field is in the body.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertDialog(TestResponse $response, string $field): void
    {
        $response->assertStatus(200);
        $this->assertTrue($response->json('result'));
        $this->assertStringContainsString('name="' . $field . '[]"', (string) $response->json('body'));
    }

    /**
     * No dialog: the deny answer of the dialog's save, nothing of the form.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertDenied(TestResponse $response): void
    {
        $response->assertStatus(400);
        $this->assertFalse($response->json('result'));
        $this->assertSame(trans('admin.deny'), $response->json('toastr'));
        $this->assertStringNotContainsString('data_share_modal', (string) $response->getContent());
    }

    /**
     * Shares the dashboard / view with the login user's user.
     */
    private function share(string $targetType, int $targetId, string $permission, LoginUser $login): void
    {
        DB::table('data_share_authoritables')->insert([
            'parent_id' => $targetId, 'parent_type' => $targetType, 'authoritable_type' => $permission,
            'authoritable_user_org_type' => SystemTableName::USER, 'authoritable_target_id' => $login->base_user_id,
        ]);
    }

    /**
     * A role group that may only view $table's records, for the login user's user.
     */
    private function grantView(LoginUser $login, CustomTable $table): void
    {
        $name = 'sharedlg_' . short_uuid();
        $group = RoleGroup::create(['role_group_name' => $name, 'role_group_view_name' => $name, 'role_group_order' => 0]);
        RoleGroupPermission::create([
            'role_group_id' => $group->id, 'role_group_permission_type' => RoleType::TABLE,
            'role_group_target_id' => $table->id, 'permissions' => [Permission::CUSTOM_VALUE_VIEW],
        ]);
        RoleGroupUserOrganization::create([
            'role_group_id' => $group->id, 'role_group_user_org_type' => SystemTableName::USER, 'role_group_target_id' => $login->base_user_id,
        ]);
    }

    /**
     * A login user created here, in no role group.
     */
    private function viewerLogin(): LoginUser
    {
        $this->asUser(LoginUser::find(1));
        $code = 'sharedlg_' . short_uuid();
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
        $user->setValue(['user_code' => $code, 'user_name' => $code, 'email' => $code . '@example.com']);
        $user->saved_notify(false);
        $user->save();
        $login = new LoginUser();
        $login->base_user_id = $user->id;
        $login->password = 'sharedlg_password';
        $login->save();
        return $login;
    }

    /**
     * The first custom table (no system table) with a record and a physical table.
     *
     * @return array{0: CustomTable, 1: CustomValue}
     */
    private function tableWithRecord(): array
    {
        $this->asUser(LoginUser::find(1));
        $schema = DB::connection()->getSchemaBuilder();
        foreach (CustomTable::orderBy('id')->get() as $table) {
            if (boolval($table->system_flg) || !$schema->hasTable(getDBTableName($table))) {
                continue;
            }
            $record = $table->getValueModel()->first();
            if ($record instanceof CustomValue) {
                return [$table, $record];
            }
        }
        $this->markTestSkipped('needs a custom table with a record');
    }

    private function asUser(?LoginUser $login): void
    {
        if ($login === null) {
            $this->markTestSkipped('needs LoginUser 1 in the dev DB');
        }
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->actingAs($login, 'admin')->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function url(string $path): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/' . $path;
    }
}
