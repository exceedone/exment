<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ShareTargetType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\ColumnOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * FIXED (review of the dashboard filter bar, 2026-10-15):
 *  - a user dashboard shared for editing with a user who may not view its filter bar's source
 *    table: their save of the setting form (a rename alone) dropped the whole filter bar — the
 *    table select lists the tables they may view, so it posted blank. The form now shows no
 *    filter bar field to them, and the save keeps the bar;
 *  - their save of the owner's default dashboard (the form, or a box deleted) turned off the
 *    default of their OWN user dashboards: the default flag is kept per dashboard owner now;
 *  - boxes deleted together (DELETE admin/dashboardbox/1,2) each saved their own copy of the
 *    dashboard, the last save wrote back the targets the one before removed; a box deleted
 *    when the dashboard's save fails stays (one transaction);
 *  - a filter bar list read off the column's index checked the values against every row of
 *    the table, also rows a user with restricted rows may not see.
 *
 * The data is rolled back; LoginUser 1 (system admin) owns, the first login user without
 * the system permission edits.
 */
class DashboardSharedEditFixedTest extends SecurityRegressionTestCase
{
    public function test_a_shared_editor_who_cannot_view_the_source_table_keeps_the_filter_bar(): void
    {
        $tableName = $this->hiddenTable();
        $this->asUser(LoginUser::find(1));
        [$dashboard] = $this->dashboardWithBoxes(DashboardType::USER, 1);
        $bar = ['source_table' => $tableName, 'dims' => [['column' => 'secret_column', 'label' => 'secret']]];
        $dashboard->setOption('filter_bar', $bar)->save();
        $editor = $this->asEditorOf($dashboard);
        $this->assertFalse(CustomTable::getEloquent($tableName)->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE));

        // the edit screen shows the notice instead of the fields
        $html = (string) $this->get($this->url('dashboard/' . $dashboard->id . '/edit'))->getContent();
        $this->assertStringContainsString(e(exmtrans('dashboard.filter_bar.locked')), $html);
        $this->assertStringNotContainsString('name="filter_bar_table"', $html);

        // what a browser holding the fields would post: blank table and items
        $this->put($this->url('dashboard/' . $dashboard->id), [
            'dashboard_view_name' => 'renamed by the editor',
            'row_setting' => ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0],
            'filter_bar_table' => '',
            'filter_bar_dims' => [],
        ]);

        $saved = $this->stored($dashboard->id);
        $this->assertSame('renamed by the editor', $saved->dashboard_view_name, 'the save itself went through (' . $editor->id . ')');
        $this->assertSame($tableName, $saved->getOption('filter_bar.source_table'));
        $this->assertSame('secret_column', $saved->getOption('filter_bar.dims.0.column'));
    }

    public function test_a_shared_editor_saving_the_owners_default_keeps_their_own_default(): void
    {
        $editor = $this->editorLogin();
        $this->asUser($editor);
        [$own] = $this->dashboardWithBoxes(DashboardType::USER, 1, true);

        $this->asUser(LoginUser::find(1));
        [$shared] = $this->dashboardWithBoxes(DashboardType::USER, 1, true);
        [$ownerOther] = $this->dashboardWithBoxes(DashboardType::USER, 1);
        DB::table('dashboards')->where('id', $ownerOther->id)->update(['default_flg' => 1]);
        $this->asEditorOf($shared);

        $shared->dashboard_view_name = 'saved by the editor';
        $shared->save();

        $this->assertSame(1, (int) DB::table('dashboards')->where('id', $own->id)->value('default_flg'), "the editor's own default is untouched");
        $this->assertSame(1, (int) DB::table('dashboards')->where('id', $shared->id)->value('default_flg'));
        $this->assertSame(0, (int) DB::table('dashboards')->where('id', $ownerOther->id)->value('default_flg'), "the owner's other default, not shared with the editor, gives way");

        // the owner's save: their other default gives way, as before
        DB::table('dashboards')->where('id', $ownerOther->id)->update(['default_flg' => 1]);
        $this->asUser(LoginUser::find(1));
        $shared = $this->stored($shared->id);
        $shared->dashboard_view_name = 'saved by the owner';
        $shared->save();
        $this->assertSame(0, (int) DB::table('dashboards')->where('id', $ownerOther->id)->value('default_flg'));
        $this->assertSame(1, (int) DB::table('dashboards')->where('id', $own->id)->value('default_flg'));
    }

    public function test_boxes_deleted_together_are_all_forgotten_among_the_targets(): void
    {
        $this->asUser(LoginUser::find(1));
        [$dashboard, $boxes] = $this->dashboardWithBoxes(DashboardType::SYSTEM, 3);
        $suuids = array_map(function ($box) {
            return (string) $box->suuid;
        }, $boxes);
        $dashboard->setOption('filter_bar', ['source_table' => 'any_table', 'dims' => [['column' => 'c', 'label' => 'c', 'targets' => $suuids]]])->save();

        $this->delete($this->url('dashboardbox/' . $boxes[0]->id . ',' . $boxes[1]->id))->assertJson(['status' => true]);

        $this->assertSame([$suuids[2]], $this->stored($dashboard->id)->getOption('filter_bar.dims.0.targets'));
    }

    public function test_a_box_stays_when_the_dashboard_cannot_be_saved(): void
    {
        $this->asUser(LoginUser::find(1));
        [$dashboard, $boxes] = $this->dashboardWithBoxes(DashboardType::SYSTEM, 1);
        $dashboard->setOption('filter_bar', ['source_table' => 'any_table', 'dims' => [['column' => 'c', 'label' => 'c', 'targets' => [(string) $boxes[0]->suuid]]]])->save();
        Event::listen('eloquent.saving: ' . Dashboard::class, function () {
            throw new \RuntimeException('dashboard save failed');
        });

        $this->delete($this->url('dashboardbox/delete/' . $boxes[0]->suuid))->assertJson(['status' => false]);

        $this->assertSame(1, DashboardBox::where('id', $boxes[0]->id)->count());
    }

    public function test_a_box_saves_its_painted_colors_in_the_forms_transaction(): void
    {
        $this->asUser(LoginUser::find(1));
        [$dashboard, $boxes] = $this->dashboardWithBoxes(DashboardType::SYSTEM, 1);
        $box = $boxes[0];
        $box->dashboard_box_type = DashboardBoxType::SYSTEM;
        $box->options = ['target_system_id' => 1, 'chart_colors' => ['points' => ['A' => '#00ff00']]];
        $box->save();
        // the save carrying the painted colors over the form's (a failure of it, rolling the
        // form's save back, would also unwind the test's own transaction)
        $outer = DB::transactionLevel();
        $levels = [];
        Event::listen('eloquent.saving: ' . DashboardBox::class, function ($saving) use (&$levels) {
            if (array_has($saving->options ?? [], 'chart_colors')) {
                $levels[] = DB::transactionLevel();
            }
        });

        $this->put($this->url('dashboardbox/' . $box->id), [
            'dashboard_id' => $dashboard->id, 'dashboard_box_type' => DashboardBoxType::SYSTEM, 'row_no' => 1, 'column_no' => 1,
            'dashboard_box_view_name' => 'renamed', 'options' => ['target_system_id' => 1],
        ]);

        $stored = DashboardBox::where('id', $box->id)->first();
        $this->assertSame('renamed', $stored->dashboard_box_view_name);
        $this->assertSame(['points' => ['A' => '#00ff00']], array_get($stored->options, 'chart_colors'));
        $this->assertCount(1, $levels);
        $this->assertGreaterThan($outer, $levels[0], 'inside the transaction of the form');
    }

    public function test_a_list_is_read_off_the_index_only_for_a_user_who_sees_every_row(): void
    {
        $indexValues = new \ReflectionMethod(ColumnOptions::class, 'indexValues');

        $this->asUser(LoginUser::find(1));
        $user = CustomTable::getEloquent(SystemTableName::USER);
        $this->assertIsArray($indexValues->invoke(null, $user, $user->custom_columns->firstWhere('column_name', 'user_code'), 100));

        // a table no role grants this user: their rows query narrows the rows (to none)
        $hidden = CustomTable::getEloquent($this->hiddenTable());
        $this->asUser($this->editorLogin());
        $this->assertNull($indexValues->invoke(null, $hidden, $hidden->custom_columns->first(), 100));
    }

    /**
     * A table no role grants, with one column (inserted bare: saving a CustomTable would
     * create its table, whose DDL ends the rolled back transaction).
     */
    private function hiddenTable(): string
    {
        $tableName = 'perm_' . uniqid();
        $tableId = DB::table('custom_tables')->insertGetId([
            'suuid' => short_uuid(), 'table_name' => $tableName, 'table_view_name' => 'hidden table', 'options' => '{}',
        ]);
        DB::table('custom_columns')->insert([
            'suuid' => short_uuid(), 'custom_table_id' => $tableId, 'column_name' => 'secret_column', 'column_view_name' => 'secret column', 'column_type' => 'text', 'options' => '{"index_enabled":"1"}',
        ]);
        return $tableName;
    }

    /**
     * A dashboard of $count chart boxes on row 1, created by the current user.
     *
     * @return array{0: Dashboard, 1: DashboardBox[]}
     */
    private function dashboardWithBoxes(int $type, int $count, bool $default = false): array
    {
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = $type;
        $dashboard->dashboard_name = 'shared_' . uniqid();
        $dashboard->dashboard_view_name = 'dashboard';
        $dashboard->default_flg = $default;
        $dashboard->options = ['row1' => $count, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();

        $boxes = [];
        for ($i = 1; $i <= $count; $i++) {
            $box = new DashboardBox();
            $box->dashboard_id = $dashboard->id;
            $box->dashboard_box_type = DashboardBoxType::CHART;
            $box->dashboard_box_view_name = 'box ' . $i;
            $box->row_no = 1;
            $box->column_no = $i;
            $box->options = [];
            $box->save();
            $boxes[] = $box;
        }
        return [$dashboard, $boxes];
    }

    /**
     * Shares $dashboard for editing with the editor and logs them in.
     */
    private function asEditorOf(Dashboard $dashboard): LoginUser
    {
        $editor = $this->editorLogin();
        DB::table('data_share_authoritables')->insert([
            'parent_id' => $dashboard->id, 'parent_type' => ShareTargetType::DASHBOARD, 'authoritable_type' => Permission::DATA_SHARE_EDIT,
            'authoritable_user_org_type' => SystemTableName::USER, 'authoritable_target_id' => $editor->base_user_id,
        ]);
        $this->asUser($editor);
        $this->assertTrue($this->stored($dashboard->id, true)->hasEditPermission());
        return $editor;
    }

    /**
     * The first login user without the system permission.
     */
    private function editorLogin(): LoginUser
    {
        foreach (LoginUser::where('id', '<>', 1)->orderBy('id')->get() as $login) {
            if ($login->base_user === null) {
                continue;
            }
            $this->asUser($login);
            if (!\Exment::user()->hasPermission(Permission::SYSTEM)) {
                return $login;
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
     * The dashboard as stored; $showable: through the scope of what the current user may view.
     */
    private function stored(int $id, bool $showable = false): Dashboard
    {
        $dashboard = ($showable ? Dashboard::query() : Dashboard::withoutGlobalScopes())->where('id', $id)->first();
        $this->assertInstanceOf(Dashboard::class, $dashboard);
        return $dashboard;
    }

    private function url(string $path): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/' . $path;
    }
}
