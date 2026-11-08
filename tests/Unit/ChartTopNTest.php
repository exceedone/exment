<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\GroupCondition;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\ChartDetailRequest;
use Exceedone\Exment\Services\Dashboard\ChartShowAs;
use Exceedone\Exment\Services\Dashboard\ChartSort;
use Exceedone\Exment\Services\Dashboard\ChartTopN;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * The 表示件数 of a chart box (`tn`, ChartTopN): the N largest points only, the rest grouped
 * into one point その他 at the end (Excel's Top 10 filter hides the rest; this keeps their
 * total).
 *
 * Checked against numbers worked out from the raw SQL of rows made for the test (with an empty
 * group, an empty value and negatives): the points kept and その他 for one series and for
 * several, under a runtime sort and a 表示方法; an average not grouped; the 明細 of その他 being
 * exactly the rows of the points left out; where the menu offers it.
 */
class ChartTopNTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    private const MARK = 'tn_case';

    /**
     * The rows made for the test: [index_text (X), odd_even (series), integer]. Per X, SUM:
     * g1 50, g3 28, g8 14, g5 12, g6 11 | g2 5, g9 3, (empty X) 1, g7 -1, g4 none — the top 5,
     * then その他 = 5 + 3 + 1 - 1 = 8 (s1: 5 + 1 - 1 = 5, s2: 3).
     */
    private const ROWS = [
        ['g1', 's1', 40], ['g1', 's2', 10],
        ['g2', 's1', 5],
        ['g3', 's2', 30], ['g3', 's1', -2],
        ['g4', 's1', null],
        ['g5', 's1', 12],
        ['g6', 's2', 11],
        ['g7', 's1', -1],
        ['g8', 's1', 7], ['g8', 's2', 7],
        ['g9', 's2', 3],
        [null, 's1', 1],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    // The cut ----------------------------------------------------------------------------------

    /**
     * `tn` parsed: N of the choices, "{N}" or "{N}:other"; anything else is no cut.
     */
    public function testTheParam(): void
    {
        $this->assertSame([5, false], ChartTopN::parse('5'));
        $this->assertSame([10, true], ChartTopN::parse('10:other'));
        $this->assertSame([20, true], ChartTopN::parse('20:other'));
        foreach (['', '0', '3', '7:other', '5:others', '5:', ' 5', '05', '5:other:other', 'all', null, 5, ['5']] as $bad) {
            $this->assertNull(ChartTopN::parse($bad), (string) json_encode($bad));
        }
        $this->assertSame('5:other', ChartTopN::normalize('5:other'));
        $this->assertNull(ChartTopN::normalize('6'));
        // remembered with the chart type and the sort, as given
        $this->assertSame(['cs' => 'y:desc', 'tn' => '10:other'], ChartItem::toolbarState(['cs' => 'y:desc', 'tn' => '10:other'], 'bar'));
        $this->assertSame(['tn' => '5'], ChartItem::toolbarState(['tn' => '5'], 'bar'));
        $this->assertNull(ChartItem::toolbarState(['tn' => '7'], 'bar'));
    }

    /**
     * The cut on numbers worked out by hand: the largest first, a point without a value below
     * any number (a negative one too), ties at the boundary in the view's order; その他 the
     * total of the rest, last — and with the runtime sort, the kept points in its order.
     */
    public function testTheCutByHand(): void
    {
        $result = [
            'chart_label' => collect(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']),
            'chart_data' => collect(['4', null, '9', '-3', '4', '4', '0.5', '4']),
            'chart_fields' => [['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']],
            'point_colors' => ['#1', '#2', '#3', '#4', '#5', '#6', '#7', '#8'],
            'chart_click' => ['column' => 'x', 'values' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'], 'selected' => [], 'highlight' => false],
            'detail' => ['points' => [[1 => 'A'], [1 => 'B'], [1 => 'C'], [1 => 'D'], [1 => 'E'], [1 => 'F'], [1 => 'G'], [1 => 'H']]],
        ];
        // 9 c; then the 4s in the view's order: a, e, f, h; left out: 0.5 g, -3 d, none b (last)
        $top = new ChartTopN(true, true, '4:other');
        $this->assertNull($top->n(), '4 is no choice');
        $top = new ChartTopN(true, true, '5:other');
        $cut = $top->applySingle($result, null, true);
        $this->assertSame(['c', 'a', 'e', 'f', 'h', exmtrans('dashboard.chart_top_n.other', 3)], $cut['chart_label']);
        $this->assertSame(['9', '4', '4', '4', '4', 0.5 - 3], $cut['chart_data'], 'その他: 0.5 - 3 (none adds nothing)');
        $this->assertSame(['#3', '#1', '#5', '#6', '#8', ChartTopN::REST_COLOR], $cut['point_colors'], 'each point keeps its color');
        $this->assertSame(['C', 'A', 'E', 'F', 'H', ''], $cut['chart_click']['values'], 'a click on その他 picks nothing');
        $this->assertSame([[1 => 'C'], [1 => 'A'], [1 => 'E'], [1 => 'F'], [1 => 'H'], [ChartTopN::REST_KEY => 1]], $cut['detail']['points']);
        $this->assertSame(5, $cut['top_n']['index']);
        $this->assertSame(3, $cut['top_n']['count']);
        $this->assertSame([['g', 0.5], ['d', -3], ['b', null]], $cut['top_n']['items'], 'largest first');
        $this->assertSame([[1 => 'C'], [1 => 'A'], [1 => 'E'], [1 => 'F'], [1 => 'H']], $cut['top_n']['excluded'], 'the 明細 keys of the points kept');
        $this->assertSame(8, $top->count());
        $this->assertSame(3, $top->hidden());

        // sorted by X, high to low: the kept points in that order, その他 still last
        $sorted = (new ChartTopN(true, true, '5:other'))->applySingle($result, ChartSort::parse('x0:desc'), true);
        $this->assertSame(['h', 'f', 'e', 'c', 'a'], array_slice($sorted['chart_label'], 0, 5));
        $this->assertSame([ChartTopN::REST_KEY => 1], end($sorted['detail']['points']) ?: null, 'the 明細 of その他 (keys of its own)');
        $sorted = (new ChartTopN(true, true, '5:other'))->applySingle($result, null, false);
        $this->assertNull(end($sorted['detail']['points']), 'no 明細 of その他');

        // without その他: the 5 only
        $only = (new ChartTopN(true, true, '5'))->applySingle($result, null, true);
        $this->assertSame(['c', 'a', 'e', 'f', 'h'], $only['chart_label']);
        $this->assertArrayNotHasKey('top_n', $only);
        // a measure that does not add up: the 5 only, whatever is asked
        $avg = new ChartTopN(true, false, '5:other');
        $this->assertFalse($avg->withOther());
        $this->assertSame(['c', 'a', 'e', 'f', 'h'], $avg->applySingle($result, null, true)['chart_label']);
        // no more points than N: none grouped, the largest first
        $all = (new ChartTopN(true, true, '10:other'))->applySingle($result, null, true);
        $this->assertSame(['c', 'a', 'e', 'f', 'h', 'g', 'd', 'b'], $all['chart_label']);
        $this->assertArrayNotHasKey('top_n', $all);
        // a chart that cannot be cut ignores it
        $this->assertFalse((new ChartTopN(false, true, '5:other'))->active());
    }

    /**
     * The labels of a time line, which a text column of months has.
     */
    public function testTimelineLabels(): void
    {
        foreach ([['2026-01', '2026-02'], ['2026/4/1', '2026/4/2'], ['2025年', '2026年'], ['2026年4月', '2026年5月', ''], ['4月', '5月'], ['2026-04-01', '2026.05.01']] as $labels) {
            $this->assertTrue(ChartTopN::isTimeline($labels), (string) json_encode($labels, JSON_UNESCAPED_UNICODE));
        }
        foreach ([['2026-01'], ['2026-01', 'その他'], ['1年', '2年'], ['2026', '2027'], ['A-1', 'B-2'], ['2026-01 東京', '2026-02 東京'], []] as $labels) {
            $this->assertFalse(ChartTopN::isTimeline($labels), (string) json_encode($labels, JSON_UNESCAPED_UNICODE));
        }
    }

    // The chart's numbers -------------------------------------------------------------------------

    /**
     * One series against the raw SQL of the rows: the SUM per X, ranked; the top 5 drawn with
     * their numbers, その他 their rest. Worked out by hand besides (ROWS).
     */
    public function testOneSeriesAgainstRawSql(): void
    {
        $this->createRows();
        $view = $this->rowsView(['index_text']);
        $sums = $this->rawSums('index_text');
        $this->assertCount(10, $sums, '9 groups and the empty one');

        $render = $this->render($view, 'bar', ['tn' => '5:other']);
        $expected = $this->expectedCut($sums, 5);
        $this->assertSame(['g1', 'g3', 'g8', 'g5', 'g6'], array_keys($expected['kept']), 'by hand');
        $this->assertEqualsWithDelta(8, $expected['rest'], 1e-9, 'by hand');
        $this->assertSame(array_merge(array_keys($expected['kept']), [exmtrans('dashboard.chart_top_n.other', 5)]), $render['labels']);
        $this->assertSeries(array_merge(array_values($expected['kept']), [$expected['rest']]), $render['values'], 'top 5 + その他');
        $this->assertSame(5, $render['rest']['index']);
        $this->assertSame(5, $render['rest']['count']);
        $this->assertSame(['g2', 'g9', '', 'g7', 'g4'], array_column($render['rest']['items'], 0), 'その他\'s tooltip: largest first');

        // 10: its 9 points + その他 of 1 (the empty one: g4)
        $ten = $this->render($view, 'bar', ['tn' => '10:other']);
        $this->assertCount(10, $ten['labels'], 'no more than 10: nothing grouped');
        $this->assertNull($ten['rest']);
        // the 5 only, and what is left out under the chart
        $only = $this->render($view, 'bar', ['tn' => '5']);
        $this->assertSame(array_keys($expected['kept']), $only['labels']);
        $this->assertStringContainsString('<div class="ct-topn-note">' . e(exmtrans('dashboard.chart_top_n.note', 5, 5)) . '</div>', $only['body']);
        $this->assertStringNotContainsString('ct-topn-note', $render['body']);
        // a COUNT is grouped the same way
        $count = $this->render($this->rowsView(['index_text'], SummaryCondition::COUNT), 'pie', ['tn' => '5:other']);
        $this->assertSame(array_sum($this->rawCounts('index_text')), (int) array_sum($count['values']), 'every row counted once');
    }

    /**
     * Several series (X index_text, series odd_even): X ranked by the total of its series, その他
     * summed per series — against the raw SQL per cell.
     */
    public function testSeveralSeriesAgainstRawSql(): void
    {
        $this->createRows();
        $view = $this->rowsView(['index_text', 'odd_even']);
        $cells = $this->rawCells('index_text', 'odd_even');
        $totals = [];
        foreach ($cells as $x => $series) {
            $totals[$x] = array_sum(array_filter($series, 'is_float'));
        }
        $expected = $this->expectedCut($totals, 5);
        foreach (['mbar', 'sbar', 'mline', 'sarea', 'sbar100'] as $type) {
            $render = $this->render($view, $type, ['tn' => '5:other']);
            [$categories, $series] = $render['labels'];
            $this->assertSame(array_merge(array_keys($expected['kept']), [exmtrans('dashboard.chart_top_n.other', 5)]), $categories, $type);
            foreach ($series as $si => $name) {
                $want = [];
                foreach (array_keys($expected['kept']) as $x) {
                    $want[] = $cells[$x][$name] ?? 0.0;
                }
                $rest = 0.0;
                foreach (array_keys($expected['rest_points']) as $x) {
                    $rest += $cells[$x][$name] ?? 0.0;
                }
                $want[] = $rest;
                $values = $type === 'sbar100' ? $this->scriptValue($render['body'], 'values')[$si] : $render['values'][$si];
                $this->assertSeries($want, $values, "$type $name");
            }
        }
        // by hand: その他 s1 = 5 + 1 - 1 (+ g4's none), s2 = 3
        $render = $this->render($view, 'mbar', ['tn' => '5:other']);
        $this->assertSeries([5, 3], [$this->seriesOf($render, 's1')[5], $this->seriesOf($render, 's2')[5]], 'by hand');
        $this->assertSame(5, $this->scriptValue($render['body'], 'detail')['rest'], 'its cells\' 明細');
        $this->assertNull($this->scriptValue($render['body'], 'detail')['x_keys'][5]);
    }

    /**
     * With the runtime sort the kept points are drawn in its order (その他 last); the 表示方法
     * calculates on the points drawn, その他 included: the shares add up to 100%, the running
     * total ends on the grand total, その他 shows no difference from the point before it.
     */
    public function testWithTheSortAndTheShowAs(): void
    {
        $this->createRows();
        $view = $this->rowsView(['index_text']);
        $other = exmtrans('dashboard.chart_top_n.other', 5);
        $this->assertSame(['g1', 'g3', 'g5', 'g6', 'g8', $other], $this->render($view, 'bar', ['tn' => '5:other', 'cs' => 'x0:asc'])['labels']);
        $this->assertSame(['g8', 'g6', 'g5', 'g3', 'g1', $other], $this->render($view, 'bar', ['tn' => '5:other', 'cs' => 'x0:desc'])['labels']);
        $this->assertSame(['g6', 'g5', 'g8', 'g3', 'g1', $other], $this->render($view, 'bar', ['tn' => '5:other', 'cs' => 'y:asc'])['labels']);

        // grand total 123: 50 28 14 12 11 + 8
        $share = $this->render($view, 'bar', ['tn' => '5:other', 'sv' => ChartShowAs::PERCENT_OF_TOTAL]);
        $this->assertSeries([50 / 1.23, 28 / 1.23, 14 / 1.23, 12 / 1.23, 11 / 1.23, 8 / 1.23], $share['values'], '% of the total');
        $this->assertEqualsWithDelta(100, array_sum($share['values']), 1e-6);
        $this->assertSeries([50, 78, 92, 104, 115, 123], $this->render($view, 'line', ['tn' => '5:other', 'sv' => ChartShowAs::RUNNING_TOTAL])['values'], 'running total');
        $this->assertSeries([null, -22, -14, -2, -1, null], $this->render($view, 'bar', ['tn' => '5:other', 'sv' => ChartShowAs::DIFFERENCE])['values'], 'difference');
        $this->assertSeries([null, -44, -50, -14.2857142857, -8.3333333333, null], $this->render($view, 'bar', ['tn' => '5:other', 'sv' => ChartShowAs::PERCENT_DIFFERENCE])['values'], '% difference');
        $this->assertSeries([50 / 1.23, 28 / 1.23, 14 / 1.23, 12 / 1.23, 11 / 1.23, 8 / 1.23], $this->render($view, 'doughnut', ['tn' => '5:other', 'sv' => ChartShowAs::PERCENT_OF_TOTAL])['values'], 'doughnut');

        // several series: the shares of every cell, その他 included
        $multi = $this->render($this->rowsView(['index_text', 'odd_even']), 'mbar', ['tn' => '5:other', 'sv' => ChartShowAs::PERCENT_OF_TOTAL]);
        $this->assertEqualsWithDelta(100, array_sum(array_map('array_sum', $multi['values'])), 1e-6);
    }

    /**
     * An average (the view's, or the 集計方法's) is not grouped into その他: the top N by their
     * averages only, with the line under the chart; the menu's checkbox disabled, saying why.
     */
    public function testAnAverageIsNotGrouped(): void
    {
        $this->createRows();
        $avg = $this->rowsView(['index_text'], SummaryCondition::AVG);
        $render = $this->render($avg, 'bar', ['tn' => '5:other']);
        // averages: g3 14, g5 12, g6 11, g8 7, g2 5 | g1 25 is the largest: g1 25, g3 14, g5 12, g6 11, g8 7
        $this->assertSame(['g1', 'g3', 'g5', 'g6', 'g8'], $render['labels']);
        $this->assertSeries([25, 14, 12, 11, 7], $render['values'], 'averages');
        $this->assertNull($render['rest']);
        $this->assertStringContainsString('data-tn-applied="5"', $render['body']);
        $this->assertStringContainsString('ct-topn-note', $render['body']);
        $this->assertMatchesRegularExpression('/class="cs-row tn-other dis" data-tn-other aria-disabled="true">/', $render['body']);
        $this->assertStringContainsString(e(exmtrans('dashboard.chart_top_n.other_not_additive')), $render['body']);

        $switched = $this->render($this->rowsView(['index_text']), 'bar', ['tn' => '5:other', 'ca' => SummaryCondition::AVG]);
        $this->assertSame(['g1', 'g3', 'g5', 'g6', 'g8'], $switched['labels'], 'switched to 平均');
        $this->assertNull($switched['rest']);
        $multi = $this->render($this->rowsView(['index_text', 'odd_even'], SummaryCondition::MAX), 'mbar', ['tn' => '5:other']);
        $this->assertCount(5, $multi['labels'][0], 'a maximum');
        $this->assertNull($multi['rest']);
    }

    // The 明細 of その他 ---------------------------------------------------------------------------

    /**
     * A double click on その他 lists the rows of the points left out — rows of the box's view
     * whose X is none of the kept ones (an empty X among them), with a value of the measure —
     * found by the server again, not sent: the same ids the raw SQL picks, adding up to その他.
     * A cell of several series: the series' rows of them.
     */
    public function testTheDetailOfTheRestListsTheRowsLeftOut(): void
    {
        $this->createRows();
        $view = $this->rowsView(['index_text']);
        $dashboard = $this->dashboard();
        $box = $this->savedBox($dashboard, $view, 'bar');
        $render = $this->render($view, 'bar', ['tn' => '5:other']);
        $kept = array_slice($render['labels'], 0, 5);
        $where = $this->textExpr() . ' = ? AND ' . $this->integerExpr() . ' IS NOT NULL'
            . ' AND (' . $this->idx('index_text') . ' IS NULL OR ' . $this->idx('index_text') . ' NOT IN (' . implode(',', array_fill(0, 5, '?')) . '))';
        $expected = $this->rawIds($where, array_merge([self::MARK], $kept));
        $this->assertCount(4, $expected, 'g2, g9, the empty X and g7 (g4 has no value)');
        $this->assertSame(8, (int) DB::selectOne('SELECT SUM(' . $this->integerExpr() . ') AS v FROM ' . $this->tableName() . ' WHERE id IN (' . implode(',', $expected) . ')')->v);

        $html = $this->detailPage($box, [ChartTopN::REST_KEY => 1], 'tn=5%3Aother');
        $this->assertSame($expected, $this->rowIdsIn($html), 'the rows left out');
        $this->assertStringContainsString(e(exmtrans('dashboard.chart_top_n.detail', 5, 5)), $html, 'what is listed');
        $this->assertStringNotContainsString(e(exmtrans('dashboard.chart_detail.invalid')), $html);

        // a cell of several series: s1's rows of the points left out (g2, the empty X, g7)
        $multi = $this->rowsView(['index_text', 'odd_even']);
        $multiBox = $this->savedBox($dashboard, $multi, 'mbar');
        $series = $this->scriptValue($this->render($multi, 'mbar', ['tn' => '5:other'])['body'], 'detail')['series'];
        $html = $this->detailPage($multiBox, [$series => 's1', ChartTopN::REST_KEY => 1], 'tn=5%3Aother');
        $s1 = $this->rawIds($where . ' AND ' . $this->idx('odd_even') . ' = ?', array_merge([self::MARK], $kept, ['s1']));
        $this->assertCount(3, $s1);
        $this->assertSame($s1, $this->rowIdsIn($html), 's1 of その他');

        // without the cut that drew it, no その他 to list
        $html = $this->detailPage($box, [ChartTopN::REST_KEY => 1], '');
        $this->assertStringContainsString(e(exmtrans('dashboard.chart_detail.invalid')), $html);
        $this->assertSame([], $this->rowIdsIn($html));
        // no key of a group sent besides
        $html = $this->detailPage($box, [ChartTopN::REST_KEY => 1, 999999 => 'g1'], 'tn=5%3Aother');
        $this->assertSame([], $this->rowIdsIn($html));
    }

    // Where it is offered --------------------------------------------------------------------------

    /**
     * The 表示件数 row of the box menu (⋯), between 集計方法 and 表示方法 in their group: すべて（n件）
     * and the choices, the checkbox; the picked one checked and named on the row, the box title
     * naming the cut (with its × back). None on a gauge / scatter / heatmap / boxplot, along a
     * date, with no more points than 5 (unless picked).
     */
    public function testTheRowOfTheBoxMenu(): void
    {
        $this->createRows();
        $view = $this->rowsView(['index_text']);
        $body = $this->render($view, 'bar', [])['body'];
        $menu = $this->between($body, 'class="ct-pop cs-pop"', 'exment-cs-reset');
        $this->assertNotNull($menu);
        $row = strpos($menu, 'data-tn-applied');
        $this->assertNotFalse($row);
        $this->assertGreaterThan(strpos($menu, 'data-ca-applied'), $row, 'after 集計方法');
        $this->assertLessThan(strpos($menu, 'data-sv-applied'), $row, 'before 表示方法');
        $this->assertStringContainsString('<ul class="cs-list" data-tn-applied=""', $menu, 'one group: no separator');
        $this->assertStringContainsString('<ul class="cs-list" data-sv-applied=""', $menu);
        foreach (['' => exmtrans('dashboard.chart_top_n.all', 10), '5' => exmtrans('dashboard.chart_top_n.top', 5), '10' => exmtrans('dashboard.chart_top_n.top', 10), '20' => exmtrans('dashboard.chart_top_n.top', 20)] as $n => $label) {
            $this->assertStringContainsString('data-tn-pick="' . $n . '"><i class="fa fa-check"></i><span>' . e($label) . '</span>', $menu);
        }
        $this->assertMatchesRegularExpression('/class="cs-row on" data-tn-pick=""/', $menu, 'すべて checked');
        $this->assertStringContainsString('class="cs-row tn-other on" data-tn-other>', $menu, 'その他 on by default');
        $this->assertStringNotContainsString('data-ca-title', $body, 'the configured title');

        $picked = $this->render($view, 'bar', ['tn' => '5'])['body'];
        $this->assertStringContainsString('cs-row ca-row tn-row ca-on', $picked);
        $this->assertMatchesRegularExpression('/class="cs-row on" data-tn-pick="5"/', $picked);
        $this->assertStringContainsString('class="cs-row tn-other" data-tn-other>', $picked, 'asked without その他');
        $this->assertMatchesRegularExpression('/<b class="ca-cur">' . preg_quote(e(exmtrans('dashboard.chart_top_n.top', 5)), '/') . '<\/b>/u', $picked);
        $group = CustomColumn::getEloquent('index_text', 'parent_table')->column_view_name;
        $title = exmtrans('dashboard.chart_show_as.decorated', exmtrans('dashboard.chart_summary.title', $group, $this->render($view, 'bar', [])['axisy']), exmtrans('dashboard.chart_top_n.top_other', 5));
        $this->assertStringContainsString('data-ca-title="' . e($title) . '"', $this->render($view, 'bar', ['tn' => '5:other'])['body']);

        // the types that cannot be cut
        foreach (['gauge', 'scatter'] as $type) {
            $render = $this->render($view, $type, ['tn' => '5:other']);
            $this->assertStringNotContainsString('data-tn-', $render['body'], $type);
            $this->assertCount(10, $render['labels'], "$type: every point");
        }
        $two = $this->rowsView(['index_text', 'odd_even']);
        foreach (['heatmap', 'boxplot'] as $type) {
            $render = $this->render($two, $type, ['tn' => '5:other']);
            $this->assertStringNotContainsString('data-tn-', $render['body'], $type);
            $this->assertCount(10, $render['labels'][0], "$type: every point");
        }
        foreach (['line', 'pie', 'hbar', 'area', 'doughnut', 'radar', 'funnel'] as $type) {
            $this->assertStringContainsString('data-tn-applied="5:other"', $this->render($view, $type, ['tn' => '5:other'])['body'], $type);
        }
        foreach (['mbar', 'sbar', 'mline', 'sarea', 'sbar100', 'shbar100', 'treemap', 'sunburst'] as $type) {
            $this->assertStringContainsString('data-tn-applied="5:other"', $this->render($two, $type, ['tn' => '5:other'])['body'], $type);
        }

        // along a date (by day, by month, by year — "2021" is no date by its label, by its
        // column it is): a period in その他 would break the time line
        foreach (range(2020, 2026) as $year) {
            $model = CustomTable::getEloquent('parent_table')->getValueModel();
            $model->setValue(['text' => self::MARK, 'date' => "$year-04-01", 'integer' => $year - 2000, 'multiples_of_3' => 0]);
            $model->saved_notify(false);
            $model->save();
        }
        foreach ([null, GroupCondition::YM, GroupCondition::Y] as $condition) {
            $dated = $this->aggregateView([
                'target_table_name' => 'parent_table',
                'column_settings' => [['column_name' => 'date'] + ($condition ? ['options' => ['view_group_condition' => $condition]] : [])],
                'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]],
                'filter_settings' => [['column_name' => 'text', 'filter_condition' => FilterOption::EQ, 'filter_value_text' => self::MARK]],
            ]);
            $render = $this->render($dated, 'bar', ['tn' => '5:other']);
            $this->assertGreaterThan(5, count($render['labels']), (string) $condition);
            $this->assertStringNotContainsString('data-tn-', $render['body'], (string) $condition);
        }
        // a text column of months (2026-01 …), told by its labels
        foreach (range(1, 7) as $month) {
            $model = CustomTable::getEloquent('parent_table')->getValueModel();
            $model->setValue(['text' => self::MARK . '_month', 'index_text' => sprintf('2026-%02d', $month), 'integer' => $month, 'multiples_of_3' => 0]);
            $model->saved_notify(false);
            $model->save();
        }
        $months = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'index_text']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]],
            'filter_settings' => [['column_name' => 'text', 'filter_condition' => FilterOption::EQ, 'filter_value_text' => self::MARK . '_month']],
        ]);
        $render = $this->render($months, 'bar', ['tn' => '5:other']);
        $this->assertCount(7, $render['labels']);
        $this->assertStringNotContainsString('data-tn-', $render['body'], 'months as text');
        // no more than 5 points: no row, unless picked (to be taken back)
        $few = $this->rowsView(['odd_even']);
        $this->assertStringNotContainsString('data-tn-', $this->render($few, 'bar', [])['body']);
        $this->assertStringContainsString('data-tn-applied="5:other"', $this->render($few, 'bar', ['tn' => '5:other'])['body']);
    }

    /**
     * Nothing picked, the chart is drawn as before: the view's order, every point, no その他.
     */
    public function testNothingPickedIsTheChartAsBefore(): void
    {
        $this->createRows();
        $view = $this->rowsView(['index_text']);
        $plain = $this->render($view, 'bar', ['cs' => 'x0:asc']);
        $this->assertSame(['', 'g1', 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8', 'g9'], $plain['labels']);
        $this->assertNull($plain['rest']);
        $this->assertStringNotContainsString('ct-topn-note', $plain['body']);
        $suuid = function (string $body): string {
            return (string) preg_replace('/\b[0-9a-f]{20}\b/', 'SUUID', $body);
        };
        foreach (['', 'x', '3', '5:all'] as $bad) {
            $this->assertSame($suuid($plain['body']), $suuid($this->render($view, 'bar', ['cs' => 'x0:asc', 'tn' => $bad])['body']), "tn=$bad");
        }
    }

    /**
     * The Excel download (and the CSV, from the chart's own points) has その他 as drawn.
     */
    public function testTheExportHasTheRest(): void
    {
        $this->createRows();
        $view = $this->rowsView(['index_text']);
        $item = new ChartItem($this->box($view, 'bar'), ['tn' => '5:other']);
        $data = $item->excelData();
        $this->assertIsArray($data);
        $this->assertSame(['g1', 'g3', 'g8', 'g5', 'g6', exmtrans('dashboard.chart_top_n.other', 5)], $data['categories']);
        $this->assertSeries([50, 28, 14, 12, 11, 8], $data['matrix'][0], 'Excel');
        $this->assertSame(ChartTopN::REST_COLOR, $data['point_colors'][5]);
        $this->assertStringContainsString(e(exmtrans('dashboard.chart_top_n.top_other', 5)), $data['title']);
    }

    // Helpers -----------------------------------------------------------------------------------

    private function createRows(): void
    {
        foreach (self::ROWS as [$x, $series, $integer]) {
            $model = CustomTable::getEloquent('parent_table')->getValueModel();
            $model->setValue(['text' => self::MARK, 'index_text' => $x, 'odd_even' => $series, 'integer' => $integer, 'multiples_of_3' => 0]);
            $model->saved_notify(false);
            $model->save();
        }
    }

    /**
     * @param string[] $groups
     */
    private function rowsView(array $groups, string $condition = SummaryCondition::SUM): CustomView
    {
        return $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => array_map(function ($column) {
                return ['column_name' => $column];
            }, $groups),
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => $condition]],
            'filter_settings' => [['column_name' => 'text', 'filter_condition' => FilterOption::EQ, 'filter_value_text' => self::MARK]],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function aggregateView(array $options): CustomView
    {
        System::clearCache();
        $view = $this->createCustomViewAll($options, ViewKindType::AGGREGATE)[1];
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        return $view;
    }

    /**
     * SUM(integer) of the test's rows per $x, from the table itself: an empty X as '', a group
     * without a value as null.
     *
     * @return array<string, float|null>
     */
    private function rawSums(string $x): array
    {
        $out = [];
        foreach ($this->rawGroups($x, null) as $row) {
            $out[(string) $row->x] = $row->y === null ? null : (float) $row->y;
        }
        return $out;
    }

    /**
     * SUM(integer) of the test's rows per $x and $series: [x][series].
     *
     * @return array<string, array<string, float|null>>
     */
    private function rawCells(string $x, string $series): array
    {
        $out = [];
        foreach ($this->rawGroups($x, $series) as $row) {
            $out[(string) $row->x][(string) $row->s] = $row->y === null ? null : (float) $row->y;
        }
        return $out;
    }

    /**
     * @return array<int, \stdClass>
     */
    private function rawGroups(string $x, ?string $series): array
    {
        $select = $this->idx($x) . ' AS x' . ($series ? ', ' . $this->idx($series) . ' AS s' : '');
        return DB::select("SELECT $select, SUM(" . $this->integerExpr() . ') AS y FROM ' . $this->tableName() . ' WHERE deleted_at IS NULL AND ' . $this->textExpr() . ' = ? GROUP BY x' . ($series ? ', s' : ''), [self::MARK]);
    }

    /**
     * @return array<string, int>
     */
    private function rawCounts(string $x): array
    {
        $rows = DB::select('SELECT ' . $this->idx($x) . ' AS x, COUNT(' . $this->integerExpr() . ') AS c FROM ' . $this->tableName() . ' WHERE deleted_at IS NULL AND ' . $this->textExpr() . ' = ? GROUP BY x', [self::MARK]);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->x] = (int) $row->c;
        }
        return $out;
    }

    /**
     * The cut of $values worked out apart from ChartTopN: the N largest (none last), largest first.
     *
     * @param array<string, float|null> $values
     * @return array{kept: array<string, float|null>, rest_points: array<string, float|null>, rest: float}
     */
    private function expectedCut(array $values, int $n): array
    {
        $keys = array_keys($values);
        usort($keys, function ($a, $b) use ($values) {
            if ($values[$a] === null || $values[$b] === null) {
                return ($values[$a] === null) <=> ($values[$b] === null);
            }
            return $values[$b] <=> $values[$a];
        });
        $kept = [];
        $rest_points = [];
        foreach ($keys as $i => $key) {
            if ($i < $n) {
                $kept[(string) $key] = $values[$key];
            } else {
                $rest_points[(string) $key] = $values[$key];
            }
        }
        return ['kept' => $kept, 'rest_points' => $rest_points, 'rest' => (float) array_sum(array_filter($rest_points, 'is_float'))];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{body: string, values: mixed, labels: mixed, rest: mixed, axisy: string|null}
     */
    private function render(CustomView $view, string $type, array $params): array
    {
        System::clearCache();
        $body = (string) (new ChartItem($this->box($view, $type), $params))->body();
        $multi = in_array($type, ['mbar', 'sbar', 'sbar100', 'shbar100', 'mline', 'sarea', 'heatmap', 'treemap', 'sunburst', 'boxplot'], true);
        $axisy = null;
        if (preg_match('/axisy: ("(?:[^"\\\\]|\\\\.)*"|null)/u', $body, $m) || preg_match('/var axisYName = ("(?:[^"\\\\]|\\\\.)*"|null);/u', $body, $m)) {
            $axisy = json_decode($m[1], true);
        }
        return [
            'body' => $body,
            'values' => $this->scriptValue($body, $multi ? 'matrix' : 'values'),
            'labels' => $multi ? [$this->scriptValue($body, 'xCategories'), $this->scriptValue($body, 'seriesNames')] : $this->scriptValue($body, 'labels'),
            'rest' => $this->scriptValue($body, 'rest'),
            'axisy' => $axisy,
        ];
    }

    /**
     * @param array<string, mixed> $render
     * @return array<int, mixed>
     */
    private function seriesOf(array $render, string $name): array
    {
        $index = array_search($name, $render['labels'][1], true);
        $this->assertIsInt($index, "series $name");
        return $render['values'][$index];
    }

    private function box(CustomView $view, string $type): DashboardBox
    {
        $x = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->first();
        $summary = CustomViewSummary::where('custom_view_id', $view->id)->first();
        $this->assertNotNull($x);
        $this->assertNotNull($summary);
        $box = new DashboardBox();
        $box->suuid = short_uuid();
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->dashboard_box_view_name = 'top_n';
        $box->options = [
            'target_table_id' => $view->custom_table_id,
            'target_view_id' => $view->id,
            'chart_type' => $type,
            'chart_axisx' => ViewKindType::DEFAULT . '_' . $x->id,
            'chart_axisy' => ViewKindType::AGGREGATE . '_' . $summary->id,
            'chart_axis_name' => ['1', '2'],
        ];
        return $box;
    }

    private function dashboard(): Dashboard
    {
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::USER;
        $dashboard->dashboard_name = 'top_n_' . short_uuid();
        $dashboard->dashboard_view_name = 'top n dashboard';
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();
        return $dashboard;
    }

    private function savedBox(Dashboard $dashboard, CustomView $view, string $type): DashboardBox
    {
        $box = $this->box($view, $type);
        $box->dashboard_id = $dashboard->id;
        $box->row_no = 1;
        $box->column_no = 1;
        $box->save();
        return $box;
    }

    /**
     * The data list of a 明細 of $box: its HTML.
     *
     * @param array<int|string, mixed> $keys
     */
    private function detailPage(DashboardBox $box, array $keys, string $query): string
    {
        System::clearCache();
        $url = admin_url('data/parent_table') . '?' . http_build_query([ChartDetailRequest::PARAM => json_encode(['b' => $box->suuid, 'k' => $keys, 'q' => $query])]);
        return $this->get($url)->assertOk()->getContent() ?: '';
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
     * @param array<int, mixed> $bindings
     * @return int[]
     */
    private function rawIds(string $where, array $bindings): array
    {
        $rows = DB::select('SELECT id FROM ' . $this->tableName() . " WHERE deleted_at IS NULL AND ($where) ORDER BY id", $bindings);
        return array_map(function ($row) {
            return (int) $row->id;
        }, $rows);
    }

    private function tableName(): string
    {
        return getDBTableName('parent_table');
    }

    /**
     * The index column of a column of parent_table, holding its text ('' none: NULL).
     */
    private function idx(string $name): string
    {
        $column = CustomColumn::getEloquent($name, 'parent_table');
        $this->assertNotNull($column);
        $this->assertTrue((bool) $column->index_enabled, "$name is indexed");
        return 'NULLIF(' . $column->getIndexColumnName() . ", '')";
    }

    private function textExpr(): string
    {
        return "JSON_UNQUOTE(JSON_EXTRACT(value, '$.text'))";
    }

    private function integerExpr(): string
    {
        return "CAST(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(value, '$.integer')), 'null'), '') AS DECIMAL(30, 10))";
    }

    private function between(string $body, string $from, string $to): ?string
    {
        $start = strpos($body, $from);
        $end = $start === false ? false : strpos($body, $to, $start);
        return $start === false || $end === false ? null : substr($body, $start, $end - $start);
    }

    /**
     * @param array<int, mixed> $expected
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
     * The JSON a chart blade assigns to `var $name = …;` (null: none, or null).
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
        $this->fail("var $name: unterminated");
    }
}
