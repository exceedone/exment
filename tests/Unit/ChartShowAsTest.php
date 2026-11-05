<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\ChartShowAs;
use Exceedone\Exment\Services\Dashboard\ChartSummarySwitch;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * The 表示方法 of a chart box (`sv`, ChartShowAs): the drawn values shown as Excel's PivotTable
 * shows them by 値の表示方法 — % of the grand total, a running total, the difference (and its
 * percent) from the previous item — calculated on the view's summarized numbers in the order
 * they are drawn.
 *
 * The numbers are checked against values worked out by hand from rows made for the test, with
 * an empty value, a 0 and negatives, and against the raw SQL of the table's own rows; for one
 * series and for several, in the view's order and after a runtime sort.
 */
class ChartShowAsTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    private const MARK = 'sv_case';

    private const PARAMS = [ChartShowAs::PARAM, ChartSummarySwitch::PARAM, 'cs', 'ct'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        foreach (self::PARAMS as $param) {
            request()->offsetUnset($param);
        }
        parent::tearDown();
    }

    // The calculation ----------------------------------------------------------------------------

    /**
     * Each way on a list of values with an empty one, a 0 and negatives, worked out by hand:
     * an empty value adds nothing to a total, has no difference and is no base of one; a percent
     * over 0 is empty (Excel: #DIV/0!); a percent difference is over the previous item sign and
     * all, as Excel's.
     */
    public function testEachWayByHand(): void
    {
        $values = ['200', '-40', '90', null, '-15', '0', '30'];
        $expected = [
            ChartShowAs::NONE => ['200', '-40', '90', null, '-15', '0', '30'],
            // total 265
            ChartShowAs::PERCENT_OF_TOTAL => [75.4716981132, -15.0943396226, 33.9622641509, null, -5.6603773585, 0.0, 11.3207547170],
            ChartShowAs::RUNNING_TOTAL => [200, 160, 250, 250, 235, 235, 265],
            ChartShowAs::DIFFERENCE => [null, -240, 130, null, null, 15, 30],
            // as Excel, over the previous item sign and all: -240 / 200, 130 / -40, (0 - -15) / -15, 30 / 0
            ChartShowAs::PERCENT_DIFFERENCE => [null, -120, -325, null, null, -100, null],
        ];
        foreach ($expected as $way => $want) {
            $show_as = new ChartShowAs($this->allWays(), $way);
            $this->assertSeries($want, $show_as->applyValues($values), $way);
        }
        // Excel: -100 → -50 is (-50 - -100) / -100 = -50%; a total of 0 divides nothing
        $this->assertSeries([null, -50], (new ChartShowAs($this->allWays(), ChartShowAs::PERCENT_DIFFERENCE))->applyValues([-100, -50]), 'over -100');
        $this->assertSeries([null, null], (new ChartShowAs($this->allWays(), ChartShowAs::PERCENT_OF_TOTAL))->applyValues([5, -5]), 'total 0');
        $this->assertSeries([null, null], (new ChartShowAs($this->allWays(), ChartShowAs::RUNNING_TOTAL))->applyValues([null, 'x']), 'nothing yet');
        $this->assertSame([], (new ChartShowAs($this->allWays(), ChartShowAs::RUNNING_TOTAL))->applyValues([]));
        // no float noise: 0.1 + 0.2
        $this->assertSame([0.1, 0.3], (new ChartShowAs($this->allWays(), ChartShowAs::RUNNING_TOTAL))->applyValues([0.1, 0.2]));
    }

    /**
     * Several series, as Excel with the series as the pivot's columns and the X axis as its rows:
     * the grand total is every cell's; a running total and a difference go along X per series.
     */
    public function testEachWayOnSeveralSeriesByHand(): void
    {
        $matrix = [[150, 0, 10], [50, -40, -25]];
        $expected = [
            // grand total 145
            ChartShowAs::PERCENT_OF_TOTAL => [[103.4482758621, 0.0, 6.8965517241], [34.4827586207, -27.5862068966, -17.2413793103]],
            ChartShowAs::RUNNING_TOTAL => [[150, 150, 160], [50, 10, -15]],
            ChartShowAs::DIFFERENCE => [[null, -150, 10], [null, -90, 15]],
            ChartShowAs::PERCENT_DIFFERENCE => [[null, -100, null], [null, -180, -37.5]],
        ];
        foreach ($expected as $way => $want) {
            $got = (new ChartShowAs($this->allWays(), $way))->applyMatrix($matrix);
            $this->assertCount(2, $got);
            $this->assertSeries($want[0], $got[0], "$way series 0");
            $this->assertSeries($want[1], $got[1], "$way series 1");
        }
        $this->assertSame($matrix, (new ChartShowAs($this->allWays(), null))->applyMatrix($matrix), 'as they are');
    }

    /**
     * The ways a chart offers by its type and the summary type it is drawn by.
     */
    public function testChoicesByTypeAndSummary(): void
    {
        $all = $this->allWays();
        foreach (['bar', 'hbar', 'line', 'area', 'mbar', 'mline'] as $type) {
            $this->assertSame($all, ChartShowAs::choicesFor($type, SummaryCondition::SUM, true), $type);
            $this->assertSame($all, ChartShowAs::choicesFor($type, SummaryCondition::COUNT, false), "$type COUNT");
        }
        // stacked: percents of each series add up to no percent of the stack
        foreach (['sbar', 'sarea'] as $type) {
            $this->assertSame([ChartShowAs::NONE, ChartShowAs::PERCENT_OF_TOTAL, ChartShowAs::RUNNING_TOTAL, ChartShowAs::DIFFERENCE], ChartShowAs::choicesFor($type, SummaryCondition::SUM, true), $type);
        }
        foreach (['pie', 'doughnut'] as $type) {
            $this->assertSame([ChartShowAs::NONE, ChartShowAs::PERCENT_OF_TOTAL], ChartShowAs::choicesFor($type, SummaryCondition::SUM, true), $type);
            $this->assertSame([], ChartShowAs::choicesFor($type, SummaryCondition::AVG, true), "$type AVG");
        }
        foreach (['radar', 'funnel', 'gauge', 'scatter', 'heatmap', 'treemap', 'sunburst', 'boxplot', 'unknown', null] as $type) {
            $this->assertSame([], ChartShowAs::choicesFor($type, SummaryCondition::SUM, true), (string) $type);
        }
        // an average / a minimum / a maximum adds up to nothing: the differences only
        foreach ([SummaryCondition::AVG, SummaryCondition::MIN, SummaryCondition::MAX] as $condition) {
            $this->assertSame([ChartShowAs::NONE, ChartShowAs::DIFFERENCE, ChartShowAs::PERCENT_DIFFERENCE], ChartShowAs::choicesFor('bar', $condition, true), $condition);
            $this->assertSame([], ChartShowAs::choicesFor('bar', $condition, false), "$condition of no number");
        }
        $this->assertSame([], ChartShowAs::choicesFor('bar', null, true), 'no summary column');
    }

    // The chart's numbers --------------------------------------------------------------------------

    /**
     * One series, the view's order (by X): each way equals the hand-worked numbers of the rows
     * made for the test (a: 120+30+50, b: 0-40, c: 90 and an empty one, d: empty only, e: -25+10).
     */
    public function testSingleSeriesByHand(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even']);
        $plain = $this->render($view, 'bar', null, ['cs' => 'x0:asc']);
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $plain['labels']);
        $this->assertSeries([200, -40, 90, null, -15], $plain['values'], 'as they are');

        $expected = [
            ChartShowAs::PERCENT_OF_TOTAL => [85.1063829787, -17.0212765957, 38.2978723404, null, -6.3829787234],
            ChartShowAs::RUNNING_TOTAL => [200, 160, 250, 250, 235],
            ChartShowAs::DIFFERENCE => [null, -240, 130, null, null],
            ChartShowAs::PERCENT_DIFFERENCE => [null, -120, -325, null, null],
        ];
        foreach (['bar', 'line', 'hbar', 'area'] as $type) {
            foreach ($expected as $way => $want) {
                $render = $this->render($view, $type, $way, ['cs' => 'x0:asc']);
                $this->assertSame(['a', 'b', 'c', 'd', 'e'], $render['labels'], "$type $way");
                $this->assertSeries($want, $render['values'], "$type $way");
            }
        }
        // the pie and the doughnut: the share of the total
        foreach (['pie', 'doughnut'] as $type) {
            $this->assertSeries($expected[ChartShowAs::PERCENT_OF_TOTAL], $this->render($view, $type, ChartShowAs::PERCENT_OF_TOTAL, ['cs' => 'x0:asc'])['values'], $type);
        }
    }

    /**
     * Sorted at runtime, the values are calculated in the order drawn, as Excel's on a sorted
     * pivot: sorted by the measure high to low, the running total is a Pareto line.
     */
    public function testSingleSeriesAfterASort(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even']);
        // by the view's values, high to low; the empty one last
        $render = $this->render($view, 'bar', ChartShowAs::RUNNING_TOTAL, ['cs' => 'y:desc']);
        $this->assertSame(['a', 'c', 'e', 'b', 'd'], $render['labels']);
        $this->assertSeries([200, 290, 275, 235, 235], $render['values'], 'running total');

        $render = $this->render($view, 'bar', ChartShowAs::DIFFERENCE, ['cs' => 'x0:desc']);
        $this->assertSame(['e', 'd', 'c', 'b', 'a'], $render['labels']);
        $this->assertSeries([null, null, null, -130, 240], $render['values'], 'difference');

        $render = $this->render($view, 'bar', ChartShowAs::PERCENT_DIFFERENCE, ['cs' => 'y:asc']);
        $this->assertSame(['b', 'e', 'c', 'a', 'd'], $render['labels']);
        // -15 - -40 over -40, 90 - -15 over -15, 200 - 90 over 90
        $this->assertSeries([null, -62.5, -700, 122.2222222222, null], $render['values'], 'percent difference');
    }

    /**
     * Several series (X: odd_even, series: index_text): the rows made for the test give
     * s1 = [150, 0, 0, 0, 10] and s2 = [50, -40, 90, 0, -25] over a..e — an empty cell of a SUM
     * is 0 on the chart, as before.
     */
    public function testSeveralSeriesByHand(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even', 'index_text']);
        $plain = $this->render($view, 'mbar', null, ['cs' => 'x0:asc']);
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $plain['labels'][0]);
        $this->assertSeries([150, 0, 0, 0, 10], $this->seriesOf($plain, 's1'), 's1 as it is');
        $this->assertSeries([50, -40, 90, 0, -25], $this->seriesOf($plain, 's2'), 's2 as it is');

        $expected = [
            // grand total 235
            ChartShowAs::PERCENT_OF_TOTAL => [[63.829787234, 0, 0, 0, 4.2553191489], [21.2765957447, -17.0212765957, 38.2978723404, 0, -10.6382978723]],
            ChartShowAs::RUNNING_TOTAL => [[150, 150, 150, 150, 160], [50, 10, 100, 100, 75]],
            ChartShowAs::DIFFERENCE => [[null, -150, 0, 0, 10], [null, -90, 130, -90, -25]],
            ChartShowAs::PERCENT_DIFFERENCE => [[null, -100, null, null, null], [null, -180, -325, -100, null]],
        ];
        foreach (['mbar', 'mline', 'sbar', 'sarea'] as $type) {
            foreach ($expected as $way => $want) {
                if (in_array($type, ['sbar', 'sarea'], true) && $way === ChartShowAs::PERCENT_DIFFERENCE) {
                    continue; // not offered (testChoicesByTypeAndSummary)
                }
                $render = $this->render($view, $type, $way, ['cs' => 'x0:asc']);
                $this->assertSame(['a', 'b', 'c', 'd', 'e'], $render['labels'][0], "$type $way");
                $this->assertSeries($want[0], $this->seriesOf($render, 's1'), "$type $way s1");
                $this->assertSeries($want[1], $this->seriesOf($render, 's2'), "$type $way s2");
            }
        }

        // sorted by the stacks' totals (a 200, c 90, d 0, e -15, b -40), then accumulated
        $render = $this->render($view, 'sbar', ChartShowAs::RUNNING_TOTAL, ['cs' => 'y:desc']);
        $this->assertSame(['a', 'c', 'd', 'e', 'b'], $render['labels'][0]);
        $this->assertSeries([150, 150, 150, 160, 160], $this->seriesOf($render, 's1'), 'sorted s1');
        $this->assertSeries([50, 140, 140, 115, 75], $this->seriesOf($render, 's2'), 'sorted s2');
    }

    /**
     * The table's own rows (the test data, no filter) against its raw SQL: per odd_even and per
     * odd_even × multiples_of_3, SUM(integer) of the rows not deleted, then each way worked out
     * on those numbers in the order the chart draws them.
     */
    public function testTheTablesRowsAgainstRawSql(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $table = getDBTableName(CustomTable::getEloquent('parent_table'));
        $value = function (string $column) {
            return "NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(value, '$.$column')), 'null'), '')";
        };
        $sum = function (string $x, string $s) use ($table, $value) {
            return DB::select("SELECT {$value($x)} AS x, {$value($s)} AS s, SUM(CAST({$value('integer')} AS DECIMAL(30, 10))) AS y FROM $table WHERE deleted_at IS NULL GROUP BY x, s");
        };

        // one series: per odd_even
        $by_x = [];
        foreach ($sum('odd_even', 'odd_even') as $row) {
            $by_x[$row->x] = $row->y === null ? null : (float) $row->y;
        }
        $this->assertCount(2, $by_x);
        $view = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]],
        ]);
        foreach (['x0:asc', 'y:desc'] as $cs) {
            $plain = $this->render($view, 'bar', null, ['cs' => $cs]);
            $drawn = array_map(function ($x) use ($by_x) {
                return $by_x[$x];
            }, $plain['labels']);
            $this->assertSeries($drawn, $plain['values'], "$cs as they are");
            foreach ($this->expectedWays($drawn) as $way => $want) {
                $this->assertSeries($want, $this->render($view, 'bar', $way, ['cs' => $cs])['values'], "$cs $way");
            }
        }

        // several series: X text (test_1 … test_10) × series odd_even (an empty cell of a SUM: 0)
        $view = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'text'], ['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]],
        ]);
        $cells = [];
        foreach ($sum('text', 'odd_even') as $row) {
            $cells[$row->s][$row->x] = (float) $row->y;
        }
        $plain = $this->render($view, 'mbar', null, ['cs' => 'x0:asc']);
        $this->assertCount(2, $plain['labels'][1], 'odd, even');
        $this->assertGreaterThan(2, count($plain['labels'][0]));
        $drawn = [];
        foreach ($plain['labels'][1] as $si => $name) {
            foreach ($plain['labels'][0] as $x) {
                $drawn[$si][] = $cells[$name][$x] ?? 0.0;
            }
        }
        foreach ($drawn as $si => $want) {
            $this->assertSeries($want, $plain['values'][$si], "series $si as it is");
        }
        foreach ($this->expectedWays(null, $drawn) as $way => $want) {
            $got = $this->render($view, 'mbar', $way, ['cs' => 'x0:asc'])['values'];
            foreach ($want as $si => $series) {
                $this->assertSeries($series, $got[$si], "$way series $si");
            }
        }
    }

    /**
     * With the 集計方法: an average is shown by its differences (a: 66.67, b: -20, c: 90, d: none,
     * e: -7.5), the same whether the view sets 平均 or the menu switches to it; a running total of
     * it is not taken — the chart draws the averages.
     */
    public function testWithTheSummaryType(): void
    {
        $this->createRows();
        $average = [66.67, -20, 90, null, -7.5];
        $difference = [null, -86.67, 110, null, null];
        $set = $this->rowsView(['odd_even'], SummaryCondition::AVG);
        $this->assertSeries($average, $this->render($set, 'bar', null, ['cs' => 'x0:asc'])['values'], 'the view\'s averages');
        $this->assertSeries($difference, $this->render($set, 'bar', ChartShowAs::DIFFERENCE, ['cs' => 'x0:asc'])['values'], 'view AVG');

        $sum = $this->rowsView(['odd_even']);
        $switched = $this->render($sum, 'bar', ChartShowAs::DIFFERENCE, ['cs' => 'x0:asc', 'ca' => SummaryCondition::AVG]);
        $this->assertSeries($difference, $switched['values'], 'switched to AVG');
        $this->assertStringContainsString('data-sv-applied="' . ChartShowAs::DIFFERENCE . '"', $switched['body']);

        $ignored = $this->render($sum, 'bar', ChartShowAs::RUNNING_TOTAL, ['cs' => 'x0:asc', 'ca' => SummaryCondition::AVG]);
        $this->assertSeries($average, $ignored['values'], 'no running total of averages');
        $this->assertStringContainsString('data-sv-applied=""', $ignored['body']);
        $this->assertSame(3, substr_count($ignored['body'], 'data-sv-pick='), '計算なし and the two differences');
    }

    // What the box shows --------------------------------------------------------------------------

    /**
     * The 表示方法 row of the box menu (⋯), right under 集計方法 in one group, opening a submenu
     * as Excel's 値の表示方法 ▸; 計算なし checked and named on the row unless another is picked.
     */
    public function testTheRowOfTheBoxMenu(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even']);
        $body = $this->render($view, 'bar', null)['body'];
        $menu = $this->between($body, 'class="ct-pop cs-pop"', 'exment-cs-reset');
        $this->assertNotNull($menu);
        $row = strpos($menu, 'data-sv-applied');
        $this->assertNotFalse($row);
        $this->assertGreaterThan(strpos($menu, 'data-ca-open'), $row, 'after 集計方法');
        $this->assertLessThan(strpos($menu, 'data-cd-toggle'), $row, 'before the display options');
        $this->assertStringContainsString('<ul class="cs-list" data-sv-applied=""', $menu, 'one group with 集計方法: no separator');
        $this->assertMatchesRegularExpression('/<i class="fa fa-percent"><\/i><span>' . preg_quote(e(exmtrans('dashboard.chart_show_as.menu')), '/') . '<\/span>\s*<b class="ca-cur">' . preg_quote(e(ChartShowAs::label(ChartShowAs::NONE)), '/') . '<\/b>/u', $menu);
        $this->assertSame(5, substr_count($menu, 'data-sv-pick='));
        foreach ($this->allWays() as $way) {
            $this->assertStringContainsString('data-sv-pick="' . $way . '"><i class="fa fa-check"></i><span>' . e(ChartShowAs::label($way)) . '</span>', $menu);
        }
        $this->assertMatchesRegularExpression('/class="cs-row on" data-sv-pick="' . ChartShowAs::NONE . '"/', $menu, '計算なし checked');
        $column = CustomColumn::getEloquent('integer', 'parent_table')->column_view_name;
        $this->assertStringContainsString(e(exmtrans('dashboard.chart_show_as.heading', $column)), $menu);

        $picked = $this->render($view, 'bar', ChartShowAs::RUNNING_TOTAL)['body'];
        $this->assertStringContainsString('data-sv-applied="' . ChartShowAs::RUNNING_TOTAL . '"', $picked);
        $this->assertStringContainsString('cs-row ca-row sv-row ca-on', $picked);
        $this->assertMatchesRegularExpression('/class="cs-row on" data-sv-pick="' . ChartShowAs::RUNNING_TOTAL . '"/', $picked);
        $this->assertMatchesRegularExpression('/<b class="ca-cur">' . preg_quote(e(ChartShowAs::label(ChartShowAs::RUNNING_TOTAL)), '/') . '<\/b>/u', $picked);

        // the rows the types offer; none on the others (no row at all)
        $two = $this->rowsView(['odd_even', 'index_text']);
        foreach (['line' => 5, 'hbar' => 5, 'area' => 5, 'pie' => 2, 'doughnut' => 2] as $type => $count) {
            $this->assertSame($count, substr_count($this->render($view, $type, null)['body'], 'data-sv-pick='), $type);
        }
        foreach (['mbar' => 5, 'mline' => 5, 'sbar' => 4, 'sarea' => 4] as $type => $count) {
            $this->assertSame($count, substr_count($this->render($two, $type, null)['body'], 'data-sv-pick='), $type);
        }
        foreach (['radar', 'funnel', 'gauge', 'scatter'] as $type) {
            $this->assertStringNotContainsString('data-sv-', $this->render($view, $type, null)['body'], $type);
        }
        foreach (['heatmap', 'treemap', 'sunburst', 'boxplot'] as $type) {
            $this->assertStringNotContainsString('data-sv-', $this->render($two, $type, null)['body'], $type);
        }
    }

    /**
     * A COUNT of a column that is no number (no 集計方法 row: COUNT only) still has its 表示方法:
     * the row stands alone, after the sort, separated from it.
     */
    public function testACountHasItWithoutTheSummaryRow(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even'], SummaryCondition::COUNT, 'text');
        $render = $this->render($view, 'bar', ChartShowAs::PERCENT_OF_TOTAL, ['cs' => 'x0:asc']);
        $this->assertStringNotContainsString('data-ca-pick', $render['body'], 'no 集計方法');
        $this->assertStringContainsString('<ul class="cs-list cs-sep" data-sv-applied="' . ChartShowAs::PERCENT_OF_TOTAL . '"', $render['body']);
        // 3 + 2 + 2 + 1 + 2 = 10 rows
        $this->assertSeries([30, 20, 20, 10, 20], $render['values'], 'counts over 10');
    }

    /**
     * Picked, the box names what it draws: the Y axis, the tooltips, the legend and the export
     * (合計 ： 整数（累計）) and the box title (with its × back), as Excel names the field;
     * percents carry "%". A stacked bar is labelled with its stacks' totals.
     */
    public function testThePickedBoxNamesWhatItDraws(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even']);
        $group = CustomColumn::getEloquent('odd_even', 'parent_table')->column_view_name;
        $plain = $this->render($view, 'bar', null);
        $axisy = $plain['axisy'];
        $this->assertNotEmpty($axisy);
        $this->assertStringContainsString('var percent = false', $plain['body']);
        $this->assertStringNotContainsString('data-ca-title', $plain['body']);

        $running = exmtrans('dashboard.chart_show_as.decorated', $axisy, ChartShowAs::label(ChartShowAs::RUNNING_TOTAL));
        $render = $this->render($view, 'bar', ChartShowAs::RUNNING_TOTAL);
        $this->assertSame($running, $render['axisy']);
        $this->assertStringContainsString('var valueName = ' . json_encode($running), $render['body'], 'tooltip');
        $this->assertStringContainsString('label: ' . json_encode($running) . ',', $render['body'], 'legend');
        $this->assertStringContainsString('var percent = false', $render['body']);
        $title = exmtrans('dashboard.chart_show_as.decorated', exmtrans('dashboard.chart_summary.title', $group, $axisy), ChartShowAs::label(ChartShowAs::RUNNING_TOTAL));
        $this->assertStringContainsString('data-ca-title="' . e($title) . '"', $render['body']);
        $this->assertStringContainsString('data-ca-reset-label=', $render['body'], 'the title\'s ×');

        foreach (['bar', 'hbar', 'doughnut'] as $type) {
            $body = $this->render($view, $type, ChartShowAs::PERCENT_OF_TOTAL)['body'];
            $this->assertStringContainsString('var percent = true', $body, $type);
        }
        $this->assertStringContainsString("callback: function (v) { return Number(v).toLocaleString() + '%'; }", $this->render($view, 'bar', ChartShowAs::PERCENT_DIFFERENCE)['body'], 'Y axis in %');
        $this->assertStringNotContainsString("toLocaleString() + '%'", $plain['body']);

        // with the 集計方法 switched too: what both draw
        $both = $this->render($view, 'bar', ChartShowAs::DIFFERENCE, ['ca' => SummaryCondition::MAX]);
        $max = exmtrans('common.format_keyvalue', exmtrans('custom_view.summary_condition_options.max'), CustomColumn::getEloquent('integer', 'parent_table')->column_view_name);
        $this->assertSame(exmtrans('dashboard.chart_show_as.decorated', $max, ChartShowAs::label(ChartShowAs::DIFFERENCE)), $both['axisy']);
        $this->assertStringContainsString('data-ca-title="' . e(exmtrans('dashboard.chart_show_as.decorated', exmtrans('dashboard.chart_summary.title', $group, $max), ChartShowAs::label(ChartShowAs::DIFFERENCE))) . '"', $both['body']);

        // several series: the stacked bar's totals on top while picked
        $two = $this->rowsView(['odd_even', 'index_text']);
        $this->assertStringContainsString('var stackTotal = true', $this->render($two, 'sbar', ChartShowAs::PERCENT_OF_TOTAL)['body']);
        $this->assertStringContainsString('var percent = true', $this->render($two, 'sbar', ChartShowAs::PERCENT_OF_TOTAL)['body']);
        $this->assertStringContainsString('var stackTotal = false', $this->render($two, 'sbar', null)['body']);
        $this->assertStringContainsString('var stackTotal = false', $this->render($two, 'mbar', ChartShowAs::RUNNING_TOTAL)['body']);
        $this->assertSame(exmtrans('dashboard.chart_show_as.decorated', $this->render($two, 'mbar', null)['axisy'], ChartShowAs::label(ChartShowAs::RUNNING_TOTAL)), $this->render($two, 'mbar', ChartShowAs::RUNNING_TOTAL)['axisy']);
    }

    /**
     * 計算なし, nothing or a way the chart does not offer draws the chart as before — the same
     * body as without the parameter; the choice is never remembered with the toolbar's.
     */
    public function testNothingPickedIsTheChartAsBefore(): void
    {
        $this->createRows();
        $one = $this->rowsView(['odd_even']);
        $two = $this->rowsView(['odd_even', 'index_text']);
        foreach ([[$one, 'bar'], [$one, 'pie'], [$one, 'radar'], [$two, 'sbar'], [$two, 'heatmap']] as [$view, $type]) {
            $plain = $this->render($view, $type, null);
            $this->assertNotNull($plain['values'], $type);
            foreach ([ChartShowAs::NONE, '', 'bogus', ['x'], ChartShowAs::PERCENT_DIFFERENCE . ' '] as $sv) {
                $this->assertSame($plain['body'], $this->render($view, $type, $sv)['body'], "$type " . json_encode($sv));
            }
        }
        $this->assertSame($this->render($one, 'pie', null)['body'], $this->render($one, 'pie', ChartShowAs::RUNNING_TOTAL)['body'], 'pie: no running total');
        $this->assertSame($this->render($two, 'sbar', null)['body'], $this->render($two, 'sbar', ChartShowAs::PERCENT_DIFFERENCE)['body'], 'stacked: no percent difference');

        // a list view draws its rows: no 表示方法
        $list = $this->createCustomViewAll([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'integer']],
            'filter_settings' => [['column_name' => 'text', 'filter_condition' => FilterOption::EQ, 'filter_value_text' => self::MARK]],
        ], ViewKindType::DEFAULT)[1];
        $columns = CustomViewColumn::where('custom_view_id', $list->id)->orderBy('order')->get();
        request()->merge([ChartShowAs::PARAM => ChartShowAs::RUNNING_TOTAL]);
        $body = (string) (new ChartItem($this->box($list, 'bar', ViewKindType::DEFAULT . '_' . $columns[0]->id, ViewKindType::DEFAULT . '_' . $columns[1]->id)))->body();
        $this->assertStringNotContainsString('data-sv-', $body);
        $this->assertStringContainsString('var percent = false', $body);

        $this->assertNull(ChartItem::toolbarState([ChartShowAs::PARAM => ChartShowAs::RUNNING_TOTAL], 'bar'), 'not remembered');
    }

    // Helpers ------------------------------------------------------------------------------------

    /**
     * @return string[]
     */
    private function allWays(): array
    {
        return [ChartShowAs::NONE, ChartShowAs::PERCENT_OF_TOTAL, ChartShowAs::RUNNING_TOTAL, ChartShowAs::DIFFERENCE, ChartShowAs::PERCENT_DIFFERENCE];
    }

    /**
     * Each way of $values (one series) or $matrix (several), worked out the plain way: what the
     * chart must draw for the numbers it draws as they are.
     *
     * @param array<int, float|null>|null $values
     * @param array<int, array<int, float|null>>|null $matrix
     * @return array<string, array<int, mixed>>
     */
    private function expectedWays(?array $values, ?array $matrix = null): array
    {
        $rows = $matrix ?? [$values];
        $total = 0;
        foreach ($rows as $row) {
            foreach ($row as $v) {
                $total += $v ?? 0;
            }
        }
        $out = [];
        foreach ($rows as $si => $row) {
            $sum = null;
            foreach ($row as $x => $v) {
                $previous = $x > 0 ? $row[$x - 1] : null;
                $out[ChartShowAs::PERCENT_OF_TOTAL][$si][] = $v === null || $total == 0 ? null : $v / $total * 100;
                $sum = $v === null ? $sum : ($sum ?? 0) + $v;
                $out[ChartShowAs::RUNNING_TOTAL][$si][] = $sum;
                $out[ChartShowAs::DIFFERENCE][$si][] = $v === null || $previous === null ? null : $v - $previous;
                $out[ChartShowAs::PERCENT_DIFFERENCE][$si][] = $v === null || $previous === null || $previous == 0 ? null : ($v - $previous) / $previous * 100;
            }
        }
        return $matrix === null ? array_map(function ($ways) {
            return $ways[0];
        }, $out) : $out;
    }

    /**
     * Rows of parent_table marked self::MARK (text): odd_even a..e, index_text s1 / s2, integer
     * a: 120, 30 (s1), 50 (s2) — b: 0 (s1), -40 (s2) — c: empty (s1), 90 (s2) — d: empty (s1)
     * — e: -25 (s2), 10 (s1).
     */
    private function createRows(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $rows = [['a', 's1', 120], ['a', 's1', 30], ['a', 's2', 50], ['b', 's1', 0], ['b', 's2', -40], ['c', 's1', null], ['c', 's2', 90], ['d', 's1', null], ['e', 's2', -25], ['e', 's1', 10]];
        foreach ($rows as [$x, $s, $integer]) {
            $model = CustomTable::getEloquent('parent_table')->getValueModel();
            $model->setValue(['text' => self::MARK, 'odd_even' => $x, 'index_text' => $s, 'integer' => $integer, 'multiples_of_3' => 0]);
            $model->saved_notify(false);
            $model->save();
        }
    }

    /**
     * An aggregate view of the rows createRows() makes, grouped by $groups, summarizing $measure.
     *
     * @param string[] $groups
     */
    private function rowsView(array $groups, string $condition = SummaryCondition::SUM, string $measure = 'integer'): CustomView
    {
        return $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => array_map(function ($column) {
                return ['column_name' => $column];
            }, $groups),
            'summary_settings' => [['column_name' => $measure, 'summary_condition' => $condition]],
            'filter_settings' => [['column_name' => 'text', 'filter_condition' => FilterOption::EQ, 'filter_value_text' => self::MARK]],
        ]);
    }

    /**
     * A box of $view drawn as $type with `sv` = $sv and the other request values $params: its
     * body, the drawn points, their labels ([x categories, series names] on a multi-series
     * chart) and the Y label.
     *
     * @param mixed $sv
     * @param array<string, string> $params
     * @return array{body: string, values: mixed, labels: mixed, axisy: mixed}
     */
    private function render(CustomView $view, string $type, $sv, array $params = []): array
    {
        System::clearCache();
        foreach (self::PARAMS as $param) {
            request()->offsetUnset($param);
        }
        request()->merge($params + ($sv === null ? [] : [ChartShowAs::PARAM => $sv]));
        $summary = CustomViewSummary::where('custom_view_id', $view->id)->first();
        $this->assertNotNull($summary);
        $x = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->first();
        $this->assertNotNull($x);
        $body = (string) (new ChartItem($this->box($view, $type, ViewKindType::DEFAULT . '_' . $x->id, ViewKindType::AGGREGATE . '_' . $summary->id)))->body();
        foreach (self::PARAMS as $param) {
            request()->offsetUnset($param);
        }
        $multi = in_array($type, ['mbar', 'sbar', 'mline', 'sarea', 'heatmap', 'treemap', 'sunburst', 'boxplot'], true);
        return [
            'body' => $body,
            'values' => $this->scriptJson($body, $multi ? 'matrix' : 'values'),
            'labels' => $multi ? [$this->scriptJson($body, 'xCategories'), $this->scriptJson($body, 'seriesNames')] : $this->scriptJson($body, 'labels'),
            'axisy' => $this->axisY($body),
        ];
    }

    /**
     * The drawn values of the series named $name of a multi-series render.
     *
     * @param array{values: mixed, labels: mixed} $render
     * @return array<int, mixed>
     */
    private function seriesOf(array $render, string $name): array
    {
        $index = array_search($name, $render['labels'][1], true);
        $this->assertIsInt($index, "series $name");
        return $render['values'][$index];
    }

    /**
     * @param array<int, mixed> $expected  null = no value
     * @param mixed $actual
     */
    private function assertSeries(array $expected, $actual, string $message): void
    {
        $this->assertIsArray($actual, $message);
        $this->assertCount(count($expected), $actual, $message);
        foreach (array_values($expected) as $i => $want) {
            $got = array_values($actual)[$i];
            if ($want === null) {
                $this->assertNull($got, "$message [$i]");
            } else {
                $this->assertIsNumeric($got, "$message [$i]");
                $this->assertEqualsWithDelta((float) $want, (float) $got, 1e-6, "$message [$i]");
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function aggregateView(array $options): CustomView
    {
        System::clearCache();
        return $this->createCustomViewAll($options, ViewKindType::AGGREGATE)[1];
    }

    private function box(CustomView $view, string $type, string $x, string $y): DashboardBox
    {
        $box = new DashboardBox();
        $box->options = [
            'target_table_id' => $view->custom_table_id,
            'target_view_id' => $view->id,
            'chart_type' => $type,
            'chart_axisx' => $x,
            'chart_axisy' => $y,
            'chart_axis_name' => ['1', '2'],
        ];
        return $box;
    }

    /**
     * The part of $body from $from to $to; null when either is missing.
     */
    private function between(string $body, string $from, string $to): ?string
    {
        $start = strpos($body, $from);
        $end = $start === false ? false : strpos($body, $to, $start);
        return $start === false || $end === false ? null : substr($body, $start, $end - $start);
    }

    /**
     * The JSON a chart blade assigns to `var $name = …;`.
     *
     * @return mixed
     */
    private function scriptJson(string $body, string $name)
    {
        $at = strpos($body, "var $name = ");
        if ($at === false) { // no chart drawn (a message)
            return null;
        }
        $at += strlen("var $name = ");
        if ($body[$at] === '(') { // echart: ({!! $chart_data !!} || [])
            $at++;
        }
        if (substr($body, $at, 4) === 'null') {
            return null;
        }
        $this->assertSame('[', $body[$at], "var $name: an array");
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
            } elseif ($c === '[') {
                $depth++;
            } elseif ($c === ']' && --$depth === 0) {
                return json_decode(substr($body, $at, $i - $at + 1), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        $this->fail("var $name: unterminated");
    }

    /**
     * The Y label a chart hands its export (`axisy` of the data hook / axisYName).
     */
    private function axisY(string $body): ?string
    {
        if (preg_match('/axisy: ("(?:[^"\\\\]|\\\\.)*"|null)/u', $body, $m) || preg_match('/var axisYName = ("(?:[^"\\\\]|\\\\.)*"|null);/u', $body, $m)) {
            return json_decode($m[1], true);
        }
        return null;
    }
}
