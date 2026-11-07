<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
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
use Exceedone\Exment\Services\Dashboard\ChartThresholds;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * The color thresholds of a heatmap box (色分けのしきい値, Excel's 条件付き書式 → セルの強調表示
 * ルール): a cell in the color of the first rule it meets, top to bottom; one meeting none plain;
 * the legend along the scale. Checked against classes worked out by hand — on numbers made up
 * for the rules, and on the table's own rows by raw SQL — the form's save and validation, and
 * that a box without thresholds draws as before.
 */
class ChartThresholdsTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    private const PARAMS = [ChartShowAs::PARAM, ChartSummarySwitch::PARAM, 'cs', 'ct', 'options', ChartThresholds::OPTION];

    private const RED = '#f8696b';
    private const GREEN = '#63be7b';
    private const YELLOW = '#ffeb84';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        $this->forgetParams();
        parent::tearDown();
    }

    // The rules ----------------------------------------------------------------------------------

    /**
     * 赤 60 未満 / 緑 75 以上: a cell of 60 is not under 60, 75 (or "75.00", a summarized value as
     * the database gives it) is at least 75; an empty cell has no class.
     */
    public function testClassesByHand(): void
    {
        $t = $this->rules([[self::RED, 'lt', 60], [self::GREEN, 'gte', 75]]);
        $cells = [59.99, 60, '60.00', 74.99, 75, '75.00', 75.01, -5, '1000', null, '', 'abc'];
        $want = [0, -1, -1, -1, 1, 1, 1, 0, 1, null, null, null];
        foreach ($cells as $i => $v) {
            $this->assertSame($want[$i], $t->classOf($v), (string) json_encode($v));
        }

        // every condition at its edge: 以下 takes A, より大きい does not; a range takes A, not B
        $edges = [
            ['lt', 10, null, [[9.99, true], [10, false]]],
            ['lte', 10, null, [[10, true], [10.01, false]]],
            ['gte', 10, null, [[10, true], [9.99, false]]],
            ['gt', 10, null, [[10.01, true], [10, false]]],
            ['range', 10, 20, [[10, true], [19.99, true], [20, false], [9.99, false]]],
        ];
        foreach ($edges as [$condition, $a, $b, $cases]) {
            $t = $this->rules([[self::RED, $condition, $a, $b]]);
            foreach ($cases as [$v, $meets]) {
                $this->assertSame($meets ? 0 : -1, $t->classOf((string) $v), "$condition $v");
            }
        }
    }

    /**
     * Excel's priority: a cell meeting two rules takes the upper one's color — the same rules in
     * the other order color it the other way.
     */
    public function testTheUpperRuleWins(): void
    {
        $rows = [[self::GREEN, 'gte', 60], [self::YELLOW, 'range', 70, 80], [self::RED, 'gte', 75]];
        $t = $this->rules($rows);
        $this->assertSame([0, 0, 0, -1], [$t->classOf(65), $t->classOf(72), $t->classOf(90), $t->classOf(59)]);

        $t = $this->rules(array_reverse($rows));
        // red ≥ 75, yellow 70 ≤ v < 80, green ≥ 60
        $this->assertSame([2, 1, 0, 0, 1, -1], [$t->classOf(65), $t->classOf(72), $t->classOf(75), $t->classOf(90), $t->classOf(74.9), $t->classOf(59)]);
    }

    /**
     * The legend: each rule by its condition, the values no rule takes (none when the rules take
     * them all), along the scale — the class of a cell is its piece's place in it.
     */
    public function testTheLegend(): void
    {
        $cases = [
            [[[self::RED, 'lt', 60], [self::GREEN, 'gte', 75]], ['60 未満', '60〜75', '75 以上'], [0, -1, 1]],
            [[[self::GREEN, 'gte', 75], [self::RED, 'lt', 60]], ['60 未満', '60〜75', '75 以上'], [1, -1, 0]],
            [[[self::RED, 'lt', 10], [self::YELLOW, 'range', 20, 30], [self::GREEN, 'gt', 50]], ['10 未満', '10〜20、30〜50', '20 以上 30 未満', '50 より大きい'], [0, -1, 1, 2]],
            [[[self::RED, 'lte', 10], [self::GREEN, 'gte', 10]], ['10 以下', '10 以上'], [0, 1]],
            [[[self::RED, 'lt', 10], [self::GREEN, 'gt', 10]], ['10 未満', '10', '10 より大きい'], [0, -1, 1]],
            [[[self::YELLOW, 'range', 0, 100]], ['0 未満、100 以上', '0 以上 100 未満'], [-1, 0]],
            [[[self::RED, 'lt', 1000000], [self::GREEN, 'gte', 1500000.5]], ['1,000,000 未満', '1,000,000〜1,500,000.5', '1,500,000.5 以上'], [0, -1, 1]],
            [[[self::RED, 'lt', -2.5], [self::GREEN, 'gte', 0]], ['-2.5 未満', '-2.5〜0', '0 以上'], [0, -1, 1]],
        ];
        foreach ($cases as $i => [$rows, $labels, $rules]) {
            $pieces = $this->rules($rows)->payload([])['pieces'];
            $this->assertSame($labels, array_column($pieces, 'label'), "case $i");
            $this->assertSame($rules, array_column($pieces, 'rule'), "case $i");
            $this->assertSame(array_keys($pieces), array_column($pieces, 'value'), "case $i: numbered in order");
            foreach ($pieces as $piece) {
                $rule = $piece['rule'] === ChartThresholds::NONE ? null : ($rows[$piece['rule']] ?? null);
                $this->assertSame($rule === null ? ChartThresholds::NONE_COLOR : $rule[0], $piece['color'], "case $i");
            }
        }

        // each cell's class: its piece in the legend
        $payload = $this->rules([[self::GREEN, 'gte', 75], [self::RED, 'lt', 60]])->payload([[59, 60, null], ['75.5', 74]]);
        $this->assertSame([[0, 1, null], [2, 1]], $payload['classes']);
    }

    /**
     * The rows as stored: a removed row, or one that is no rule, left out; the color in lower
     * case, the numbers as numbers; MAX rules at most.
     */
    public function testNormalize(): void
    {
        $rows = [
            ['color' => '#F8696B', 'condition' => 'lt', 'value' => ' 60 ', 'value2' => '', '_remove_' => '0'],
            ['color' => '#000000', 'condition' => 'gt', 'value' => '1', '_remove_' => '1'],
            ['color' => 'red', 'condition' => 'gt', 'value' => '1'],
            ['color' => '#fff', 'condition' => 'gt', 'value' => '1'],
            ['color' => '#ffffff" onmouseover="alert(1)', 'condition' => 'gt', 'value' => '1'],
            ['color' => '#ffffff', 'condition' => 'eq', 'value' => '1'],
            ['color' => '#ffffff', 'condition' => 'gt', 'value' => 'abc'],
            ['color' => '#ffffff', 'condition' => 'gt', 'value' => '1e400'],
            ['color' => '#ffffff', 'condition' => 'range', 'value' => '5', 'value2' => '5'],
            ['color' => '#ffffff', 'condition' => 'range', 'value' => '5'],
            ['color' => self::YELLOW, 'condition' => 'range', 'value' => '59.5', 'value2' => '75'],
            'not a row',
        ];
        $this->assertSame([
            ['color' => self::RED, 'condition' => 'lt', 'value' => 60],
            ['color' => self::YELLOW, 'condition' => 'range', 'value' => 59.5, 'value2' => 75],
        ], ChartThresholds::normalize($rows));
        $this->assertSame([], ChartThresholds::normalize(null));
        $this->assertSame([], ChartThresholds::normalize('[{"color":"#ffffff"}]'));

        $many = array_fill(0, ChartThresholds::MAX + 3, ['color' => self::RED, 'condition' => 'lt', 'value' => 1]);
        $this->assertCount(ChartThresholds::MAX, ChartThresholds::normalize($many));
    }

    // The chart's cells ----------------------------------------------------------------------------

    /**
     * parent_table's own rows against raw SQL: AVG(integer) per text × odd_even, rounded as the
     * view rounds it (2 decimals: the column has none), each cell's class worked out by hand
     * from the SQL value — three rules, the second one overlapping the first.
     */
    public function testTheCellsAgainstRawSql(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $table = getDBTableName(CustomTable::getEloquent('parent_table'));
        $value = function (string $column) {
            return "NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(value, '$.$column')), 'null'), '')";
        };
        $cells = [];
        foreach (DB::select("SELECT {$value('text')} AS x, {$value('odd_even')} AS s, ROUND(AVG(CAST({$value('integer')} AS DECIMAL(30, 10))), 2) AS y FROM $table WHERE deleted_at IS NULL GROUP BY x, s") as $row) {
            if ($row->y !== null) {
                $cells[$row->s][$row->x] = (float) $row->y;
            }
        }
        $all = array_merge(...array_values(array_map('array_values', $cells)));
        sort($all);
        $this->assertGreaterThan(5, count($all));
        // limits between the data's values, so each rule takes some cells
        $q = function (float $p) use ($all) {
            return $all[(int) floor((count($all) - 1) * $p)] + 0.001;
        };
        [$low, $mid, $high] = [round($q(0.25), 3), round($q(0.5), 3), round($q(0.75), 3)];
        $rows = [[self::RED, 'lt', $low], [self::YELLOW, 'range', round($q(0.15), 3), $mid], [self::GREEN, 'gte', $high]];
        $byHand = function (float $v) use ($low, $mid, $high, $q) {
            if ($v < $low) {
                return 0;
            }
            if ($v >= round($q(0.15), 3) && $v < $mid) {
                return 1;
            }
            return $v >= $high ? 2 : -1;
        };

        $view = $this->aggregateView(['text', 'odd_even'], SummaryCondition::AVG);
        $render = $this->render($view, 'heatmap', $rows);
        [$categories, $series] = $render['labels'];
        $this->assertNotNull($render['thresholds']);
        $pieces = $render['thresholds']['pieces'];
        $seen = [0 => 0, 1 => 0, 2 => 0, -1 => 0];
        foreach ($series as $si => $name) {
            foreach ($categories as $xi => $x) {
                $sql = $cells[$name][$x] ?? null;
                $cell = $render['matrix'][$si][$xi];
                $class = $render['thresholds']['classes'][$si][$xi];
                if ($sql === null) {
                    $this->assertNull($cell, "$x $name: empty");
                    $this->assertNull($class, "$x $name: no class");
                    continue;
                }
                $this->assertEqualsWithDelta($sql, (float) $cell, 1e-9, "$x $name value");
                $rule = $byHand($sql);
                $this->assertSame($rule, $pieces[$class]['rule'], "$x $name ($sql) rule");
                $this->assertSame($rule === -1 ? ChartThresholds::NONE_COLOR : $rows[$rule][0], $pieces[$class]['color'], "$x $name color");
                $seen[$rule]++;
            }
        }
        $this->assertGreaterThan(0, $seen[0], 'red cells');
        $this->assertGreaterThan(0, $seen[2], 'green cells');
        $this->assertGreaterThan(0, $seen[-1], 'plain cells');
        // the box grows to its rows; the cells of the overlap are red (the upper rule)
        $this->assertStringContainsString("height:" . max(300, 26 * count($series) + 110) . "px", $render["body"]);
    }

    /**
     * Sorted, the classes follow the cells; the measure switched to another condition (集計方法)
     * or a type with a 表示方法 drawing other numbers: no thresholds (the continuous scale); the
     * view's own condition picked again: the thresholds. Any type of the box hands them on (for
     * the type picker's preview of a heatmap).
     */
    public function testWhenTheThresholdsApply(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $view = $this->aggregateView(['text', 'odd_even'], SummaryCondition::AVG);
        $rows = [[self::RED, 'lt', 3], [self::GREEN, 'gte', 6]];

        $sorted = $this->render($view, 'heatmap', $rows, ['cs' => 'y:desc']);
        $t = $this->rules($rows);
        foreach ($sorted['matrix'] as $si => $row) {
            foreach ($row as $xi => $v) {
                $class = $sorted['thresholds']['classes'][$si][$xi];
                $this->assertSame($v === null ? null : $t->classOf($v), $class === null ? null : $sorted['thresholds']['pieces'][$class]['rule'], "sorted $si $xi");
            }
        }

        $count = $this->render($view, 'heatmap', $rows, [ChartSummarySwitch::PARAM => SummaryCondition::COUNT]);
        $this->assertNull($count['thresholds'], '件数: the continuous scale');
        $this->assertStringContainsString('height:300px', $count['body']);
        $own = $this->render($view, 'heatmap', $rows, [ChartSummarySwitch::PARAM => SummaryCondition::AVG]);
        $this->assertNotNull($own['thresholds'], "平均 picked: the view's own");

        $bar = $this->render($view, 'mbar', $rows);
        $this->assertNotNull($bar['thresholds'], 'mbar: handed on for the preview');
        $this->assertStringContainsString('height:300px', $bar['body']);
        $shown = $this->render($view, 'mbar', $rows, [ChartShowAs::PARAM => ChartShowAs::DIFFERENCE]);
        $this->assertNull($shown['thresholds'], '表示方法: other numbers');

        // the heatmap the toolbar switches to applies them
        $switched = $this->render($view, 'mbar', $rows, ['ct' => 'heatmap']);
        $this->assertNotNull($switched['thresholds']);
        $this->assertStringContainsString("var type = 'heatmap'", $switched['body']);
    }

    /**
     * No thresholds, or none that is a rule (a color written into the page must be #rrggbb):
     * the heatmap as before — its continuous scale, 300 px.
     */
    public function testABoxWithoutThresholds(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $view = $this->aggregateView(['text', 'odd_even'], SummaryCondition::AVG);
        foreach ([[], [['#ffffff" onmouseover="alert(1)', 'lt', 3]], [['red', 'lt', 3]]] as $i => $rows) {
            $render = $this->render($view, 'heatmap', $rows);
            $this->assertNull($render['thresholds'], "case $i");
            $this->assertStringContainsString('var thresholds = null;', $render['body']);
            $this->assertStringContainsString('height:300px', $render['body']);
            $this->assertStringNotContainsString('onmouseover', $render['body']);
        }
    }

    /**
     * A heatmap, with thresholds or without, is as tall as its rows need (26px a row, the axes
     * besides), 300px at least; another type keeps 300px.
     */
    public function testTheHeatmapGrowsToItsRows(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $many = $this->aggregateView(['odd_even', 'text'], SummaryCondition::SUM);
        $few = $this->aggregateView(['text', 'odd_even'], SummaryCondition::SUM);
        foreach ([[], [[self::RED, 'lt', 3]]] as $rows) {
            $render = $this->render($many, 'heatmap', $rows);
            $count = count($render['labels'][1]);
            $this->assertGreaterThan(8, $count, 'rows: the texts');
            $this->assertStringContainsString('height:' . (26 * $count + 110) . 'px', $render['body'], count($rows) . ' rules');
            $render = $this->render($few, 'heatmap', $rows);
            $this->assertCount(2, $render['labels'][1], 'rows: odd, even');
            $this->assertStringContainsString('height:300px', $render['body'], count($rows) . ' rules, 2 rows');
        }
        $this->assertStringContainsString('height:300px', $this->render($many, 'mbar', [])['body']);
        $this->assertStringContainsString('height:300px', $this->render($many, 'sbar', [], ['ct' => 'treemap'])['body']);
    }

    // The box form -----------------------------------------------------------------------------------

    /**
     * Saved in the table's order, a removed row left out, the numbers as numbers; the other
     * options and the painted colors kept; the edit form shows them; a save without rows, or of
     * another type, stores none.
     */
    public function testTheBoxFormSaves(): void
    {
        $this->login();
        $dashboard = $this->dashboard();
        $view = $this->aggregateView(['text', 'odd_even'], SummaryCondition::AVG);
        $rows = [
            'new_1' => ['color' => '#F8696B', 'condition' => 'lt', 'value' => '60', 'value2' => '', '_remove_' => '0'],
            'new_2' => ['color' => 'not a color', 'condition' => 'gt', 'value' => 'x', '_remove_' => '1'],
            'new_3' => ['color' => self::YELLOW, 'condition' => 'range', 'value' => '60', 'value2' => '75.5', '_remove_' => '0'],
            'new_4' => ['color' => self::GREEN, 'condition' => 'gte', 'value' => '75.5', '_remove_' => '0'],
        ];
        $this->post($this->url('dashboardbox'), $this->boxForm($dashboard, $view, 'heatmap', $rows))->assertSessionHasNoErrors();
        $box = DashboardBox::where('dashboard_id', $dashboard->id)->first();
        $this->assertNotNull($box);
        $stored = [
            ['color' => self::RED, 'condition' => 'lt', 'value' => 60],
            ['color' => self::YELLOW, 'condition' => 'range', 'value' => 60, 'value2' => 75.5],
            ['color' => self::GREEN, 'condition' => 'gte', 'value' => 75.5],
        ];
        $this->assertSame($stored, $box->options[ChartThresholds::OPTION]);
        $this->assertSame('heatmap', $box->options['chart_type']);
        $this->assertSame((string) $view->id, (string) $box->options['target_view_id']);

        // the edit form: the rows in their cells
        $html = $this->get($this->url("dashboardbox/{$box->id}/edit"))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/name="chart_thresholds\[[^\]]+\]\[value2\]"[^>]*value="75.5"|value="75.5"[^>]*name="chart_thresholds\[[^\]]+\]\[value2\]"/', $html);
        $this->assertStringContainsString('ExmentChartThresholdForm.init(', $html);
        // the color button and its panel get their words from the language files
        foreach (['color_select', 'color_other', 'color_custom', 'color_placeholder', 'custom_color'] as $key) {
            $this->assertStringContainsString('"' . exmtrans('dashboard.chart_threshold.' . $key) . '"', $html);
        }

        // painted colors and the rules kept over an update; the rows reordered
        $options = $box->options;
        $options['chart_colors'] = ['series' => ['a' => '#123456']];
        DB::table('dashboard_boxes')->where('id', $box->id)->update(['options' => json_encode($options)]);
        $reordered = ['k2' => $rows['new_4'], 'k1' => $rows['new_1']];
        $this->put($this->url("dashboardbox/{$box->id}"), $this->boxForm($dashboard, $view, 'heatmap', $reordered))->assertSessionHasNoErrors();
        $box = DashboardBox::where('id', $box->id)->first();
        $this->assertNotNull($box);
        $this->assertSame([$stored[2], $stored[0]], $box->options[ChartThresholds::OPTION]);
        $this->assertSame(['series' => ['a' => '#123456']], $box->options['chart_colors']);

        // no rows: none; another type: none (its form hides the table, rows left in it ignored)
        $this->put($this->url("dashboardbox/{$box->id}"), $this->boxForm($dashboard, $view, 'heatmap', []))->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey(ChartThresholds::OPTION, (array) DashboardBox::where('id', $box->id)->first()?->options);
        $bad = ['k' => ['color' => 'red', 'condition' => 'range', 'value' => '9', 'value2' => '1', '_remove_' => '0']];
        $this->put($this->url("dashboardbox/{$box->id}"), $this->boxForm($dashboard, $view, 'mbar', $rows + $bad))->assertSessionHasNoErrors();
        $options = (array) DashboardBox::where('id', $box->id)->first()?->options;
        $this->assertSame('mbar', $options['chart_type']);
        $this->assertArrayNotHasKey(ChartThresholds::OPTION, $options);
    }

    /**
     * Each cell's error on its own cell, as the form's other errors; a row removed on the form is
     * not checked; at most MAX rules. Nothing is saved.
     */
    public function testTheBoxFormValidates(): void
    {
        $this->login();
        $dashboard = $this->dashboard();
        $view = $this->aggregateView(['text', 'odd_even'], SummaryCondition::AVG);
        $rows = [
            'a' => ['color' => '#ffffff" onmouseover="alert(1)', 'condition' => 'range', 'value' => '75', 'value2' => '60', '_remove_' => '0'],
            'b' => ['color' => '', 'condition' => 'lt', 'value' => 'abc', '_remove_' => '0'],
            'c' => ['color' => self::RED, 'condition' => 'range', 'value' => '1', 'value2' => '', '_remove_' => '0'],
            'd' => ['color' => self::RED, 'condition' => 'between', 'value' => '', '_remove_' => '0'],
            'e' => ['color' => 'x', 'condition' => 'x', 'value' => 'x', 'value2' => 'x', '_remove_' => '1'],
        ];
        $response = $this->post($this->url('dashboardbox'), $this->boxForm($dashboard, $view, 'heatmap', $rows));
        $response->assertSessionHasErrors([
            'chart_thresholds.a.color',
            'chart_thresholds.a.value2',
            'chart_thresholds.b.color',
            'chart_thresholds.b.value',
            'chart_thresholds.c.value2',
            'chart_thresholds.d.condition',
            'chart_thresholds.d.value',
        ]);
        $errors = session('errors');
        $this->assertNotNull($errors);
        $bag = $errors->getBag('default');
        $this->assertStringContainsString(exmtrans('dashboard.chart_threshold.value2'), (string) $bag->first('chart_thresholds.a.value2'));
        $this->assertStringContainsString('より大きい数値', (string) $bag->first('chart_thresholds.a.value2'));
        foreach (['a.value', 'a.condition', 'c.value', 'e.color', 'e.value', 'e.value2'] as $key) {
            $this->assertFalse($bag->has("chart_thresholds.$key"), $key);
        }
        $this->assertSame(0, DashboardBox::where('dashboard_id', $dashboard->id)->count());

        // MAX rules: the one after is refused
        // a removed row before them counts for nothing
        $many = ['r0' => ['color' => self::RED, 'condition' => 'lt', 'value' => '0', '_remove_' => '1']];
        for ($i = 1; $i <= ChartThresholds::MAX + 1; $i++) {
            $many["r$i"] = ['color' => self::RED, 'condition' => 'lt', 'value' => (string) $i, '_remove_' => '0'];
        }
        $response = $this->post($this->url('dashboardbox'), $this->boxForm($dashboard, $view, 'heatmap', $many));
        $response->assertSessionHasErrors(['chart_thresholds.r' . (ChartThresholds::MAX + 1) . '.color' => exmtrans('dashboard.chart_threshold.message.max_rules', ChartThresholds::MAX)]);
        $this->assertFalse(session('errors')->getBag('default')->has('chart_thresholds.r' . ChartThresholds::MAX . '.color'));
        unset($many['r' . (ChartThresholds::MAX + 1)]);
        $this->post($this->url('dashboardbox'), $this->boxForm($dashboard, $view, 'heatmap', $many))->assertSessionHasNoErrors();
        $this->assertCount(ChartThresholds::MAX, (array) array_get(DashboardBox::where('dashboard_id', $dashboard->id)->first()?->options, ChartThresholds::OPTION));
    }

    /**
     * A dashboard template carries the thresholds: exported with the box's options, imported
     * with them.
     */
    public function testTheTemplateKeepsThem(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $view = $this->aggregateView(['text', 'odd_even'], SummaryCondition::AVG);
        $rules = ChartThresholds::normalize($this->rows([[self::RED, 'lt', 3], [self::GREEN, 'range', 5, 7.5]]));
        $dashboard = $this->dashboard();
        $box = $this->box($view, 'heatmap', $rules);
        $box->dashboard_id = $dashboard->id;
        $box->row_no = 1;
        $box->column_no = 1;
        $box->dashboard_box_view_name = 'template';
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->save();

        $exported = $box->getTemplateExportItems();
        $this->assertSame($rules, array_get($exported, 'options.' . ChartThresholds::OPTION));
        $other = $this->dashboard();
        DashboardBox::importTemplate($exported, false, ['parent' => $other]);
        $imported = DashboardBox::where('dashboard_id', $other->id)->first();
        $this->assertNotNull($imported);
        $this->assertSame($rules, $imported->options[ChartThresholds::OPTION]);
        $this->assertSame((string) $view->id, (string) $imported->options['target_view_id']);
    }

    // Helpers ------------------------------------------------------------------------------------

    /**
     * @param array<int, array<int, mixed>> $rows  [color, condition, A, B]
     */
    private function rules(array $rows): ChartThresholds
    {
        $t = ChartThresholds::fromOption($this->rows($rows));
        $this->assertCount(count($rows), $t->rules(), 'every row a rule');
        return $t;
    }

    /**
     * @param array<int, array<int, mixed>> $rows  [color, condition, A, B]
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $rows): array
    {
        return array_map(function ($row) {
            return ['color' => $row[0], 'condition' => $row[1], 'value' => $row[2], 'value2' => $row[3] ?? null];
        }, $rows);
    }

    /**
     * @param string[] $groups
     */
    private function aggregateView(array $groups, string $condition): CustomView
    {
        System::clearCache();
        return $this->createCustomViewAll([
            'target_table_name' => 'parent_table',
            'column_settings' => array_map(function ($column) {
                return ['column_name' => $column];
            }, $groups),
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => $condition]],
        ], ViewKindType::AGGREGATE)[1];
    }

    /**
     * A box of $view drawn as $type with the thresholds $rows ([color, condition, A, B], stored
     * as they are) and the request values $params.
     *
     * @param array<int, array<int, mixed>> $rows
     * @param array<string, mixed> $params
     * @return array{body: string, matrix: mixed, labels: array{0: mixed, 1: mixed}, thresholds: mixed}
     */
    private function render(CustomView $view, string $type, array $rows, array $params = []): array
    {
        System::clearCache();
        $this->forgetParams();
        request()->merge($params);
        $body = (string) (new ChartItem($this->box($view, $type, $this->rows($rows))))->body();
        $this->forgetParams();
        $thresholds = preg_match('/var thresholds = (.*?);\s*$/m', $body, $m) === 1 ? $m[1] : '';
        $this->assertNotSame('', $thresholds, 'var thresholds');
        return [
            'body' => $body,
            'matrix' => $this->scriptJson($body, 'matrix'),
            'labels' => [$this->scriptJson($body, 'xCategories'), $this->scriptJson($body, 'seriesNames')],
            'thresholds' => json_decode($thresholds, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $thresholds
     */
    private function box(CustomView $view, string $type, array $thresholds): DashboardBox
    {
        $summary = CustomViewSummary::where('custom_view_id', $view->id)->first();
        $this->assertNotNull($summary);
        $columns = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->get();
        $box = new DashboardBox();
        $box->options = [
            'target_table_id' => $view->custom_table_id,
            'target_view_id' => $view->id,
            'chart_type' => $type,
            'chart_axisx' => 'chartitem_label',
            'chart_axisy' => ViewKindType::AGGREGATE . '_' . $summary->id,
            'chart_series' => ViewKindType::DEFAULT . '_' . $columns[1]->id,
            'chart_options' => [],
        ] + (count($thresholds) ? [ChartThresholds::OPTION => $thresholds] : []);
        return $box;
    }

    private function forgetParams(): void
    {
        foreach (self::PARAMS as $param) {
            request()->offsetUnset($param);
        }
    }

    /**
     * The JSON array a chart blade assigns to `var $name = …;`.
     *
     * @return mixed
     */
    private function scriptJson(string $body, string $name)
    {
        $json = preg_match('/var ' . preg_quote($name, '/') . ' = (\[.*?\]);\s*$/m', $body, $m) === 1 ? $m[1] : '';
        $this->assertNotSame('', $json, "var $name");
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function login(): void
    {
        $login = LoginUser::find(1);
        if ($login === null) {
            $this->markTestSkipped('needs LoginUser 1');
        }
        $this->actingAs($login, 'admin')->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function dashboard(): Dashboard
    {
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::USER;
        $dashboard->dashboard_name = 'threshold_' . uniqid();
        $dashboard->dashboard_view_name = 'thresholds';
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();
        return $dashboard;
    }

    /**
     * @param array<string, array<string, string>> $rows  the posted 色分けのしきい値 table
     * @return array<string, mixed>
     */
    private function boxForm(Dashboard $dashboard, CustomView $view, string $type, array $rows): array
    {
        $summary = CustomViewSummary::where('custom_view_id', $view->id)->first();
        $this->assertNotNull($summary);
        $columns = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->get();
        return [
            'dashboard_id' => $dashboard->id,
            'dashboard_box_type' => DashboardBoxType::CHART,
            'row_no' => 1,
            'column_no' => 1,
            'dashboard_box_view_name' => 'threshold chart',
            'options' => [
                'chart_type' => $type,
                'target_table_id' => $view->custom_table_id,
                'target_view_id' => $view->id,
                'chart_axisx' => 'chartitem_label',
                'chart_axisy' => ViewKindType::AGGREGATE . '_' . $summary->id,
                'chart_series' => ViewKindType::DEFAULT . '_' . $columns[1]->id,
            ],
        ] + (count($rows) ? [ChartThresholds::OPTION => $rows] : []);
    }

    private function url(string $path): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/' . $path;
    }
}
