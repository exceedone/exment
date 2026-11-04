<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\ChartSummarySwitch;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * The 集計 switch of a chart box (`ca`, ChartSummarySwitch): the measure of an aggregate view
 * drawn by another summary condition for one render, through the view's own query.
 *
 * The contract: a chart of a view set to AVG, switched to X, draws exactly what a chart of the
 * same view set to X draws — the same points, the same SQL — for every condition and for a
 * column of the table, of its 1:n children and of its n:n children. The view is never changed.
 */
class ChartSummarySwitchTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    private const CONDITIONS = [SummaryCondition::SUM, SummaryCondition::AVG, SummaryCondition::COUNT, SummaryCondition::MIN, SummaryCondition::MAX];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        request()->offsetUnset(ChartSummarySwitch::PARAM);
        parent::tearDown();
    }

    // Same numbers as a view set that way ------------------------------------------------------

    /**
     * A column of the view's own table; one group column (bar) and two (mbar).
     */
    public function testSwitchedChartEqualsAViewSetThatWayOnTheTablesOwnColumn(): void
    {
        $this->assertSwitchEqualsPreset([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ], 'bar', 'parent_table.integer');
        $this->assertSwitchEqualsPreset([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'multiples_of_3']],
            'summary_settings' => [['column_name' => 'decimal', 'summary_condition' => SummaryCondition::AVG]],
        ], 'mbar', 'parent_table.decimal by 2 columns');
    }

    /**
     * 1:n children (parent_table → child_table), summarized through the sub query per parent.
     */
    public function testSwitchedChartEqualsAViewSetThatWayOnOneToManyChildren(): void
    {
        $this->createChildrenOfUnequalCount('parent_table', 'child_table');
        foreach (['bar' => [['column_name' => 'odd_even']], 'mbar' => [['column_name' => 'odd_even'], ['column_name' => 'multiples_of_3']]] as $type => $groups) {
            $this->assertSwitchEqualsPreset([
                'target_table_name' => 'parent_table',
                'column_settings' => $groups,
                'summary_settings' => [['reference_table' => 'child_table', 'is_child' => true, 'column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
            ], $type, "child_table.integer ($type)");
        }
    }

    /**
     * n:n children (parent_table_n_n ↔ child_table_n_n), grouped by a system column too.
     */
    public function testSwitchedChartEqualsAViewSetThatWayOnManyToManyChildren(): void
    {
        foreach (['bar' => [['condition_type' => ConditionType::SYSTEM, 'column_name' => 'updated_user']], 'mbar' => [['column_name' => 'odd_even'], ['condition_type' => ConditionType::SYSTEM, 'column_name' => 'updated_user']]] as $type => $groups) {
            $this->assertSwitchEqualsPreset([
                'target_table_name' => 'parent_table_n_n',
                'column_settings' => $groups,
                'summary_settings' => [['reference_table' => 'child_table_n_n', 'is_child' => true, 'column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
            ], $type, "child_table_n_n.integer ($type)");
        }
    }

    /**
     * Picking the view's own condition, or nothing, draws the view as before: no switch, the
     * same output as a box without the parameter.
     */
    public function testTheViewsOwnConditionIsNoSwitch(): void
    {
        $view = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ]);
        $plain = $this->render($view, 'bar', null);
        foreach ([SummaryCondition::AVG, '', null] as $ca) {
            $render = $this->render($view, 'bar', $ca);
            $this->assertSame($plain['body'], $render['body'], var_export($ca, true));
        }
        $this->assertStringContainsString('data-ca-applied=""', $plain['body'], 'the menu, not switched');
        $this->assertStringNotContainsString('data-ca-title', $plain['body']);
        $this->assertStringNotContainsString('ca-active', $plain['body']);
        $this->assertStringNotContainsString('ca-on', $plain['body']);
        $this->assertStringNotContainsString('ca-dot', $plain['body']);
    }

    /**
     * The switch is a row of the box menu (⋯) opening a submenu, as Excel's 値の集計方法 ▸ —
     * no button of its own on the toolbar: 集計方法 with the condition drawn, after the sort
     * and before the display options.
     */
    public function testTheSwitchIsARowOfTheBoxMenu(): void
    {
        $view = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ]);
        $body = $this->render($view, 'bar', null)['body'];
        $menu = $this->between($body, 'class="ct-pop cs-pop"', 'exment-cs-reset');
        $this->assertNotNull($menu, 'the box menu');
        $this->assertStringContainsString('data-ca-open', $menu);
        $this->assertMatchesRegularExpression('/<span>' . preg_quote(e(exmtrans('dashboard.chart_summary.menu')), '/') . '<\/span>\s*<b class="ca-cur">' . preg_quote(e(exmtrans('custom_view.summary_condition_options.avg')), '/') . '<\/b>/u', $menu);
        $this->assertSame(5, substr_count($menu, 'data-ca-pick='), 'its submenu');
        $this->assertLessThan(strpos($menu, 'data-ca-open'), strpos($menu, 'data-cs-field'), 'after the sort');
        $this->assertGreaterThan(strpos($menu, 'data-ca-open'), strpos($menu, 'data-cd-toggle'), 'before the display options');
        $this->assertSame(5, substr_count($body, 'data-ca-pick='), 'nowhere else');
        foreach (['ca-item', 'ca-btn', 'ca-pop'] as $gone) {
            $this->assertStringNotContainsString($gone, $body, "no toolbar button ($gone)");
        }
    }

    // What the request may pick ------------------------------------------------------------------

    /**
     * A number column takes the five conditions of the view form, in its order; any other
     * column only COUNT — its MIN / MAX is a text or a date no value axis can draw, and the box
     * form offers it for COUNT only — so it gets no switch.
     */
    public function testChoicesFollowTheColumnType(): void
    {
        foreach (['integer', 'decimal', 'currency'] as $column_name) {
            $switch = $this->switchOf($this->aggregateView([
                'target_table_name' => 'parent_table',
                'column_settings' => [['column_name' => 'odd_even']],
                'summary_settings' => [['column_name' => $column_name, 'summary_condition' => SummaryCondition::SUM]],
            ]), null);
            $this->assertSame(self::CONDITIONS, $switch->choices(), $column_name);
            $this->assertTrue($switch->available(), $column_name);
        }
        foreach ([['column_name' => 'text'], ['condition_type' => ConditionType::SYSTEM, 'column_name' => 'id']] as $column) {
            $view = $this->aggregateView([
                'target_table_name' => 'parent_table',
                'column_settings' => [['column_name' => 'odd_even']],
                'summary_settings' => [$column + ['summary_condition' => SummaryCondition::COUNT]],
            ]);
            $switch = $this->switchOf($view, SummaryCondition::MIN);
            $this->assertSame([SummaryCondition::COUNT], $switch->choices(), $column['column_name']);
            $this->assertFalse($switch->available(), $column['column_name']);
            $this->assertFalse($switch->switched(), $column['column_name'] . ': MIN ignored');
            $this->assertStringNotContainsString('data-ca-configured', $this->render($view, 'bar', SummaryCondition::MIN)['body'], $column['column_name'] . ': no menu');
        }
    }

    /**
     * Whatever the request carries that is no other condition of the measure is ignored.
     */
    public function testInvalidPicksAreIgnored(): void
    {
        $view = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ]);
        $plain = $this->render($view, 'bar', null)['body'];
        foreach (['0', '6', '9', 'min', '4.0', ' 4', '4 ', '04', 'sum', ['4'], '2;DROP TABLE x'] as $ca) {
            $this->assertFalse($this->switchOf($view, $ca)->switched(), var_export($ca, true));
            $this->assertSame($plain, $this->render($view, 'bar', $ca)['body'], var_export($ca, true));
        }
        $this->assertTrue($this->switchOf($view, 4)->switched(), 'an integer is the same condition as its string');
    }

    /**
     * A measure that is no summary column of the box's view — a group column, another view's
     * summary, a list view — has no switch.
     */
    public function testOnlyASummaryColumnOfTheViewSwitches(): void
    {
        $view = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'integer']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]],
        ]);
        $other = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]],
        ]);
        $group = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->get()[1];
        $this->assertFalse((new ChartSummarySwitch($view, $group, SummaryCondition::MIN))->available(), 'a group column');
        $this->assertFalse((new ChartSummarySwitch($view, $this->summaryOf($other), SummaryCondition::MIN))->available(), 'a summary of another view');
        $this->assertFalse((new ChartSummarySwitch(null, $this->summaryOf($view), SummaryCondition::MIN))->available(), 'no view');
        $this->assertNull((new ChartSummarySwitch(null, null, SummaryCondition::MIN))->condition());

        // a list view's chart
        list(, $list) = $this->createCustomViewAll([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'integer']],
        ]);
        $columns = CustomViewColumn::where('custom_view_id', $list->id)->orderBy('order')->get();
        $box = $this->box($list, 'bar', ViewKindType::DEFAULT . '_' . $columns[0]->id, ViewKindType::DEFAULT . '_' . $columns[1]->id);
        request()->merge([ChartSummarySwitch::PARAM => SummaryCondition::MIN]);
        $this->assertStringNotContainsString('data-ca-configured', (string) (new ChartItem($box))->body());
    }

    /**
     * A multi-series chart of a view grouped by more columns than it reads cannot draw an
     * average (isAverageOfExtraGroups): AVG is not offered there, and a request for it draws
     * the view's condition. A single-series chart of the same view offers it.
     */
    public function testNoAverageWhereTheChartCannotDrawOne(): void
    {
        $view = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'multiples_of_3'], ['column_name' => 'text']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]],
        ]);
        $multi = $this->switchOf($view, SummaryCondition::AVG, 'mbar');
        $this->assertNotContains(SummaryCondition::AVG, $multi->choices());
        $this->assertFalse($multi->switched());
        $render = $this->render($view, 'mbar', SummaryCondition::AVG);
        $this->assertNotSame(exmtrans('dashboard.message.need_average_groups'), $render['body'], 'drawn by the view\'s SUM');
        $this->assertSame($this->render($view, 'mbar', null)['body'], $render['body']);

        $this->assertContains(SummaryCondition::AVG, $this->switchOf($view, null, 'bar')->choices());

        // the view's own AVG keeps its choice and its message, as before
        $average = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'multiples_of_3'], ['column_name' => 'text']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ]);
        $this->assertContains(SummaryCondition::AVG, $this->switchOf($average, null, 'mbar')->choices());
        $this->assertSame(exmtrans('dashboard.message.need_average_groups'), $this->render($average, 'mbar', null)['body']);
        $sum = $this->render($average, 'mbar', SummaryCondition::SUM);
        $this->assertNotSame(exmtrans('dashboard.message.need_average_groups'), $sum['body'], 'switched to SUM, it draws');
        $this->assertNotEmpty($sum['values']);
        $this->assertNotEmpty($render['values']);
    }

    // What the box shows ----------------------------------------------------------------------

    /**
     * Switched: the menu marks the pick and the view's setting; the box title (with a × back),
     * the Y axis, the legend, the tooltips and the export name what is drawn — not the view's
     * own name for the column.
     */
    public function testSwitchedBoxNamesWhatItDraws(): void
    {
        $view = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG, 'view_column_name' => '平均点']],
        ]);
        $column = CustomColumn::getEloquent('integer', 'parent_table')->column_view_name;
        $group = CustomColumn::getEloquent('odd_even', 'parent_table')->column_view_name;
        $value_label = exmtrans('common.format_keyvalue', exmtrans('custom_view.summary_condition_options.min'), $column);

        $plain = $this->render($view, 'bar', null);
        $this->assertSame('平均点', $plain['axisy'], 'the view\'s name as before');

        $render = $this->render($view, 'bar', SummaryCondition::MIN);
        $body = $render['body'];
        $this->assertSame($value_label, $render['axisy']);
        $this->assertStringContainsString('data-ca-applied="' . SummaryCondition::MIN . '"', $body);
        $this->assertStringContainsString('data-ca-configured="' . SummaryCondition::AVG . '"', $body);
        $this->assertStringContainsString('cs-row ca-row ca-on', $body, 'the menu row marked');
        // the box title alone says it (with its ×, dashboard.js syncTitle): no caption, no orange dot
        $this->assertStringNotContainsString('ca-active', $body, 'no caption');
        $this->assertStringNotContainsString('ca-dot', $body, 'no orange dot');
        $this->assertStringContainsString('data-ca-title="' . e(exmtrans('dashboard.chart_summary.title', $group, $value_label)) . '"', $body);
        $this->assertStringContainsString('data-ca-reset-label="' . e(exmtrans('dashboard.chart_summary.reset')) . '"', $body, 'the title\'s ×');
        $this->assertStringContainsString(e(exmtrans('dashboard.chart_summary.heading', $column)), $body);
        $this->assertMatchesRegularExpression('/data-ca-pick="' . SummaryCondition::AVG . '"><i class="fa fa-check"><\/i><span>[^<]+<\/span><em class="ca-badge">' . preg_quote(e(exmtrans('dashboard.chart_summary.view_setting')), '/') . '/u', $body, 'the view\'s setting marked');
        $this->assertMatchesRegularExpression('/class="cs-row on" data-ca-pick="' . SummaryCondition::MIN . '"/', $body, 'the pick checked');
        $this->assertStringContainsString('var valueName = ' . json_encode($value_label), $body, 'tooltip');
        $this->assertStringContainsString('var valueName = null', $plain['body']);
        // the legend names the dataset by the Y axis (it read "undefined" before)
        $this->assertStringContainsString('label: ' . json_encode('平均点') . ',', $plain['body'], 'legend');
        $this->assertStringContainsString('label: ' . json_encode($value_label) . ',', $body, 'legend switched');

        // the ECharts types too
        foreach (['hbar' => 'echart', 'mbar' => 'echart_multi'] as $type => $blade) {
            $two = $type === 'mbar' ? $this->aggregateView([
                'target_table_name' => 'parent_table',
                'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'multiples_of_3']],
                'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
            ]) : $view;
            $this->assertStringContainsString('var valueName = ' . json_encode($value_label), $this->render($two, $type, SummaryCondition::MIN)['body'], $blade);
            $this->assertSame($value_label, $this->render($two, $type, SummaryCondition::MIN)['axisy'], $blade);
        }
    }

    /**
     * A switched render leaves the view as it is: stored, cached and labelled — the next
     * render of the request without a switch draws the view's setting.
     */
    public function testTheViewIsNotChanged(): void
    {
        $view = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ]);
        $summary = $this->summaryOf($view);
        $fresh = $this->render($view, 'bar', null);

        // one request: the switched render first, then the plain one
        System::clearCache();
        $switched = $this->render($view, 'bar', SummaryCondition::MAX, false);
        $this->assertNotSame($fresh['values'], $switched['values']);
        $cached = null;
        foreach (CustomView::getEloquent($view->id)->custom_view_summaries_cache as $s) {
            if ($s->id == $summary->id) {
                $cached = $s;
            }
        }
        $this->assertInstanceOf(CustomViewSummary::class, $cached);
        $this->assertSame(SummaryCondition::AVG, (string) $cached->view_summary_condition, 'cached summary put back');
        $this->assertFalse($cached->isDirty(), 'nothing to save');
        $this->assertSame($fresh, $this->render($view, 'bar', null, false), 'then drawn as before, labelled as before');

        $this->assertSame(SummaryCondition::AVG, (string) DB::table('custom_view_summaries')->where('id', $summary->id)->value('view_summary_condition'), 'stored as it was');
    }

    // Helpers ------------------------------------------------------------------------------------

    /**
     * For every condition X: the view set to another condition and switched to X draws what
     * the view set to X draws — points, labels, the Y label and the summary query (SQL and
     * bindings) alike.
     *
     * @param array<string, mixed> $options  the view (createCustomViewAll), one summary column
     */
    private function assertSwitchEqualsPreset(array $options, string $type, string $message): void
    {
        $view = $this->aggregateView($options);
        $summary = $this->summaryOf($view);
        foreach (self::CONDITIONS as $condition) {
            $base = $condition === SummaryCondition::AVG ? SummaryCondition::SUM : SummaryCondition::AVG;
            $this->setCondition($summary, $base);
            $switched = $this->render($view, $type, $condition);
            $this->assertStringContainsString('data-ca-applied="' . $condition . '"', $switched['body'], "$message: switched to $condition");

            $this->setCondition($summary, $condition);
            $preset = $this->render($view, $type, null);
            $this->assertStringContainsString('data-ca-applied=""', $preset['body'], "$message: set to $condition");

            $this->assertNotEmpty($preset['values'], "$message [$condition]: no data");
            $this->assertSame($preset['values'], $switched['values'], "$message [$condition]: values");
            $this->assertSame($preset['labels'], $switched['labels'], "$message [$condition]: labels");
            $this->assertSame($preset['axisy'], $switched['axisy'], "$message [$condition]: Y label");
            $this->assertNotEmpty($preset['sql'], "$message [$condition]: summary query");
            $this->assertSame($preset['sql'], $switched['sql'], "$message [$condition]: SQL");
        }
    }

    /**
     * A box of $view drawn as $type with `ca` = $ca: its body, the drawn points, their
     * labels, the Y label and the summary queries run (SQL + bindings).
     *
     * @param mixed $ca
     * @return array{body: string, values: mixed, labels: mixed, axisy: mixed, sql: array<int, array{0: string, 1: array<mixed>}>}
     */
    private function render(CustomView $view, string $type, $ca, bool $clear = true): array
    {
        if ($clear) {
            System::clearCache();
        }
        request()->offsetUnset(ChartSummarySwitch::PARAM);
        if ($ca !== null) {
            request()->merge([ChartSummarySwitch::PARAM => $ca]);
        }
        $summary = $this->summaryOf($view);
        $x = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->first();
        $this->assertNotNull($x);
        $box = $this->box($view, $type, ViewKindType::DEFAULT . '_' . $x->id, ViewKindType::AGGREGATE . '_' . $summary->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $body = (string) (new ChartItem($box))->body();
        $sql = [];
        foreach (DB::getQueryLog() as $query) {
            if (stripos($query['query'], 'group by') !== false) {
                $sql[] = [$this->withoutRandomAliases($query['query']), $query['bindings']];
            }
        }
        DB::disableQueryLog();
        request()->offsetUnset(ChartSummarySwitch::PARAM);

        $multi = $type === 'mbar';
        return [
            'body' => $body,
            'values' => $this->scriptJson($body, $multi ? 'matrix' : 'values'),
            'labels' => $multi ? [$this->scriptJson($body, 'xCategories'), $this->scriptJson($body, 'seriesNames')] : $this->scriptJson($body, 'labels'),
            'axisy' => $this->axisY($body),
            'sql' => $sql,
        ];
    }

    /**
     * $sql with the alias of each joined relation (RelationTable::$tableUniqueName, a
     * short_uuid() new on every query) numbered in order of appearance.
     */
    private function withoutRandomAliases(string $sql): string
    {
        preg_match_all('/ as `([0-9a-f]{20})`/i', $sql, $m);
        foreach (array_values(array_unique($m[1])) as $i => $alias) {
            $sql = str_replace("`$alias`", "`relation$i`", $sql);
        }
        return $sql;
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
        // the array's end: brackets counted outside the JSON strings
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
        return null; // no chart drawn (a message)
    }

    /**
     * @param array<string, mixed> $options
     */
    private function aggregateView(array $options): CustomView
    {
        System::clearCache();
        return $this->createCustomViewAll($options, ViewKindType::AGGREGATE)[1];
    }

    private function summaryOf(CustomView $view): CustomViewSummary
    {
        $summary = CustomViewSummary::where('custom_view_id', $view->id)->first();
        $this->assertNotNull($summary);
        return $summary;
    }

    private function setCondition(CustomViewSummary $summary, string $condition): void
    {
        $summary->view_summary_condition = $condition;
        $summary->save();
        System::clearCache();
    }

    /**
     * @param mixed $ca
     */
    private function switchOf(CustomView $view, $ca, string $type = 'bar'): ChartSummarySwitch
    {
        $multi = in_array($type, ['mbar', 'sbar', 'mline', 'sarea', 'heatmap', 'treemap', 'sunburst', 'boxplot'], true);
        return new ChartSummarySwitch($view, $this->summaryOf($view), $ca, !($multi && $view->custom_view_columns->count() > 2));
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
     * Children of unequal count per parent (1, 3, none), with an empty value: an average of
     * the per-parent averages, a count of parents instead of rows, would differ.
     */
    private function createChildrenOfUnequalCount(string $parent_table, string $child_table): void
    {
        $this->be(\Exceedone\Exment\Model\LoginUser::find(\Exceedone\Exment\Tests\TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $parents = [];
        foreach (['odd', 'odd', 'even'] as $odd_even) {
            $parent = CustomTable::getEloquent($parent_table)->getValueModel();
            $parent->setValue(['text' => 'ca_parent', 'odd_even' => $odd_even, 'multiples_of_3' => 0, 'integer' => 1]);
            $parent->saved_notify(false);
            $parent->save();
            $parents[] = $parent;
        }
        foreach ([[$parents[0], [100]], [$parents[1], [0, 0, null]]] as [$parent, $values]) {
            foreach ($values as $integer) {
                $child = CustomTable::getEloquent($child_table)->getValueModel();
                $child->setValue(['text' => 'ca_child', 'integer' => $integer]);
                $child->parent_id = $parent->id;
                $child->parent_type = $parent->custom_table->table_name;
                $child->saved_notify(false);
                $child->save();
            }
        }
    }
}
