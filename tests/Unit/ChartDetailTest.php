<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\GroupCondition;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\ChartDetail;
use Exceedone\Exment\Services\Dashboard\ChartDetailRequest;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * 明細 of a dashboard chart (ChartDetail): a double click on a point lists the rows behind
 * its number on the table's data list.
 *
 * The contract, for every point of the chart: the rows listed are the ones the number is
 * summarized from — as many as a 件数 point counts, adding up to a 合計 point, their average
 * an 平均 point — and exactly the rows a hand-written SQL picks for the point; no row is
 * listed for two points, none is lost. Checked on a group column of each kind (text, date
 * by year / month, select_table, user, fixed values, an empty value), on the 1:n and n:n
 * children, under the filter bar and the chart filter, for a user who sees part of the rows,
 * with the 集計方法 / 表示方法 / sort of the box menu.
 */
class ChartDetailTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    // Own table ------------------------------------------------------------------------------

    /**
     * A text column, rows without a value among them: each point lists its rows, the empty
     * group (未設定) the rows without one — the same ids a plain SQL picks.
     */
    public function testATextGroupListsItsRowsAndTheEmptyGroupTheRowsWithoutAValue(): void
    {
        $this->createRows('parent_table', [['odd_even' => null, 'integer' => 5], ['odd_even' => null, 'integer' => 7], ['odd_even' => 'odd', 'integer' => 1]]);
        $view = $this->aggregateView('parent_table', [['column_name' => 'odd_even']], $this->countOfIds());
        $chart = $this->chart($view, 'bar');

        $column = $this->column('parent_table', 'odd_even');
        $seenNull = false;
        foreach ($chart['points'] as [$keys, $value]) {
            $raw = current($keys);
            $seenNull = $seenNull || $raw === null;
            $expected = $this->rawIds('parent_table', $raw === null ? $this->idx($column) . ' IS NULL' : $this->idx($column) . ' = ?', $raw === null ? [] : [$raw]);
            $this->assertSame($expected, $this->listed($chart['detail'], $keys), 'raw SQL: ' . (string) json_encode($keys));
            $this->assertCount((int) $value, $expected, 'the count on the chart: ' . (string) json_encode($keys));
        }
        $this->assertTrue($seenNull, 'the empty group is a point');
        $this->assertAllRowsOnce($chart, 'parent_table');
    }

    /**
     * A date grouped by month and by year (集計ビュー グループ化 年月 / 年).
     */
    public function testADateGroupedByMonthOrYearListsTheRowsOfItsPeriod(): void
    {
        $this->createRows('parent_table', [['date' => '2025-12-10', 'integer' => 1], ['date' => '2025-12-31', 'integer' => 2], ['date' => '2026-01-01', 'integer' => 3], ['date' => null, 'integer' => 4]]);
        $column = $this->column('parent_table', 'date');
        foreach ([GroupCondition::YM => '%Y-%m', GroupCondition::Y => '%Y'] as $condition => $format) {
            $view = $this->aggregateView('parent_table', [['column_name' => 'date', 'options' => ['view_group_condition' => $condition]]], $this->countOfIds());
            $chart = $this->chart($view, 'bar');
            $this->assertNotEmpty($chart['points']);
            foreach ($chart['points'] as [$keys, $value]) {
                $raw = current($keys);
                $expected = $raw === null
                    ? $this->rawIds('parent_table', $this->idx($column) . ' IS NULL')
                    : $this->rawIds('parent_table', 'DATE_FORMAT(' . $this->idx($column) . ", '$format') = ?", [$raw]);
                $this->assertSame($expected, $this->listed($chart['detail'], $keys), "$condition " . (string) json_encode($keys));
                $this->assertCount((int) $value, $expected, "$condition count " . (string) json_encode($keys));
            }
            $this->assertContains($condition === GroupCondition::Y ? '2025' : '2025-12', array_map(function ($p) {
                return (string) current($p[0]);
            }, $chart['points']), "$condition: the period is a point");
            $this->assertAllRowsOnce($chart, 'parent_table');
        }
    }

    /**
     * select_table, user, fixed values (select / select_valtext) and yes/no columns, on one
     * and on two group columns (a compound point, a multi-series cell); a 合計 and a 件数.
     */
    public function testSelectTableUserAndFixedValueGroupsListTheRowsOfTheirValue(): void
    {
        $table = 'all_columns_table_fortest';
        foreach (['select_table', 'user', 'select', 'select_valtext', 'yesno'] as $name) {
            $view = $this->aggregateView($table, [['column_name' => $name]], $this->countOfIds());
            $chart = $this->chart($view, 'bar');
            $this->assertNotEmpty($chart['points'], $name);
            $column = $this->column($table, $name);
            foreach ($chart['points'] as [$keys, $value]) {
                $raw = current($keys);
                $expected = $raw === null ? $this->rawIds($table, $this->idx($column) . ' IS NULL') : $this->rawIds($table, $this->idx($column) . ' = ?', [$raw]);
                $this->assertSame($expected, $this->listed($chart['detail'], $keys), "$name " . (string) json_encode($keys));
                $this->assertCount((int) $value, $expected, "$name count " . (string) json_encode($keys));
            }
            $this->assertAllRowsOnce($chart, $table);
        }

        $sum = ['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM];
        $view = $this->aggregateView($table, [['column_name' => 'select'], ['column_name' => 'user']], $sum);
        foreach (['bar', 'mbar', 'heatmap', 'treemap'] as $type) {
            $chart = $this->chart($view, $type);
            $this->assertMeasureMatches($chart, $table, 'integer', SummaryCondition::SUM, $type);
        }
    }

    /**
     * Every summary condition: the listed rows summarized again give the point's number.
     */
    public function testTheListedRowsSummarizeToThePointForEveryCondition(): void
    {
        foreach ([SummaryCondition::SUM, SummaryCondition::AVG, SummaryCondition::COUNT, SummaryCondition::MIN, SummaryCondition::MAX] as $condition) {
            $view = $this->aggregateView('all_columns_table_fortest', [['column_name' => 'select']], ['column_name' => 'decimal', 'summary_condition' => $condition]);
            $this->assertMeasureMatches($this->chart($view, 'bar'), 'all_columns_table_fortest', 'decimal', $condition, "condition $condition");
        }
    }

    // Children ------------------------------------------------------------------------------

    /**
     * A measure of the 1:n children lists the children (the rows a 件数 counts), of the
     * parents of the point; a group column of the children, the children of its value.
     */
    public function testAMeasureOfOneToManyChildrenListsTheChildren(): void
    {
        $this->createFamily('parent_table', 'child_table');
        $count = ['reference_table' => 'child_table', 'is_child' => true, 'condition_type' => ConditionType::SYSTEM, 'column_name' => 'id', 'summary_condition' => SummaryCondition::COUNT];

        $view = $this->aggregateView('parent_table', [['column_name' => 'odd_even']], $count);
        $chart = $this->chart($view, 'bar');
        $this->assertSame('child_table', $chart['detail']->table()->table_name, 'the children are listed');
        $parent = $this->column('parent_table', 'odd_even');
        foreach ($chart['points'] as [$keys, $value]) {
            $raw = current($keys);
            $expected = $this->rawIds('child_table', "parent_type = 'parent_table' AND parent_id IN (SELECT id FROM " . getDBTableName('parent_table') . ' WHERE deleted_at IS NULL AND ' . ($raw === null ? $this->idx($parent) . ' IS NULL)' : $this->idx($parent) . ' = ?)'), $raw === null ? [] : [$raw]);
            $this->assertSame($expected, $this->listed($chart['detail'], $keys), (string) json_encode($keys));
            $this->assertCount((int) $value, $expected, 'count ' . (string) json_encode($keys));
        }

        // grouped by a column of the children: the children of the value
        $view = $this->aggregateView('parent_table', [['reference_table' => 'child_table', 'is_child' => true, 'column_name' => 'odd_even'], ['column_name' => 'multiples_of_3']], $count);
        foreach (['bar', 'mbar'] as $type) {
            $chart = $this->chart($view, $type);
            $this->assertSame('child_table', $chart['detail']->table()->table_name);
            $this->assertMeasureMatches($chart, 'child_table', null, SummaryCondition::COUNT, "child group ($type)");
        }

        // a 合計 of the children's values: the listed children add up to it
        $view = $this->aggregateView('parent_table', [['column_name' => 'odd_even']], ['reference_table' => 'child_table', 'is_child' => true, 'column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]);
        $this->assertMeasureMatches($this->chart($view, 'bar'), 'child_table', 'integer', SummaryCondition::SUM, 'child sum');
    }

    /**
     * A measure of the table, grouped by a column of its 1:n children: the parents that
     * have a child of the value.
     */
    public function testAGroupOfOneToManyChildrenListsTheParentsHavingOne(): void
    {
        $this->createFamily('parent_table', 'child_table');
        $view = $this->aggregateView('parent_table', [['reference_table' => 'child_table', 'is_child' => true, 'column_name' => 'odd_even']], $this->countOfIds());
        $chart = $this->chart($view, 'bar');
        $this->assertSame('parent_table', $chart['detail']->table()->table_name);
        $child = $this->column('child_table', 'odd_even');
        foreach ($chart['points'] as [$keys, $value]) {
            $raw = current($keys);
            if ($raw === null) {
                continue; // the parents without a child, and the ones with a child without a value
            }
            $expected = $this->rawIds('parent_table', 'id IN (SELECT parent_id FROM ' . getDBTableName('child_table') . " WHERE deleted_at IS NULL AND parent_type = 'parent_table' AND " . $this->idx($child) . ' = ?)', [$raw]);
            $this->assertSame($expected, $this->listed($chart['detail'], $keys), (string) json_encode($keys));
            $this->assertCount((int) $value, $expected, 'count ' . (string) json_encode($keys));
        }
    }

    /**
     * A measure of the n:n children lists the children linked to the parents of the point.
     */
    public function testAMeasureOfManyToManyChildrenListsTheChildren(): void
    {
        $count = ['reference_table' => 'child_table_n_n', 'is_child' => true, 'condition_type' => ConditionType::SYSTEM, 'column_name' => 'id', 'summary_condition' => SummaryCondition::COUNT];
        $view = $this->aggregateView('parent_table_n_n', [['column_name' => 'odd_even']], $count);
        $chart = $this->chart($view, 'bar');
        $this->assertSame('child_table_n_n', $chart['detail']->table()->table_name);
        $relation = CustomRelation::getRelationByParentChild('parent_table_n_n', 'child_table_n_n');
        $this->assertNotNull($relation);
        $pivot = $relation->getRelationName();
        $parent = $this->column('parent_table_n_n', 'odd_even');
        foreach ($chart['points'] as [$keys, $value]) {
            $raw = current($keys);
            $parents = 'SELECT id FROM ' . getDBTableName('parent_table_n_n') . ' WHERE deleted_at IS NULL AND ' . $this->idx($parent) . ($raw === null ? ' IS NULL' : ' = ?');
            $expected = $this->rawIds('child_table_n_n', "id IN (SELECT child_id FROM $pivot WHERE parent_id IN ($parents))", $raw === null ? [] : [$raw]);
            $this->assertSame($expected, $this->listed($chart['detail'], $keys), (string) json_encode($keys));
            // the count of the (parent, child) links: a child linked to two parents of the point counts twice
            $links = DB::selectOne("SELECT COUNT(*) AS n FROM $pivot JOIN " . getDBTableName('child_table_n_n') . " c ON c.id = $pivot.child_id AND c.deleted_at IS NULL WHERE $pivot.parent_id IN ($parents)", $raw === null ? [] : [$raw]);
            $this->assertSame((int) $value, (int) $links->n, 'count ' . (string) json_encode($keys));
        }
    }

    // Box state ------------------------------------------------------------------------------

    /**
     * The filter bar's selection and the chart filter narrow the rows as they narrow the chart.
     */
    public function testTheFilterBarAndTheChartFilterNarrowTheRows(): void
    {
        $view = $this->aggregateView('parent_table', [['column_name' => 'odd_even']], $this->countOfIds());
        $dashboard = $this->dashboard(['source_table' => 'parent_table', 'dims' => [['column' => 'user']]]);
        $params = ['df_user' => ['2', '4', '5', '6'], 'bf_index_text' => ['index_002_001', 'index_004_001', 'index_005_001', 'index_007_001']];
        $chart = $this->chart($view, 'bar', $params, $dashboard, ['chart_filters' => ['index_text']]);
        $whole = $this->chart($view, 'bar');

        $user = $this->column('parent_table', 'user');
        $odd_even = $this->column('parent_table', 'odd_even');
        $index_text = $this->column('parent_table', 'index_text');
        $this->assertNotEmpty($chart['points']);
        foreach ($chart['points'] as [$keys, $value]) {
            // index_007_001 is out of the bar's scope: the chart filter drops it, as the chart does
            $expected = $this->rawIds('parent_table', $this->idx($odd_even) . ' = ? AND ' . $this->idx($user) . " IN ('2', '4', '5', '6') AND " . $this->idx($index_text) . " IN ('index_002_001', 'index_004_001', 'index_005_001')", [current($keys)]);
            $this->assertSame($expected, $this->listed($chart['detail'], $keys), (string) json_encode($keys));
            $this->assertCount((int) $value, $expected);
        }
        $this->assertLessThan(array_sum(array_column($whole['points'], 1)), array_sum(array_column($chart['points'], 1)), 'the filters narrow it');
    }

    /**
     * A user who sees part of the rows: the chart counts theirs, the list lists theirs.
     */
    public function testAUserSeesOnlyTheRowsTheyMaySee(): void
    {
        $view = $this->aggregateView('parent_table', [['column_name' => 'odd_even']], $this->countOfIds());
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearCache();
        $visible = CustomTable::getEloquent('parent_table')->getValueQuery()->pluck('id')->map('intval')->sort()->values()->all();
        $this->assertLessThan(DB::table(getDBTableName('parent_table'))->whereNull('deleted_at')->count(), count($visible), 'the user sees part of the table');

        $chart = $this->chart($view, 'bar');
        $listed = [];
        foreach ($chart['points'] as [$keys, $value]) {
            $ids = $this->listed($chart['detail'], $keys);
            $this->assertCount((int) $value, $ids, (string) json_encode($keys));
            $listed = array_merge($listed, $ids);
        }
        sort($listed);
        $this->assertSame($visible, $listed, 'their rows, every one once');
    }

    /**
     * 集計方法 / 表示方法 / the sort: the points keep their rows. Summarized as 件数 (集計方法),
     * a point counts its listed rows; shown as a share (表示方法), its rows are the same.
     */
    public function testTheBoxMenuKeepsEachPointsRows(): void
    {
        $view = $this->aggregateView('all_columns_table_fortest', [['column_name' => 'select']], ['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]);
        $plain = $this->chart($view, 'bar');
        $byKey = [];
        foreach ($plain['points'] as [$keys]) {
            $byKey[(string) json_encode($keys)] = $this->listed($plain['detail'], $keys);
        }

        $counted = $this->chart($view, 'bar', ['ca' => SummaryCondition::COUNT]);
        $shown = $this->chart($view, 'bar', ['sv' => 'percent_of_total']);
        $sorted = $this->chart($view, 'bar', ['cs' => 'y:desc']);
        foreach (['ca' => $counted, 'sv' => $shown, 'cs' => $sorted] as $name => $chart) {
            $this->assertCount(count($byKey), $chart['points'], $name);
            foreach ($chart['points'] as [$keys, $value]) {
                $ids = $this->listed($chart['detail'], $keys);
                $this->assertSame($byKey[(string) json_encode($keys)], $ids, "$name " . (string) json_encode($keys));
                if ($name === 'ca') {
                    $this->assertCount((int) $value, $ids, 'a 件数 counts its rows');
                }
            }
        }
        // sorted by value, descending: the keys moved with their values
        $values = array_column($sorted['points'], 1);
        $desc = $values;
        rsort($desc);
        $this->assertSame($desc, $values);
        $this->assertMeasureMatches($sorted, 'all_columns_table_fortest', 'integer', SummaryCondition::SUM, 'sorted');
    }

    /**
     * The types whose point is no one group, a list view, have no 明細: nothing to double
     * click (the payload is null) — the others hand theirs.
     */
    public function testOnlyTypesWhosePointIsAGroupHaveADetail(): void
    {
        $view = $this->aggregateView('parent_table', [['column_name' => 'odd_even'], ['column_name' => 'multiples_of_3']], $this->countOfIds());
        foreach (['bar', 'line', 'pie', 'hbar', 'area', 'doughnut', 'funnel', 'scatter', 'mbar', 'sbar', 'mline', 'sarea', 'heatmap', 'treemap', 'boxplot'] as $type) {
            $this->assertNotNull($this->chart($view, $type)['payload'], "$type has one");
        }
        foreach (['radar', 'gauge', 'sunburst'] as $type) {
            $chart = $this->chart($view, $type);
            $this->assertNull($chart['payload'], "$type has none");
            $this->assertStringContainsString('var detail = null;', $chart['body'], $type);
        }

        $list = $this->createCustomViewAll(['target_table_name' => 'parent_table', 'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'integer']]], ViewKindType::DEFAULT)[1];
        $columns = CustomViewColumn::where('custom_view_id', $list->id)->orderBy('order')->get();
        $box = $this->box($list, 'bar', ViewKindType::DEFAULT . '_' . $columns[0]->id, ViewKindType::DEFAULT . '_' . $columns[1]->id);
        $item = new ChartItem($box, []);
        $body = (string) $item->body();
        $this->assertNull($item->detail(), 'a list view');
        $this->assertStringContainsString('var detail = null;', $body);
    }

    // The data list ---------------------------------------------------------------------------

    /**
     * data/{table}?chart_detail=… lists the point's rows on the table's every-column view and
     * says which: the chart, the conditions, the way back to the dashboard under the
     * selection the chart was drawn by.
     */
    public function testTheDataListShowsThePointsRows(): void
    {
        $this->createRows('parent_table', [['odd_even' => 'odd', 'multiples_of_3' => 1, 'integer' => 1], ['odd_even' => 'even', 'multiples_of_3' => 1, 'integer' => 1]]);
        $view = $this->aggregateView('parent_table', [['column_name' => 'odd_even']], $this->countOfIds());
        $dashboard = $this->dashboard(['source_table' => 'parent_table', 'dims' => [['column' => 'user']]]);
        $box = $this->savedBox($dashboard, $view, 'bar');
        $params = ['df_user' => ['1', '2', '3', '4']];
        $chart = $this->chart($view, 'bar', $params, $dashboard);
        [$keys, $value] = $chart['points'][0];
        $ids = $this->listed($chart['detail'], $keys);
        $this->assertNotEmpty($ids);

        $html = $this->get($this->detailUrl('parent_table', ['b' => $box->suuid, 'k' => $keys, 'q' => http_build_query($params)]))->assertOk()->getContent() ?: '';
        $this->assertStringContainsString(e(exmtrans('dashboard.chart_detail.title', $box->dashboard_box_view_name)), $html, 'the chart it came from');
        $this->assertStringContainsString(e(exmtrans('dashboard.chart_detail.back')), $html, 'the way back');
        $this->assertStringContainsString('dashboard=' . $dashboard->suuid, $html, 'to the dashboard');
        $this->assertStringContainsString(e(rawurlencode('df_user[]') . '=1'), $html, 'under the selection it was drawn by');
        $this->assertMatchesRegularExpression('/exm-filter-chip-key">[^<]*<\/span>\s*<span class="exm-filter-chip-value">' . preg_quote(e((string) current($keys)), '/') . '</u', $html, 'the group value');
        $this->assertStringNotContainsString(e(exmtrans('dashboard.chart_detail.invalid')), $html);
        $listed = $this->rowIdsIn($html);
        $this->assertSame($ids, $listed, 'the rows of the point');
        $this->assertCount((int) $value, $listed);
    }

    /**
     * A param the list cannot trust lists no row and says so: an unknown box, a list of
     * another table than the 明細's, keys of no group column, no keys, no JSON.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidParams(): array
    {
        return [
            'unknown box' => ['parent_table', 'unknown box'],
            'another table' => ['child_table', ''],
            'no group column' => ['parent_table', 'no group column'],
            'no keys' => ['parent_table', 'no keys'],
            'not json' => ['parent_table', 'not json'],
        ];
    }

    /**
     * @dataProvider invalidParams
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidParams')]
    public function testAParamItCannotTrustListsNoRow(string $table, string $case): void
    {
        $view = $this->aggregateView('parent_table', [['column_name' => 'odd_even']], $this->countOfIds());
        $box = $this->savedBox($this->dashboard(null), $view, 'bar');
        [$keys] = $this->chart($view, 'bar')['points'][0];
        $data = ['b' => $box->suuid, 'k' => $keys, 'q' => ''];
        if ($case === 'unknown box') {
            $data['b'] = 'nosuchbox00000000000';
        } elseif ($case === 'no group column') {
            $data['k'] = [999999 => 'odd'];
        } elseif ($case === 'no keys') {
            unset($data['k']);
        }
        $url = $case === 'not json' ? admin_url("data/$table") . '?' . ChartDetailRequest::PARAM . '=x' : $this->detailUrl($table, $data);
        $html = $this->get($url)->assertOk()->getContent() ?: '';
        $this->assertStringContainsString(e(exmtrans('dashboard.chart_detail.invalid')), $html, $case);
        $this->assertSame([], $this->rowIdsIn($html), "$case: no row");
    }

    /**
     * A box of a dashboard the user may not view is no box to them: no row.
     */
    public function testABoxOfAnotherUsersDashboardListsNothing(): void
    {
        $view = $this->aggregateView('parent_table', [['column_name' => 'odd_even']], $this->countOfIds());
        $dashboard = $this->dashboard(null);
        $box = $this->savedBox($dashboard, $view, 'bar');
        $chart = $this->chart($view, 'bar');
        [$keys] = $chart['points'][0];

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        System::clearCache();
        $html = $this->get($this->detailUrl('parent_table', ['b' => $box->suuid, 'k' => $keys, 'q' => '']))->assertOk()->getContent() ?: '';
        $this->assertStringContainsString(e(exmtrans('dashboard.chart_detail.invalid')), $html);
        $this->assertSame([], $this->rowIdsIn($html));
    }

    // Helpers -----------------------------------------------------------------------------------

    /**
     * A chart box of $view drawn as $type with the box params $params: the chart, its 明細 and
     * its points as [keys, the value on the chart].
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $boxOptions
     * @return array{item: ChartItem, detail: ChartDetail, payload: array<string, mixed>|null, body: string, points: array<int, array{0: array<int|string, mixed>, 1: mixed}>}
     */
    private function chart(CustomView $view, string $type, array $params = [], ?Dashboard $dashboard = null, array $boxOptions = []): array
    {
        System::clearCache();
        $x = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->first();
        $this->assertNotNull($x);
        $box = $this->box($view, $type, ViewKindType::DEFAULT . '_' . $x->id, ViewKindType::AGGREGATE . '_' . $this->summaryOf($view)->id, $boxOptions);
        if ($dashboard !== null) {
            $box->setRelation('dashboard', $dashboard);
        }
        $item = new ChartItem($box, $params);
        $body = (string) $item->body();
        $payload = $this->scriptValue($body, 'detail');
        $points = [];
        if (is_array($payload) && isset($payload['points'])) {
            $values = $this->scriptValue($body, 'values');
            foreach ($payload['points'] as $i => $keys) {
                $points[] = [$keys, $values[$i]];
            }
        } elseif (is_array($payload) && isset($payload['x_keys'])) {
            $matrix = $this->scriptValue($body, 'matrix');
            foreach ($payload['series_keys'] as $si => $series) {
                foreach ($payload['x_keys'] as $xi => $x_key) {
                    $points[] = [[$payload['x'] => $x_key, $payload['series'] => $series], $matrix[$si][$xi]];
                }
            }
        }
        $detail = $item->detail();
        if ($payload !== null) {
            $this->assertInstanceOf(ChartDetail::class, $detail);
            $this->assertSame(admin_url('data/' . $detail->table()->table_name), $payload['url']);
        }
        return ['item' => $item, 'detail' => $detail, 'payload' => $payload, 'body' => $body, 'points' => $points];
    }

    /**
     * Ids of the rows the 明細 of the point $keys lists, ascending.
     *
     * @param array<int|string, mixed> $keys
     * @return int[]
     */
    private function listed(?ChartDetail $detail, array $keys): array
    {
        $this->assertNotNull($detail);
        $parsed = $detail->keys($keys);
        $this->assertNotNull($parsed, 'keys of the view: ' . (string) json_encode($keys));
        $query = $detail->table()->getValueQuery();
        $detail->apply($query, $parsed);
        return $query->pluck(getDBTableName($detail->table()) . '.id')->map('intval')->sort()->values()->all();
    }

    /**
     * The listed rows of every point summarized again (by plain SQL) give the point's value;
     * $column null = their count.
     *
     * @param array<string, mixed> $chart
     */
    private function assertMeasureMatches(array $chart, string $table, ?string $column, string $condition, string $message): void
    {
        $this->assertNotEmpty($chart['points'], "$message: points");
        $expr = $column === null ? 'id' : $this->measureExpr($this->column($table, $column));
        $sql = [SummaryCondition::SUM => "SUM($expr)", SummaryCondition::AVG => "AVG($expr)", SummaryCondition::COUNT => "COUNT($expr)", SummaryCondition::MIN => "MIN($expr)", SummaryCondition::MAX => "MAX($expr)"][$condition];
        foreach ($chart['points'] as [$keys, $value]) {
            $ids = $this->listed($chart['detail'], $keys);
            if ($value === null) {
                $this->assertSame([], $ids, "$message: an empty cell lists nothing " . (string) json_encode($keys));
                continue;
            }
            $got = empty($ids) ? 0 : DB::selectOne("SELECT $sql AS v FROM " . getDBTableName($table) . ' WHERE id IN (' . implode(',', $ids) . ')')->v;
            $this->assertEqualsWithDelta((float) $value, (float) $got, 0.01, "$message: " . (string) json_encode($keys));
        }
    }

    /**
     * Every row of the table (a COUNT of ids chart) is listed by exactly one point.
     *
     * @param array<string, mixed> $chart
     */
    private function assertAllRowsOnce(array $chart, string $table): void
    {
        $listed = [];
        foreach ($chart['points'] as [$keys]) {
            $listed = array_merge($listed, $this->listed($chart['detail'], $keys));
        }
        sort($listed);
        $this->assertSame($this->rawIds($table, '1 = 1'), $listed, 'every row once');
    }

    /**
     * @param array<int, mixed> $bindings
     * @return int[]
     */
    private function rawIds(string $table, string $where, array $bindings = []): array
    {
        $rows = DB::select('SELECT id FROM ' . getDBTableName($table) . " WHERE deleted_at IS NULL AND ($where) ORDER BY id", $bindings);
        return array_map(function ($row) {
            return (int) $row->id;
        }, $rows);
    }

    private function idx(CustomColumn $column): string
    {
        $this->assertTrue((bool) $column->index_enabled, $column->column_name . ' is indexed');
        return $column->getIndexColumnName();
    }

    private function measureExpr(CustomColumn $column): string
    {
        // the index column is text: cast, as the summary does
        $raw = $column->index_enabled ? $column->getIndexColumnName() : "JSON_UNQUOTE(JSON_EXTRACT(value, '$.{$column->column_name}'))";
        return "CAST($raw AS DECIMAL(20, 6))";
    }

    private function column(string $table, string $name): CustomColumn
    {
        $column = CustomColumn::getEloquent($name, $table);
        $this->assertNotNull($column);
        return $column;
    }

    /**
     * @return array<string, mixed>
     */
    private function countOfIds(): array
    {
        return ['condition_type' => ConditionType::SYSTEM, 'column_name' => 'id', 'summary_condition' => SummaryCondition::COUNT];
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @param array<string, mixed> $summary
     */
    private function aggregateView(string $table, array $groups, array $summary): CustomView
    {
        System::clearCache();
        $view = $this->createCustomViewAll([
            'target_table_name' => $table,
            'column_settings' => $groups,
            'summary_settings' => [$summary],
        ], ViewKindType::AGGREGATE)[1];
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        return $view;
    }

    private function summaryOf(CustomView $view): CustomViewSummary
    {
        $summary = CustomViewSummary::where('custom_view_id', $view->id)->first();
        $this->assertNotNull($summary);
        return $summary;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function box(CustomView $view, string $type, string $x, string $y, array $extra = []): DashboardBox
    {
        $box = new DashboardBox();
        $box->suuid = short_uuid();
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->options = [
            'target_table_id' => $view->custom_table_id,
            'target_view_id' => $view->id,
            'chart_type' => $type,
            'chart_axisx' => $x,
            'chart_axisy' => $y,
        ] + $extra;
        return $box;
    }

    /**
     * A saved dashboard of the admin.
     *
     * @param array<string, mixed>|null $filterBar
     */
    private function dashboard(?array $filterBar): Dashboard
    {
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::USER;
        $dashboard->dashboard_name = 'detail_' . short_uuid();
        $dashboard->dashboard_view_name = 'detail dashboard';
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0] + ($filterBar === null ? [] : ['filter_bar' => $filterBar]);
        $dashboard->save();
        return $dashboard;
    }

    private function savedBox(Dashboard $dashboard, CustomView $view, string $type): DashboardBox
    {
        $x = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->first();
        $this->assertNotNull($x);
        $box = $this->box($view, $type, ViewKindType::DEFAULT . '_' . $x->id, ViewKindType::AGGREGATE . '_' . $this->summaryOf($view)->id);
        $box->dashboard_id = $dashboard->id;
        $box->row_no = 1;
        $box->column_no = 1;
        $box->dashboard_box_view_name = 'クラス別 明細テスト';
        $box->save();
        return $box;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function createRows(string $table, array $rows): void
    {
        foreach ($rows as $values) {
            $row = CustomTable::getEloquent($table)->getValueModel();
            $row->setValue($values + ['text' => 'detail_row']);
            $row->saved_notify(false);
            $row->save();
        }
    }

    /**
     * Parents with 0, 1 and 3 children, a child without a value among them.
     */
    private function createFamily(string $parent_table, string $child_table): void
    {
        $parents = [];
        foreach (['odd', 'even', 'odd'] as $odd_even) {
            $parent = CustomTable::getEloquent($parent_table)->getValueModel();
            $parent->setValue(['text' => 'detail_parent', 'odd_even' => $odd_even, 'multiples_of_3' => 0, 'integer' => 1]);
            $parent->saved_notify(false);
            $parent->save();
            $parents[] = $parent;
        }
        foreach ([[$parents[0], [['odd', 3]]], [$parents[1], [['odd', 1], ['even', 2], [null, null]]]] as [$parent, $children]) {
            foreach ($children as [$odd_even, $integer]) {
                $child = CustomTable::getEloquent($child_table)->getValueModel();
                $child->setValue(['text' => 'detail_child', 'odd_even' => $odd_even, 'multiples_of_3' => 0, 'integer' => $integer]);
                $child->parent_id = $parent->id;
                $child->parent_type = $parent->custom_table->table_name;
                $child->saved_notify(false);
                $child->save();
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function detailUrl(string $table, array $data): string
    {
        return admin_url("data/$table") . '?' . http_build_query([ChartDetailRequest::PARAM => json_encode($data)]);
    }

    /**
     * Ids of the rows a data list page shows (their row checkboxes).
     *
     * @return int[]
     */
    private function rowIdsIn(string $html): array
    {
        preg_match_all('/class="[^"]*grid-row-checkbox[^"]*"[^>]*data-id="(\d+)"/', $html, $m);
        $ids = array_map('intval', array_unique($m[1]));
        sort($ids);
        return $ids;
    }

    /**
     * The JSON a chart blade assigns to `var $name = …;`.
     *
     * @return mixed
     */
    private function scriptValue(string $body, string $name)
    {
        $at = strpos($body, "var $name = ");
        if ($at === false) {
            return null;
        }
        $at += strlen("var $name = ");
        if ($body[$at] === '(') {
            $at++;
        }
        if (substr($body, $at, 4) === 'null') {
            return null;
        }
        $open = $body[$at];
        $close = $open === '[' ? ']' : '}';
        $depth = 0;
        $in_string = false;
        for ($i = $at, $n = strlen($body); $i < $n; $i++) {
            $c = $body[$i];
            if ($in_string) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '"') {
                    $in_string = false;
                }
            } elseif ($c === '"') {
                $in_string = true;
            } elseif ($c === '[' || $c === '{') {
                $depth++;
            } elseif (($c === ']' || $c === '}') && --$depth === 0) {
                return json_decode(substr($body, $at, $i - $at + 1), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        $this->fail("var $name: unterminated ($close)");
    }
}
