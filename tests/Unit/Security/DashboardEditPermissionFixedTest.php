<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\FilterBarForm;
use Illuminate\Support\Facades\DB;

/**
 * FIXED: saving a dashboard or a dashboard box checked no edit permission — only the edit
 * screen did (DashboardController::edit). A user who may view a system dashboard but not
 * edit it could PUT admin/dashboard/{id} (its name, rows, filter bar) and PUT / POST
 * admin/dashboardbox (its boxes). Both now refuse such a request (403, shown as Exment's
 * error page) and change nothing; the system administrator still saves. Saving a box of a
 * dashboard this user may edit cannot move it into another dashboard (its posted dashboard_id).
 * Deleting through the resource routes (DELETE admin/dashboard/{id}, admin/dashboardbox/{id})
 * checked nothing either: a viewer removed a system dashboard with all its boxes. The box
 * form's ajax lists (admin/dashboardbox/table_views, chart_axis) answered the views and
 * columns of any table, now only of one this user may view (as the form's table list); so do
 * the filter bar's column choices, also shown for a table posted back on a validation error.
 * The chart box form itself (create with a table or view posted back, edit of a chart box of
 * the table) listed the views and columns of any table as well; now of one this user may view.
 *
 * The data (a system dashboard with one box) is rolled back; the requests use LoginUser 1
 * (system admin) and the first login user without the system permission.
 */
class DashboardEditPermissionFixedTest extends SecurityRegressionTestCase
{
    public function test_a_viewer_cannot_save_a_system_dashboard(): void
    {
        [$dashboard] = $this->systemDashboard();
        $this->asViewer();

        $response = $this->put($this->url('dashboard/' . $dashboard->id), $this->dashboardForm('changed by a viewer'));

        $this->assertDenied($response);
        $this->assertSame('system dashboard', Dashboard::withoutGlobalScopes()->where('id', $dashboard->id)->value('dashboard_view_name'));
    }

    public function test_a_viewer_cannot_save_or_add_a_box_of_a_system_dashboard(): void
    {
        [$dashboard, $box] = $this->systemDashboard();
        $this->asViewer();

        $response = $this->put($this->url('dashboardbox/' . $box->id), $this->boxForm($dashboard, 'changed by a viewer'));
        $this->assertDenied($response);
        $this->assertSame('box', DashboardBox::where('id', $box->id)->value('dashboard_box_view_name'));

        $before = DashboardBox::where('dashboard_id', $dashboard->id)->count();
        $response = $this->post($this->url('dashboardbox'), ['column_no' => 2] + $this->boxForm($dashboard, 'added by a viewer'));
        $this->assertDenied($response);
        $this->assertSame($before, DashboardBox::where('dashboard_id', $dashboard->id)->count());
    }

    public function test_an_editor_cannot_move_a_box_into_a_dashboard_they_may_not_edit(): void
    {
        [$system] = $this->systemDashboard();
        $this->asViewer();
        [$own, $box] = $this->dashboardWithBox(DashboardType::USER, 'own dashboard');
        $this->assertTrue($own->hasEditPermission());

        // the box's own dashboard: saved as before
        $this->put($this->url('dashboardbox/' . $box->id), $this->boxForm($own, 'own box renamed'));
        $this->assertSame('own box renamed', DashboardBox::where('id', $box->id)->value('dashboard_box_view_name'));

        // another dashboard (a system one this user only views), or no scalar id: refused
        foreach ([$system->id, [$system->id]] as $target) {
            $response = $this->put($this->url('dashboardbox/' . $box->id), ['dashboard_id' => $target] + $this->boxForm($own, 'moved'));
            $this->assertDenied($response);
            $this->assertSame((string) $own->id, (string) DashboardBox::where('id', $box->id)->value('dashboard_id'));
            $this->assertSame('own box renamed', DashboardBox::where('id', $box->id)->value('dashboard_box_view_name'));
        }
    }

    public function test_a_viewer_cannot_delete_a_system_dashboard_or_its_box(): void
    {
        [$dashboard, $box] = $this->systemDashboard();
        $this->asViewer();

        $response = $this->delete($this->url('dashboardbox/' . $box->id));
        $response->assertStatus(403);
        $this->assertSame(1, DashboardBox::where('id', $box->id)->count());

        $response = $this->delete($this->url('dashboard/' . $dashboard->id));
        $response->assertStatus(403);
        $this->assertSame(1, Dashboard::withoutGlobalScopes()->where('id', $dashboard->id)->count());
        $this->assertSame(1, DashboardBox::where('id', $box->id)->count());
    }

    public function test_the_system_administrator_still_deletes(): void
    {
        [$dashboard, $box] = $this->systemDashboard();

        $this->delete($this->url('dashboardbox/' . $box->id))->assertJson(['status' => true]);
        $this->assertSame(0, DashboardBox::where('id', $box->id)->count());

        $this->delete($this->url('dashboard/' . $dashboard->id))->assertJson(['status' => true]);
        $this->assertSame(0, Dashboard::withoutGlobalScopes()->where('id', $dashboard->id)->count());
    }

    public function test_the_box_form_lists_no_view_or_column_of_a_table_this_user_may_not_view(): void
    {
        // a table no role grants (inserted bare: saving a CustomTable would create its table,
        // whose DDL ends the rolled back transaction), with one view of one column
        $tableId = DB::table('custom_tables')->insertGetId([
            'suuid' => short_uuid(), 'table_name' => 'perm_' . uniqid(), 'table_view_name' => 'hidden table', 'options' => '{}',
        ]);
        $columnId = DB::table('custom_columns')->insertGetId([
            'suuid' => short_uuid(), 'custom_table_id' => $tableId, 'column_name' => 'secret_column', 'column_view_name' => 'secret column', 'column_type' => 'text', 'options' => '{}',
        ]);
        $viewId = DB::table('custom_views')->insertGetId([
            'suuid' => short_uuid(), 'custom_table_id' => $tableId, 'view_view_name' => 'hidden view', 'view_type' => ViewType::SYSTEM, 'view_kind_type' => ViewKindType::DEFAULT, 'options' => '{}',
        ]);
        DB::table('custom_view_columns')->insert([
            'custom_view_id' => $viewId, 'view_column_type' => 0, 'view_column_target_id' => $columnId, 'order' => 1, 'options' => '{}',
        ]);

        [$dashboard] = $this->systemDashboard();
        $chartBox = $this->chartBox($dashboard, $tableId, $viewId);
        $this->asViewer();
        $this->assertFalse(CustomTable::getEloquent($tableId)->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE));
        $tableViews = $this->url('dashboardbox/table_views/' . DashboardBoxType::CHART . '?dashboard_suuid=' . $dashboard->suuid . '&q');

        $this->get($tableViews . '=' . $tableId)->assertExactJson([]);
        $this->get($this->url('dashboardbox/chart_axis/x?q=' . $viewId))->assertExactJson([]);
        $this->get($tableViews . '[]=' . $tableId)->assertExactJson([]);
        $this->assertSame([], FilterBarForm::columnOptions((string) $tableId));

        // nor does the box form itself: create with the table or the view posted back, edit
        // of a chart box of the table (its stored table and view)
        foreach ($this->chartForms($dashboard, $chartBox, $tableId, $viewId) as $form) {
            $this->assertSame(200, $form->getStatusCode());
            $this->assertStringNotContainsString('secret_column', (string) $form->getContent());
            $this->assertStringNotContainsString('secret column', (string) $form->getContent());
            $this->assertStringNotContainsString('hidden view', (string) $form->getContent());
        }

        // the system administrator, who may view the table, still gets the lists
        $this->asUser(LoginUser::find(1));
        $this->assertSame('hidden view', $this->get($tableViews . '=' . $tableId)->json('0.text'));
        $this->assertNotEmpty($this->get($this->url('dashboardbox/chart_axis/x?q=' . $viewId))->json());
        $this->assertArrayHasKey('secret_column', FilterBarForm::columnOptions((string) $tableId));
        [$byTable, $byView, $edit] = $this->chartForms($dashboard, $chartBox, $tableId, $viewId);
        $this->assertStringContainsString('secret_column', (string) $byTable->getContent());
        $this->assertStringContainsString('secret column', (string) $byView->getContent());
        $this->assertStringContainsString('secret_column', (string) $edit->getContent());
        $this->assertStringContainsString('hidden view', (string) $edit->getContent());
    }

    public function test_the_system_administrator_still_saves(): void
    {
        [$dashboard, $box] = $this->systemDashboard();
        $this->asUser(LoginUser::find(1));

        $this->put($this->url('dashboard/' . $dashboard->id), $this->dashboardForm('renamed'));
        $this->assertSame('renamed', Dashboard::withoutGlobalScopes()->where('id', $dashboard->id)->value('dashboard_view_name'));

        $this->put($this->url('dashboardbox/' . $box->id), $this->boxForm($dashboard, 'box renamed'));
        $this->assertSame('box renamed', DashboardBox::where('id', $box->id)->value('dashboard_box_view_name'));
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardForm(string $name): array
    {
        return ['dashboard_view_name' => $name, 'row_setting' => ['row1' => 2, 'row2' => 0, 'row3' => 0, 'row4' => 0]];
    }

    /**
     * @return array<string, mixed>
     */
    private function boxForm(Dashboard $dashboard, string $name): array
    {
        return [
            'dashboard_id' => $dashboard->id, 'dashboard_box_type' => DashboardBoxType::SYSTEM, 'row_no' => 1, 'column_no' => 1,
            'dashboard_box_view_name' => $name, 'options' => ['target_system_id' => 1],
        ];
    }

    /**
     * @return array{0: Dashboard, 1: DashboardBox}
     */
    private function systemDashboard(): array
    {
        $this->asUser(LoginUser::find(1));
        return $this->dashboardWithBox(DashboardType::SYSTEM, 'system dashboard');
    }

    /**
     * A dashboard with one box, created by the current user.
     *
     * @return array{0: Dashboard, 1: DashboardBox}
     */
    private function dashboardWithBox(int $type, string $name): array
    {
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = $type;
        $dashboard->dashboard_name = 'perm_' . uniqid();
        $dashboard->dashboard_view_name = $name;
        $dashboard->options = ['row1' => 2, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();

        $box = new DashboardBox();
        $box->dashboard_id = $dashboard->id;
        $box->dashboard_box_type = DashboardBoxType::SYSTEM;
        $box->dashboard_box_view_name = 'box';
        $box->row_no = 1;
        $box->column_no = 1;
        $box->options = ['target_system_id' => 1];
        $box->save();
        return [$dashboard, $box];
    }

    /**
     * A chart box of the table's view on the dashboard's free column, created by the current user.
     */
    private function chartBox(Dashboard $dashboard, int $tableId, int $viewId): DashboardBox
    {
        $box = new DashboardBox();
        $box->dashboard_id = $dashboard->id;
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->dashboard_box_view_name = 'chart of the hidden table';
        $box->row_no = 1;
        $box->column_no = 2;
        $box->options = ['target_table_id' => $tableId, 'target_view_id' => $viewId, 'chart_type' => 'bar'];
        $box->save();
        return $box;
    }

    /**
     * The chart box form as the current user: create with the table posted back, create with
     * the view posted back, edit of the box.
     *
     * @return array<int, \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>>
     */
    private function chartForms(Dashboard $dashboard, DashboardBox $box, int $tableId, int $viewId): array
    {
        $create = $this->url('dashboardbox/create?dashboard_suuid=' . $dashboard->suuid . '&dashboard_box_type=' . DashboardBoxType::CHART . '&row_no=1&column_no=2');
        return [
            $this->get($create . '&options[target_table_id]=' . $tableId),
            $this->get($create . '&options[target_view_id]=' . $viewId),
            $this->get($this->url('dashboardbox/' . $box->id . '/edit')),
        ];
    }

    private function asViewer(): void
    {
        foreach (LoginUser::all() as $login) {
            if ($login->base_user === null) {
                continue;
            }
            $this->asUser($login);
            if (!\Exment::user()->hasPermission(Permission::SYSTEM)) {
                return;
            }
        }
        $this->markTestSkipped('needs a login user without the system permission');
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

    /**
     * The request was refused: Exment renders the 403 as its error page (status 200), with
     * the not-found-or-denied message, never the redirect of a save.
     *
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertDenied($response): void
    {
        $this->assertContains($response->getStatusCode(), [200, 403]);
        $this->assertStringContainsString(exmtrans('common.message.notfound_or_deny'), (string) $response->getContent());
    }

    private function url(string $path): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/' . $path;
    }
}
