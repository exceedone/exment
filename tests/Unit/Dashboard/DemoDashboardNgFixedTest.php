<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\Unit\Security\SecurityRegressionTestCase;
use Illuminate\Support\Facades\DB;

/**
 * FIXED (Demo_Dashboard checklist, NG of v7's new dashboard features):
 *
 * - BSET-04: on the chart box form, picking an aggregate view for a multi-series type
 *   filled 系列の項目 by ajax with no empty entry, so the select took the first group column
 *   — the X column — and the saved box drew its axes swapped, against the help ("1列目＝横軸、
 *   2列目＝系列"). The ajax list (chart_axis/series) now pre-selects the 2nd group column.
 * - PART-03: a box without a table (system, html) got the 「フィルター対象外」 badge once a
 *   filter bar item was picked: dashboard.js badged every box carrying data-df-dims, while
 *   the server (DashboardBoxController::filterBadge) badges none without a table. Such a box
 *   is now marked data-df-badge="none", which dashboard.js leaves unbadged.
 *
 * The data (a bare table and views, a system dashboard) is rolled back; the requests use
 * LoginUser 1 (system admin).
 */
class DemoDashboardNgFixedTest extends SecurityRegressionTestCase
{
    public function test_the_series_list_preselects_the_second_group_column(): void
    {
        $this->asAdmin();
        [$tableId, $columnIds] = $this->table(['kubun' => '区分', 'tanto' => '担当者']);

        $twoColumns = $this->aggregateView($tableId, $columnIds);
        $options = $this->get($this->url('dashboardbox/chart_axis/series?q=' . $twoColumns))->assertStatus(200)->json();
        $this->assertSame(['区分', '担当者'], array_column($options, 'text'));
        $this->assertArrayNotHasKey('selected', $options[0], 'the 1st group column is the X axis');
        $this->assertTrue($options[1]['selected'] ?? false, 'the 2nd group column is the series, as the help says');

        // a view of one group column has no 2nd: nothing pre-selected (the chart asks for 2)
        $oneColumn = $this->aggregateView($tableId, [$columnIds[0]]);
        $options = $this->get($this->url('dashboardbox/chart_axis/series?q=' . $oneColumn))->json();
        $this->assertCount(1, $options);
        $this->assertArrayNotHasKey('selected', $options[0]);

        // the X / Y lists are as before
        $this->assertArrayNotHasKey('selected', $this->get($this->url('dashboardbox/chart_axis/x?q=' . $twoColumns))->json()[1] ?? []);
    }

    public function test_a_box_without_a_table_is_marked_unbadged(): void
    {
        $this->asAdmin();
        [$tableId, $columnIds] = $this->table(['kubun' => '区分']);
        $viewId = $this->aggregateView($tableId, $columnIds);
        $table = CustomTable::getEloquent($tableId);

        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::SYSTEM;
        $dashboard->dashboard_name = 'ngfix_' . uniqid();
        $dashboard->dashboard_view_name = 'filter bar dashboard';
        $dashboard->options = [
            'row1' => 3, 'row2' => 0, 'row3' => 0, 'row4' => 0,
            'filter_bar' => ['source_table' => $table->table_name, 'dims' => [['column' => 'kubun', 'label' => '区分']]],
        ];
        $dashboard->save();
        $system = $this->box($dashboard, 1, DashboardBoxType::SYSTEM, ['target_system_id' => 1]);
        $chart = $this->box($dashboard, 2, DashboardBoxType::CHART, ['target_table_id' => $tableId, 'target_view_id' => $viewId, 'chart_type' => 'bar']);
        $list = $this->box($dashboard, 3, DashboardBoxType::LIST, ['target_table_id' => $tableId, 'target_view_id' => $viewId]);

        $content = (string) $this->get('/' . config('admin.route.prefix', 'admin') . '?dashboard=' . $dashboard->suuid . '&df_kubun=A')->assertStatus(200)->getContent();

        $systemTag = $this->boxTag($content, $system);
        $this->assertStringContainsString('data-df-dims=""', $systemTag, 'still a box the bar never reloads');
        $this->assertStringContainsString('data-df-badge="none"', $systemTag, 'no data a filter could narrow: no badge');
        // a box of a table keeps its badge, as the server draws it (a list: not narrowed)
        $this->assertStringNotContainsString('data-df-badge', $this->boxTag($content, $chart));
        $this->assertStringNotContainsString('data-df-badge', $this->boxTag($content, $list));
    }

    private function asAdmin(): void
    {
        $login = LoginUser::find(1);
        if ($login === null) {
            $this->markTestSkipped('needs LoginUser 1 in the dev DB');
        }
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->actingAs($login, 'admin');
    }

    /**
     * A bare table with select columns (inserted bare: saving a CustomTable would create its
     * table, whose DDL ends the rolled back transaction).
     *
     * @param array<string, string> $columns  column name => view name
     * @return array{0: int, 1: int[]}
     */
    private function table(array $columns): array
    {
        $tableId = DB::table('custom_tables')->insertGetId([
            'suuid' => short_uuid(), 'table_name' => 'ngfix_' . uniqid(), 'table_view_name' => 'ngfix table', 'options' => '{}',
        ]);
        $ids = [];
        foreach ($columns as $name => $viewName) {
            $ids[] = DB::table('custom_columns')->insertGetId([
                'suuid' => short_uuid(), 'custom_table_id' => $tableId, 'column_name' => $name, 'column_view_name' => $viewName, 'column_type' => 'select',
                'options' => json_encode(['select_item' => "A\nB"]),
            ]);
        }
        System::clearRequestSession();
        return [$tableId, $ids];
    }

    /**
     * An aggregate view of the table grouped by the columns, in that order.
     *
     * @param int[] $columnIds
     */
    private function aggregateView(int $tableId, array $columnIds): int
    {
        $viewId = DB::table('custom_views')->insertGetId([
            'suuid' => short_uuid(), 'custom_table_id' => $tableId, 'view_view_name' => 'ngfix view', 'view_type' => ViewType::SYSTEM, 'view_kind_type' => ViewKindType::AGGREGATE, 'options' => '{}',
        ]);
        foreach ($columnIds as $i => $columnId) {
            DB::table('custom_view_columns')->insert([
                'custom_view_id' => $viewId, 'view_column_type' => 0, 'view_column_table_id' => $tableId, 'view_column_target_id' => $columnId, 'order' => $i + 1, 'options' => '{}',
            ]);
        }
        System::clearRequestSession();
        return $viewId;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function box(Dashboard $dashboard, int $column, string $type, array $options): DashboardBox
    {
        $box = new DashboardBox();
        $box->dashboard_id = $dashboard->id;
        $box->dashboard_box_type = $type;
        $box->dashboard_box_view_name = 'ngfix ' . $type;
        $box->row_no = 1;
        $box->column_no = $column;
        $box->options = $options;
        $box->save();
        return $box;
    }

    /**
     * The opening tag of the box's element (the one carrying its data-suuid and data-df-*).
     */
    private function boxTag(string $content, DashboardBox $box): string
    {
        $this->assertSame(1, preg_match('/<[^>]*data-suuid="' . preg_quote($box->suuid, '/') . '"[^>]*>/', $content, $m), 'the box is on the page');
        return $m[0] ?? '';
    }

    private function url(string $path): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/' . $path;
    }
}
