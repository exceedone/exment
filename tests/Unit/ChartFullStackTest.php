<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\ChartShowAs;
use Exceedone\Exment\Services\Dashboard\ChartSummarySwitch;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * The 100% stacked chart types (sbar100 / shbar100, Excel's 100% 積み上げ縦棒 / 横棒): every X item
 * a bar of 100%, each series its share of the bar — ChartShowAs::PERCENT_OF_ROW on the cells as
 * drawn — with the numbers they are shares of beside them for the tooltip and the CSV.
 *
 * The shares are checked against numbers worked out by hand from rows made for the test (an
 * empty cell, a bar of 0, negatives) and against the raw SQL of the table's own rows; a measure
 * that does not add up (平均 / 最小 / 最大) is refused, on the chart, the 集計方法 and the box form.
 */
class ChartFullStackTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    private const MARK = 'full_stack_case';

    private const PARAMS = [ChartShowAs::PARAM, ChartSummarySwitch::PARAM, 'cs', 'ct', 'options'];

    private const TYPES = ['sbar100', 'shbar100'];

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
     * Each cell over the size of its X item (the sum of its cells' sizes): an empty cell adds
     * nothing and stays empty, an item of 0 has no share at all, a negative cell is its size's
     * share below 0 (Excel's 100% stacked chart).
     */
    public function testSharesByHand(): void
    {
        $matrix = [[150, '0', null, 0, 10, 3], [50, -40, 90, 0, -25, '1.5'], [null, null, null, null, null, 1.5]];
        $got = ChartShowAs::fullStacked()->applyMatrix($matrix);
        $this->assertCount(3, $got);
        // sizes 200, 40, 90, 0, 35, 6
        $this->assertSeries([75, 0, null, null, 28.5714285714, 50], $got[0], 's1');
        $this->assertSeries([25, -100, 100, null, -71.4285714286, 25], $got[1], 's2');
        $this->assertSeries([null, null, null, null, null, 25], $got[2], 's3');
        $this->assertTrue(ChartShowAs::fullStacked()->isPercent());
        $this->assertSame([], ChartShowAs::fullStacked()->applyMatrix([]));
    }

    /**
     * The type shows the shares itself: the 表示方法 offers 計算なし only, and the way of the
     * 100% stack is in no other type's menu.
     */
    public function testChoices(): void
    {
        foreach (self::TYPES as $type) {
            $this->assertSame([ChartShowAs::NONE], ChartShowAs::choicesFor($type, SummaryCondition::SUM, true), $type);
            $this->assertSame([ChartShowAs::NONE], ChartShowAs::choicesFor($type, SummaryCondition::COUNT, false), "$type COUNT");
            $this->assertSame([], ChartShowAs::choicesFor($type, null, true), "$type no summary column");
        }
        foreach (['bar', 'hbar', 'line', 'area', 'pie', 'doughnut', 'mbar', 'sbar', 'mline', 'sarea'] as $type) {
            $this->assertNotContains(ChartShowAs::PERCENT_OF_ROW, ChartShowAs::choicesFor($type, SummaryCondition::SUM, true), $type);
        }
        $this->assertFalse((new ChartShowAs(ChartShowAs::choicesFor('sbar', SummaryCondition::SUM, true), ChartShowAs::PERCENT_OF_ROW))->active(), 'not by `sv`');
        $this->assertTrue(ChartSummarySwitch::isAdditive(SummaryCondition::SUM));
        $this->assertTrue(ChartSummarySwitch::isAdditive((int) SummaryCondition::COUNT));
        foreach ([SummaryCondition::AVG, SummaryCondition::MIN, SummaryCondition::MAX, null, '', 'x'] as $condition) {
            $this->assertFalse(ChartSummarySwitch::isAdditive($condition), (string) json_encode($condition));
        }
    }

    // The chart's numbers --------------------------------------------------------------------------

    /**
     * X odd_even × series index_text, SUM(integer) of the rows made for the test: s1 = [150, 0, 0,
     * 0, 10], s2 = [50, -40, 90, 0, -25] over a..e (an empty cell of a SUM is 0, as on 積み上げ棒).
     * Both types draw the same shares and hand the same numbers to the tooltip and the CSV.
     */
    public function testTheChartByHand(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even', 'index_text']);
        $stacked = $this->render($view, 'sbar', ['cs' => 'x0:asc']);
        foreach (self::TYPES as $type) {
            $render = $this->render($view, $type, ['cs' => 'x0:asc']);
            $this->assertSame([['a', 'b', 'c', 'd', 'e'], ['s1', 's2']], $render['labels'], $type);
            $this->assertSeries([75, 0, 0, null, 28.5714285714], $render['values'][0], "$type s1");
            $this->assertSeries([25, -100, 100, null, -71.4285714286], $render['values'][1], "$type s2");
            // the numbers: the stacked bar's own
            $this->assertSame($stacked['values'], $render['raw'], "$type numbers");
            $this->assertSeries([150, 0, 0, 0, 10], $render['raw'][0], "$type s1 numbers");
            $this->assertStringContainsString('var percent = true', $render['body'], $type);
            $this->assertStringContainsString('var fullTexts = ' . json_encode(['total' => exmtrans('dashboard.chart_full_stack.total', '{n}'), 'value' => exmtrans('dashboard.chart_full_stack.value', '{n}')]), $render['body'], "$type tooltip: a sum as it is");
            $this->assertStringContainsString('var stackTotal = false', $render['body'], $type);
            // the Y axis named as the stacked bar's
            $this->assertSame($stacked['axisy'], $render['axisy'], $type);
        }
        // the other types hand no numbers of their own
        $this->assertStringContainsString('var values = null', $stacked['body']);
        $this->assertStringContainsString('var fullTexts = null', $stacked['body']);
    }

    /**
     * A COUNT: the shares of the rows (a: 2 + 1, b: 1 + 1, c: 1 + 1, d: 1 + none, e: 1 + 1), the
     * tooltip in 件.
     */
    public function testACountByHand(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even', 'index_text'], SummaryCondition::COUNT, 'text');
        $render = $this->render($view, 'shbar100', ['cs' => 'x0:asc']);
        $this->assertSeries([66.6666666667, 50, 50, 100, 50], $render['values'][0], 's1');
        $this->assertSeries([33.3333333333, 50, 50, 0, 50], $render['values'][1], 's2');
        $count = exmtrans('dashboard.chart_full_stack.count', '{n}');
        $this->assertStringContainsString('var fullTexts = ' . json_encode(['total' => exmtrans('dashboard.chart_full_stack.total', $count), 'value' => exmtrans('dashboard.chart_full_stack.value', $count)]), $render['body']);
    }

    /**
     * Sorted by the measure, the X items go by their totals as on 積み上げ棒 (a 200, c 90, d 0,
     * e -15, b -40), each keeping its shares.
     */
    public function testSortedByTheMeasure(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even', 'index_text']);
        foreach (self::TYPES as $type) {
            $render = $this->render($view, $type, ['cs' => 'y:desc']);
            $this->assertSame(['a', 'c', 'd', 'e', 'b'], $render['labels'][0], $type);
            $this->assertSeries([75, 0, null, 28.5714285714, 0], $render['values'][0], "$type s1");
            $this->assertSeries([25, 100, null, -71.4285714286, -100], $render['values'][1], "$type s2");
            $this->assertSame($this->render($view, 'sbar', ['cs' => 'y:desc'])['labels'], $render['labels'], "$type as the stacked bar");
        }
    }

    /**
     * The table's own rows (the test data, no filter) against its raw SQL: per text × odd_even,
     * SUM(integer) and COUNT(integer) of the rows not deleted, each cell over its X item's size.
     */
    public function testTheTablesRowsAgainstRawSql(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $table = getDBTableName(CustomTable::getEloquent('parent_table'));
        $value = function (string $column) {
            return "NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(value, '$.$column')), 'null'), '')";
        };
        $measures = [
            SummaryCondition::SUM => ['integer', "SUM(CAST({$value('integer')} AS DECIMAL(30, 10)))"],
            SummaryCondition::COUNT => ['integer', "COUNT({$value('integer')})"],
        ];
        foreach ($measures as $condition => [$column, $aggregate]) {
            $cells = [];
            foreach (DB::select("SELECT {$value('text')} AS x, {$value('odd_even')} AS s, $aggregate AS y FROM $table WHERE deleted_at IS NULL GROUP BY x, s") as $row) {
                $cells[$row->s][$row->x] = (float) $row->y;
            }
            $view = $this->aggregateView([
                'target_table_name' => 'parent_table',
                'column_settings' => [['column_name' => 'text'], ['column_name' => 'odd_even']],
                'summary_settings' => [['column_name' => $column, 'summary_condition' => $condition]],
            ]);
            foreach (self::TYPES as $type) {
                $render = $this->render($view, $type, ['cs' => 'x0:asc']);
                [$categories, $series] = $render['labels'];
                $this->assertCount(2, $series, "$condition odd, even");
                $this->assertGreaterThan(2, count($categories));
                foreach ($categories as $xi => $x) {
                    $size = 0.0;
                    foreach ($series as $name) {
                        $size += abs($cells[$name][$x] ?? 0.0);
                    }
                    foreach ($series as $si => $name) {
                        $cell = $cells[$name][$x] ?? 0.0;
                        $this->assertEqualsWithDelta($cell, (float) $render['raw'][$si][$xi], 1e-6, "$condition $type $x $name number");
                        $want = $size == 0 ? null : $cell / $size * 100;
                        $this->assertSeries([$want], [$render['values'][$si][$xi]], "$condition $type $x $name share");
                    }
                }
            }
        }
    }

    // A measure that does not add up ----------------------------------------------------------------

    /**
     * A view's 平均 / 最小 / 最大 draws the message; its 集計方法 then offers nothing, and the type
     * picker of its other charts offers no 100% stacked type.
     */
    public function testAMeasureThatDoesNotAddUp(): void
    {
        $this->createRows();
        foreach ([SummaryCondition::AVG, SummaryCondition::MIN, SummaryCondition::MAX] as $condition) {
            $view = $this->rowsView(['odd_even', 'index_text'], $condition);
            foreach (self::TYPES as $type) {
                $this->assertSame(exmtrans('dashboard.message.need_additive'), $this->render($view, $type)['body'], "$condition $type");
                $this->assertSame(exmtrans('dashboard.message.need_additive'), $this->render($view, $type, ['ca' => SummaryCondition::SUM])['body'], "$condition $type: no switch");
            }
            $body = $this->render($view, 'mbar')['body'];
            $this->assertStringNotContainsString('data-ct-type="sbar100"', $body, $condition);
            $this->assertStringNotContainsString('data-ct-type="shbar100"', $body, $condition);
            $this->assertStringContainsString('data-ct-type="sbar"', $body, $condition);
            // a switch to one (a `ct` remembered before the view changed) is ignored: the box's own
            // type is drawn, with its picker to switch on
            $switched = $this->render($view, 'mbar', ['ct' => 'sbar100']);
            $this->assertSame($body, $switched['body'], "$condition: as without the switch");
            $this->assertStringContainsString('class="ctp-tile on" data-ct-type="mbar"', $switched['body'], $condition);
        }
    }

    /**
     * A view's 合計: the 集計方法 of a 100% stacked chart offers 合計 and 件数 only; a pick of
     * another (still asked by the request after a type switch) is dropped, the chart draws the sum.
     */
    public function testTheSummaryTypeOfASum(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even', 'index_text']);
        $plain = $this->render($view, 'sbar100', ['cs' => 'x0:asc']);
        $this->assertSame([SummaryCondition::SUM, SummaryCondition::COUNT], $this->picks($plain['body'], 'data-ca-pick'));
        $this->assertSame(5, count($this->picks($this->render($view, 'sbar', ['cs' => 'x0:asc'])['body'], 'data-ca-pick')), 'the stacked bar: every one');

        foreach ([SummaryCondition::AVG, SummaryCondition::MIN, SummaryCondition::MAX] as $condition) {
            $render = $this->render($view, 'sbar100', ['cs' => 'x0:asc', 'ca' => $condition]);
            $this->assertSame($plain['values'], $render['values'], $condition);
            $this->assertStringContainsString('data-ca-applied=""', $render['body'], $condition);
        }
        // 件数 of the rows that have a number: a 2 + 1, b 1 + 1, c none + 1, d none, e 1 + 1
        $count = $this->render($view, 'sbar100', ['cs' => 'x0:asc', 'ca' => SummaryCondition::COUNT]);
        $this->assertStringContainsString('data-ca-applied="' . SummaryCondition::COUNT . '"', $count['body']);
        $this->assertSeries([66.6666666667, 50, 0, null, 50], $count['values'][0], 'COUNT s1');

        // the type picker offers both on the other multi-series charts of the view
        $body = $this->render($view, 'mbar')['body'];
        foreach (self::TYPES as $type) {
            $this->assertStringContainsString('data-ct-type="' . $type . '"', $body, $type);
            $this->assertStringContainsString(e(exmtrans('chart.chart_type_options.' . $type)), $body, $type);
        }
    }

    /**
     * The 表示方法 row of a 100% stacked chart offers 計算なし only, and a way asked for (still in the
     * request after a type switch) is dropped: the shares as without it.
     */
    public function testTheShowValuesAsRow(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even', 'index_text']);
        foreach (self::TYPES as $type) {
            $plain = $this->render($view, $type);
            $this->assertSame([ChartShowAs::NONE], $this->picks($plain['body'], 'data-sv-pick'), $type);
            $this->assertStringContainsString('data-sv-applied=""', $plain['body'], $type);
            foreach ([ChartShowAs::PERCENT_OF_TOTAL, ChartShowAs::RUNNING_TOTAL, ChartShowAs::DIFFERENCE, ChartShowAs::PERCENT_OF_ROW] as $sv) {
                $this->assertSame($plain['body'], $this->render($view, $type, [ChartShowAs::PARAM => $sv])['body'], "$type $sv");
            }
            // the box keeps its title (no 表示方法 is picked)
            $this->assertStringNotContainsString('data-ca-title', $plain['body'], $type);
        }
    }

    /**
     * The box form refuses a 100% stacked chart of a measure that does not add up, at チャートの
     * 種類, and saves the others; it agrees with what the chart draws.
     */
    public function testTheBoxForm(): void
    {
        $login = LoginUser::find(1);
        if ($login === null) {
            $this->markTestSkipped('needs LoginUser 1');
        }
        $this->actingAs($login, 'admin')->withoutMiddleware(VerifyCsrfToken::class);
        $dashboard = $this->dashboard();
        $avg = $this->rowsView(['odd_even', 'index_text'], SummaryCondition::AVG);
        $sum = $this->rowsView(['odd_even', 'index_text']);

        foreach (self::TYPES as $type) {
            $response = $this->post($this->url('dashboardbox'), $this->boxForm($dashboard, $avg, $type));
            $response->assertSessionHasErrors(['options.chart_type' => exmtrans('dashboard.message.need_additive_form')]);
            $this->assertSame(0, DashboardBox::where('dashboard_id', $dashboard->id)->count(), $type);
        }
        foreach ([[$sum, 'sbar100'], [$sum, 'shbar100'], [$avg, 'sbar']] as [$view, $type]) {
            DashboardBox::where('dashboard_id', $dashboard->id)->delete();
            $this->post($this->url('dashboardbox'), $this->boxForm($dashboard, $view, $type))->assertSessionHasNoErrors();
            $box = DashboardBox::where('dashboard_id', $dashboard->id)->first();
            $this->assertNotNull($box, $type);
            $this->assertSame($type, array_get($box->options, 'chart_type'));
            // a multi-series type keeps its series column and legend option as 積み上げ棒
            $this->assertArrayHasKey('chart_series', $box->options, $type);
        }

        foreach ([$avg, $sum] as $view) {
            foreach (['sbar100', 'shbar100', 'sbar', 'bar'] as $type) {
                $box = new DashboardBox();
                $box->options = $this->boxForm(null, $view, $type)['options'];
                request()->merge(['options' => $box->options]);
                $message = (string) (new ChartItem($box))->body() === exmtrans('dashboard.message.need_additive');
                $this->assertSame($message, ChartItem::formFullStackOfNonAdditive($type), "{$view->id} $type");
            }
        }
    }

    // Helpers ------------------------------------------------------------------------------------

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
     * @param array<string, mixed> $options
     */
    private function aggregateView(array $options): CustomView
    {
        System::clearCache();
        return $this->createCustomViewAll($options, ViewKindType::AGGREGATE)[1];
    }

    /**
     * A box of $view drawn as $type with the request values $params: its body, the drawn cells
     * (matrix), the numbers behind a 100% stacked chart's shares (values), [x categories,
     * series names] and the Y label.
     *
     * @param array<string, mixed> $params
     * @return array{body: string, values: mixed, raw: mixed, labels: mixed, axisy: mixed}
     */
    private function render(CustomView $view, string $type, array $params = []): array
    {
        System::clearCache();
        foreach (self::PARAMS as $param) {
            request()->offsetUnset($param);
        }
        request()->merge($params);
        $summary = CustomViewSummary::where('custom_view_id', $view->id)->first();
        $this->assertNotNull($summary);
        $x = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->first();
        $this->assertNotNull($x);
        $body = (string) (new ChartItem($this->box($view, $type, ViewKindType::DEFAULT . '_' . $x->id, ViewKindType::AGGREGATE . '_' . $summary->id)))->body();
        foreach (self::PARAMS as $param) {
            request()->offsetUnset($param);
        }
        return [
            'body' => $body,
            'values' => $this->scriptJson($body, 'matrix'),
            'raw' => $this->scriptJson($body, 'values'),
            'labels' => [$this->scriptJson($body, 'xCategories'), $this->scriptJson($body, 'seriesNames')],
            'axisy' => preg_match('/var axisYName = ("(?:[^"\\\\]|\\\\.)*"|null);/u', $body, $m) ? json_decode($m[1], true) : null,
        ];
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
            'chart_options' => ['1'],
        ];
        return $box;
    }

    /**
     * The values of the menu rows $attribute="…" in $body, in order.
     *
     * @return string[]
     */
    private function picks(string $body, string $attribute): array
    {
        preg_match_all('/' . preg_quote($attribute, '/') . '="([^"]*)"/', $body, $m);
        return $m[1];
    }

    /**
     * The JSON a chart blade assigns to `var $name = …;` (null: no such var, or null).
     *
     * @return mixed
     */
    private function scriptJson(string $body, string $name)
    {
        $at = strpos($body, "var $name = ");
        if ($at === false) {
            return null;
        }
        $at += strlen("var $name = ");
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

    private function dashboard(): Dashboard
    {
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::USER;
        $dashboard->dashboard_name = 'full_stack_form_' . uniqid();
        $dashboard->dashboard_view_name = 'full stack form';
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();
        return $dashboard;
    }

    /**
     * @return array<string, mixed>
     */
    private function boxForm(?Dashboard $dashboard, CustomView $view, string $type): array
    {
        $summary = CustomViewSummary::where('custom_view_id', $view->id)->first();
        $this->assertNotNull($summary);
        $columns = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->get();
        return [
            'dashboard_id' => $dashboard ? $dashboard->id : null,
            'dashboard_box_type' => DashboardBoxType::CHART,
            'row_no' => 1,
            'column_no' => 1,
            'dashboard_box_view_name' => 'full stack chart',
            'options' => [
                'chart_type' => $type,
                'target_table_id' => $view->custom_table_id,
                'target_view_id' => $view->id,
                'chart_axisx' => 'chartitem_label',
                'chart_axisy' => ViewKindType::AGGREGATE . '_' . $summary->id,
                'chart_series' => ViewKindType::DEFAULT . '_' . $columns[1]->id,
            ],
        ];
    }

    private function url(string $path): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/' . $path;
    }
}
