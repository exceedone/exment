<?php

namespace Exceedone\Exment\Tests\Unit;

use Carbon\Carbon;
use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Database\Query\Grammars\MariaDBGrammar;
use Exceedone\Exment\Database\Query\Grammars\MySqlGrammar;
use Exceedone\Exment\Database\Query\Grammars\SqlServerGrammar;
use Exceedone\Exment\Enums\ConditionType;
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
use Exceedone\Exment\Services\Dashboard\ChartPeriod;
use Exceedone\Exment\Services\Dashboard\ChartSecondValue;
use Exceedone\Exment\Services\Dashboard\ChartShowAs;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * The 期間の単位 of a chart box (`pu`, ChartPeriod): the date on the X axis grouped by day, week,
 * month, quarter or year without changing the view — grouped by the database (GroupCondition's
 * yq / yw on each Grammar), not again in PHP.
 *
 * Checked against the rows made for the test read back by plain SQL and grouped apart from the
 * code under test (Carbon): the points per unit for one series and several, with the sort, the
 * 表示方法, the 目標線, the 複合 type, 上位N件; the 明細 of a period being exactly its rows; the Excel
 * download; where the menu offers it. The Grammars' expressions are run over every day of 7 years
 * (MySQL / MariaDB) and the SQL Server one's ISO-year rule is checked the same way in PHP.
 */
class ChartPeriodTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    private const MARK = 'pu_case';

    /**
     * [date, odd_even (series), integer]: powers of 2, so that each group's sum tells its rows.
     * Around the ISO week years (2024-12-30 is 2025-W01, 2027-01-01 is 2026-W53) and the quarters.
     */
    private const ROWS = [
        ['2024-12-30', 's1', 1],
        ['2025-01-02', 's2', 2],
        ['2025-03-31', 's1', 4],
        ['2025-04-01', 's1', 8],
        ['2025-06-15', 's2', 16],
        ['2025-12-29', 's1', 32],
        ['2026-01-01', 's2', 64],
        ['2026-01-04', 's1', 128],
        ['2026-01-05', 's2', 256],
        ['2027-01-01', 's1', 512],
        [null, 's1', 1024],
        ['2026-05-20', 's2', null],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    // The Grammars --------------------------------------------------------------------------------

    /**
     * MySQL / MariaDB: the quarter and the ISO week of every day from 2020-12 to 2028-01, run on
     * the database, equal Carbon's (2024-12-30 → 2025-W01, 2027-01-01 → 2026-W53); the weekday
     * (w) keeps its meaning; the Carbon formats agree.
     */
    public function testTheMySqlGrammar(): void
    {
        if (\Exment::isSqlServer()) {
            $this->markTestSkipped('a MySQL / MariaDB database');
        }
        foreach ([new MySqlGrammar(DB::connection()), new MariaDBGrammar(DB::connection())] as $grammar) {
            $this->assertSame("concat(date_format(`d`, '%Y'), '-Q', quarter(`d`))", $grammar->getDateFormatString(new GroupCondition(GroupCondition::YQ), 'd'));
            $this->assertSame("date_format(`d`, '%x-W%v')", $grammar->getDateFormatString(new GroupCondition(GroupCondition::YW), 'd'));
            $this->assertSame("date_format(`d`, '%w')", $grammar->getDateFormatString(new GroupCondition(GroupCondition::W), 'd'), 'the weekday as before');
            $this->assertSame($grammar->getDateFormatString(new GroupCondition(GroupCondition::YQ), 'd'), $grammar->getDateFormatString(new GroupCondition(GroupCondition::YQ), 'd', true), 'the same to group by');
        }
        $grammar = DB::getQueryGrammar();
        $this->assertInstanceOf(MySqlGrammar::class, $grammar);
        $digits = 'SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9';
        $days = "SELECT DATE_ADD('2020-12-01', INTERVAL (a.n + 10 * b.n + 100 * c.n + 1000 * e.n) DAY) AS d FROM ($digits) a, ($digits) b, ($digits) c, ($digits) e";
        $rows = DB::select('SELECT d, ' . $grammar->getDateFormatString(new GroupCondition(GroupCondition::YQ), 'd') . ' AS q, ' . $grammar->getDateFormatString(new GroupCondition(GroupCondition::YW), 'd')
            . " AS w FROM ($days) days WHERE d <= '2028-01-31' ORDER BY d");
        $this->assertCount(2618, $rows);
        foreach ($rows as $row) {
            $date = Carbon::parse($row->d);
            $this->assertSame($date->format('Y') . '-Q' . $date->quarter, $row->q, $row->d);
            $this->assertSame($date->format('o-\WW'), $row->w, $row->d);
        }
        $this->assertSame('2025-W01', $this->dayOf($rows, '2024-12-30')->w);
        $this->assertSame('2026-W53', $this->dayOf($rows, '2027-01-01')->w);
        $this->assertSame('2026-W01', $this->dayOf($rows, '2026-01-04')->w, 'a week from Monday: Sunday the 4th still W01');
        $this->assertSame('2026-W02', $this->dayOf($rows, '2026-01-05')->w);
        $this->assertSame('2026-Q1', $this->dayOf($rows, '2026-03-31')->q);
        $this->assertSame('2026-Q2', $this->dayOf($rows, '2026-04-01')->q);

        // a datetime and a NULL
        $row = DB::selectOne('SELECT ' . $grammar->getDateFormatString(new GroupCondition(GroupCondition::YQ), 'd') . ' AS q, ' . $grammar->getDateFormatString(new GroupCondition(GroupCondition::YW), 'd')
            . " AS w FROM (SELECT CAST('2026-12-31 23:59:59' AS DATETIME) AS d) t");
        $this->assertSame(['2026-Q4', '2026-W53'], [$row->q, $row->w]);
        $row = DB::selectOne('SELECT ' . $grammar->getDateFormatString(new GroupCondition(GroupCondition::YQ), 'd') . ' AS q, ' . $grammar->getDateFormatString(new GroupCondition(GroupCondition::YW), 'd') . ' AS w FROM (SELECT NULL AS d) t');
        $this->assertSame([null, null], [$row->q, $row->w]);

        $carbon = Carbon::parse('2027-01-01 10:00:00');
        $this->assertSame('2027-Q1', $grammar->convertCarbonDateFormat(new GroupCondition(GroupCondition::YQ), $carbon));
        $this->assertSame('2026-W53', $grammar->convertCarbonDateFormat(new GroupCondition(GroupCondition::YW), $carbon));
    }

    /**
     * SQL Server: the expressions as written, and their ISO-year rule — the year of the day
     * 26 - (its ISO week) days away is its week's year — true of every day from 2020-12 to 2028-01.
     */
    public function testTheSqlServerGrammar(): void
    {
        $grammar = new SqlServerGrammar(DB::connection());
        $this->assertSame("format(datepart(YEAR, [d]), '0000') + '-Q' + format(datepart(QUARTER, [d]), '0')", $grammar->getDateFormatString(new GroupCondition(GroupCondition::YQ), 'd'));
        $this->assertSame("format(datepart(YEAR, dateadd(DAY, 26 - datepart(ISO_WEEK, [d]), [d])), '0000') + '-W' + format(datepart(ISO_WEEK, [d]), '00')", $grammar->getDateFormatString(new GroupCondition(GroupCondition::YW), 'd'));
        $this->assertSame($grammar->getDateFormatString(new GroupCondition(GroupCondition::YW), 'd'), $grammar->getDateFormatString(new GroupCondition(GroupCondition::YW), 'd', true), 'the same to group by');
        $this->assertSame('datepart(WEEKDAY, [d])', $grammar->getDateFormatString(new GroupCondition(GroupCondition::W), 'd', true), 'the weekday as before');

        for ($date = Carbon::parse('2020-12-01'); $date->lte(Carbon::parse('2028-01-31')); $date->addDay()) {
            $week = (int) $date->format('W');
            $year = (int) $date->copy()->addDays(26 - $week)->format('Y');
            $this->assertSame($date->format('o-\WW'), sprintf('%04d-W%02d', $year, $week), $date->format('Y-m-d'));
        }
        $carbon = Carbon::parse('2024-12-30');
        $this->assertSame('2024-Q4', $grammar->convertCarbonDateFormat(new GroupCondition(GroupCondition::YQ), $carbon));
        $this->assertSame('2025-W01', $grammar->convertCarbonDateFormat(new GroupCondition(GroupCondition::YW), $carbon));
    }

    /**
     * The view form offers the units it did (yq / yw are the chart's only).
     */
    public function testTheViewFormIsUnchanged(): void
    {
        $this->assertSame(['y', 'ym', 'ymd', 'm', 'd', 'w'], array_column(GroupCondition::getOptions(), 'id'));
    }

    /**
     * `pu`: one of the 5 units; remembered with the other toolbar choices.
     */
    public function testTheParam(): void
    {
        foreach (ChartPeriod::UNITS as $unit) {
            $this->assertSame($unit, ChartPeriod::normalize($unit));
        }
        foreach (['', 'w', 'm', 'd', 'ymdhis', 'YQ', ' yq', null, ['yq'], 1] as $bad) {
            $this->assertNull(ChartPeriod::normalize($bad), (string) json_encode($bad));
        }
        $this->assertSame(['cs' => 'x0:asc', 'pu' => 'yq'], ChartItem::toolbarState(['cs' => 'x0:asc', 'pu' => 'yq'], 'bar'));
        $this->assertNull(ChartItem::toolbarState(['pu' => 'w'], 'bar'));
    }

    // The chart's numbers -------------------------------------------------------------------------

    /**
     * One series, each unit: the points are the groups of the rows' dates by that unit, their
     * sums the rows' — worked out from the rows read back by plain SQL (a date without a value
     * as its own point ''; a group without a value of the measure, null).
     */
    public function testOneSeriesByEachUnit(): void
    {
        $this->createRows();
        $view = $this->dateView(GroupCondition::YM);
        $this->assertSame($this->expected(GroupCondition::YM), $this->points($this->render($view, 'bar', [])), 'the view\'s month');
        foreach (ChartPeriod::UNITS as $unit) {
            $render = $this->render($view, 'bar', ['pu' => $unit]);
            $this->assertSame($this->expected($unit), $this->points($render), $unit);
        }
        // by hand
        $this->assertSame(['' => 1024.0, '2024-Q4' => 1.0, '2025-Q1' => 6.0, '2025-Q2' => 24.0, '2025-Q4' => 32.0, '2026-Q1' => 448.0, '2026-Q2' => null, '2027-Q1' => 512.0], $this->expected(GroupCondition::YQ));
        $this->assertSame(['' => 1024.0, '2025-W01' => 3.0, '2025-W14' => 12.0, '2025-W24' => 16.0, '2026-W01' => 224.0, '2026-W02' => 256.0, '2026-W21' => null, '2026-W53' => 512.0], $this->expected(GroupCondition::YW));

        // the X axis and the box title name the unit
        $quarter = $this->render($view, 'bar', ['pu' => GroupCondition::YQ]);
        $column = CustomColumn::getEloquent('date', 'parent_table')->column_view_name;
        $axis = exmtrans('dashboard.chart_show_as.decorated', $column, exmtrans('dashboard.chart_period.units.yq'));
        $this->assertStringContainsString('data-ca-title="' . e(exmtrans('dashboard.chart_summary.title', $axis, $quarter['axisy'])) . '"', $quarter['body']);
        $this->assertStringContainsString('labelString: ' . json_encode($axis), $quarter['body'], 'the X axis name');

        // a COUNT, a pie: the same groups
        $count = $this->render($this->dateView(GroupCondition::YM, SummaryCondition::COUNT), 'pie', ['pu' => GroupCondition::Y]);
        $this->assertSame(['' => 1.0, '2024' => 1.0, '2025' => 5.0, '2026' => 3.0, '2027' => 1.0], $this->points($count));
    }

    /**
     * Several series (X the date, series odd_even): each cell the sum of its period's rows of its
     * series; the series column is never regrouped.
     */
    public function testSeveralSeries(): void
    {
        $this->createRows();
        $view = $this->dateView(GroupCondition::YM, SummaryCondition::SUM, true);
        foreach ([GroupCondition::YQ, GroupCondition::YW, GroupCondition::Y] as $unit) {
            $render = $this->render($view, 'mbar', ['pu' => $unit, 'cs' => 'x0:asc']);
            [$categories, $series] = $render['labels'];
            $cells = $this->expectedCells($unit);
            $this->assertSame(array_map('strval', array_keys($cells)), $categories, $unit);
            foreach ($series as $si => $name) {
                // a period without a value of the series: 0, as ChartPivot folds a SUM's empty cell
                $this->assertSame(array_map(function ($row) use ($name) {
                    return $row[$name] ?? 0.0;
                }, array_values($cells)), array_map(function ($v) {
                    return $v === null ? null : (float) $v;
                }, $render['values'][$si]), "$unit $name");
            }
        }
        $this->assertEquals(['s1' => 128.0, 's2' => 320.0], $this->expectedCells(GroupCondition::YQ)['2026-Q1'], 'by hand');
    }

    /**
     * With the sort, the 表示方法, the 目標線, 上位N件 and the 複合 type: each runs on the points of
     * the unit picked.
     */
    public function testWithTheOtherMenus(): void
    {
        $this->createRows();
        $view = $this->dateView(GroupCondition::YM);
        $quarters = $this->expected(GroupCondition::YQ);

        $desc = $this->render($view, 'bar', ['pu' => GroupCondition::YQ, 'cs' => 'x0:desc']);
        $this->assertSame(['2027-Q1', '2026-Q2', '2026-Q1', '2025-Q4', '2025-Q2', '2025-Q1', '2024-Q4', ''], $desc['labels']);
        $byValue = $this->render($view, 'bar', ['pu' => GroupCondition::YQ, 'cs' => 'y:desc']);
        $this->assertSame(['', '2027-Q1', '2026-Q1', '2025-Q4', '2025-Q2', '2025-Q1', '2024-Q4', '2026-Q2'], $byValue['labels']);

        // a running total along the quarters (none adds nothing)
        $running = $this->render($view, 'line', ['pu' => GroupCondition::YQ, 'cs' => 'x0:asc', 'sv' => ChartShowAs::RUNNING_TOTAL]);
        $total = 0.0;
        $want = [];
        foreach ($quarters as $value) {
            $total += $value ?? 0.0;
            $want[] = $total;
        }
        $this->assertSeries($want, $running['values'], 'running total');

        // the target line: the quarters under 30 painted
        $target = $this->render($view, 'bar', ['pu' => GroupCondition::YQ, 'cs' => 'x0:asc'], ['chart_target_value' => 30, 'chart_target_below' => '1']);
        $labels = array_keys($quarters);
        $below = [];
        foreach (array_values($quarters) as $i => $value) {
            if ($value !== null && $value < 30) {
                $below[] = $i;
            }
        }
        $this->assertSame([1, 2, 3], $below, '2024-Q4 1, 2025-Q1 6, 2025-Q2 24');
        $this->assertSame($below, $this->scriptValue($target['body'], 'target')['below']);
        $this->assertSame($labels, $target['labels']);

        // 上位N件 stays off along a date, a remembered pick ignored: every quarter drawn
        $cut = $this->render($view, 'bar', ['pu' => GroupCondition::YQ, 'tn' => '5:other']);
        $this->assertCount(8, $cut['labels']);
        $this->assertStringNotContainsString('data-tn-', $cut['body']);

        // 複合: the 2つ目の値 (受注件数) grouped the same way
        $two = $this->dateView(GroupCondition::YM, SummaryCondition::SUM, false, true);
        $count = CustomViewSummary::where('custom_view_id', $two->id)->orderBy('id', 'desc')->first();
        $this->assertNotNull($count);
        $this->assertSame(SummaryCondition::COUNT, (string) $count->view_summary_condition);
        $combo = $this->render($two, 'combo', ['pu' => GroupCondition::YQ, 'cs' => 'x0:asc'], [
            ChartSecondValue::COLUMN => ViewKindType::AGGREGATE . '_' . $count->id, ChartSecondValue::TYPE => 'line', ChartSecondValue::RIGHT => '1',
        ]);
        $this->assertSame($labels, $combo['labels']);
        $this->assertSeries(array_values($quarters), $combo['values'], '複合 値');
        $this->assertSeries([1, 1, 2, 2, 1, 3, 0, 1], (array) $this->scriptValue($combo['body'], 'values2'), '複合 2つ目の値: the rows with a value per quarter');
    }

    // The 明細 -----------------------------------------------------------------------------------

    /**
     * A double click on a point of the unit picked lists exactly the rows of that period (with a
     * value of the measure), read by the same expression; without the unit the request names, the
     * key is no group of the view and lists none. A cell of several series: its series' rows.
     */
    public function testTheDetailListsTheRowsOfThePeriod(): void
    {
        $this->createRows();
        $view = $this->dateView(GroupCondition::YM);
        $dashboard = $this->dashboard();
        $box = $this->savedBox($dashboard, $view, 'bar');
        $x = CustomViewColumn::where('custom_view_id', $view->id)->first();
        $this->assertNotNull($x);

        $points = $this->scriptValue($this->render($view, 'bar', ['pu' => GroupCondition::YQ, 'cs' => 'x0:asc'])['body'], 'detail')['points'];
        $this->assertSame(['', '2024-Q4', '2025-Q1', '2025-Q2', '2025-Q4', '2026-Q1', '2026-Q2', '2027-Q1'], array_map(function ($p) use ($x) {
            return (string) $p[$x->id];
        }, $points), 'the keys are the periods');

        $html = $this->detailPage($box, [$x->id => '2026-Q1'], 'pu=yq');
        $this->assertSame($this->rawIds(['2026-01-01', '2026-01-04', '2026-01-05']), $this->rowIdsIn($html), '2026-Q1');
        $this->assertStringContainsString('2026-Q1', $html);
        $html = $this->detailPage($box, [$x->id => '2026-W01'], 'pu=yw');
        $this->assertSame($this->rawIds(['2025-12-29', '2026-01-01', '2026-01-04']), $this->rowIdsIn($html), '2026-W01: from Monday the 29th');
        $html = $this->detailPage($box, [$x->id => '2026-W53'], 'pu=yw');
        $this->assertSame($this->rawIds(['2027-01-01']), $this->rowIdsIn($html));
        $html = $this->detailPage($box, [$x->id => '2026-Q2'], 'pu=yq');
        $this->assertSame([], $this->rowIdsIn($html), 'its one row has no value');
        // the month the view groups by, without the unit
        $html = $this->detailPage($box, [$x->id => '2026-01'], '');
        $this->assertSame($this->rawIds(['2026-01-01', '2026-01-04', '2026-01-05']), $this->rowIdsIn($html));
        $html = $this->detailPage($box, [$x->id => '2026-Q1'], '');
        $this->assertSame([], $this->rowIdsIn($html), 'no such month');

        // a cell: s2 of 2026-Q1
        $multi = $this->dateView(GroupCondition::YM, SummaryCondition::SUM, true);
        $multiBox = $this->savedBox($dashboard, $multi, 'mbar');
        $detail = $this->scriptValue($this->render($multi, 'mbar', ['pu' => GroupCondition::YQ])['body'], 'detail');
        $html = $this->detailPage($multiBox, [$detail['x'] => '2026-Q1', $detail['series'] => 's2'], 'pu=yq');
        $this->assertSame($this->rawIds(['2026-01-01', '2026-01-05']), $this->rowIdsIn($html));
    }

    // The Excel download -----------------------------------------------------------------------------

    /**
     * Excel出力 writes the points as drawn: the periods, their sums, the axis naming the unit.
     */
    public function testTheExcelHasThePeriods(): void
    {
        $this->createRows();
        $view = $this->dateView(GroupCondition::YM);
        $data = (new ChartItem($this->box($view, 'bar'), ['pu' => GroupCondition::YQ, 'cs' => 'x0:asc']))->excelData();
        $this->assertIsArray($data);
        $quarters = $this->expected(GroupCondition::YQ);
        $this->assertSame(array_keys($quarters), $data['categories']);
        $this->assertSeries(array_values($quarters), $data['matrix'][0], 'Excel');
        $column = CustomColumn::getEloquent('date', 'parent_table')->column_view_name;
        $this->assertSame(exmtrans('dashboard.chart_show_as.decorated', $column, exmtrans('dashboard.chart_period.units.yq')), $data['axisx']);
    }

    // Where it is offered --------------------------------------------------------------------------

    /**
     * The 期間の単位 row of the box menu, a group of its own under the sort and before 集計方法: the
     * 5 units, the view's checked and badged, the picked one named on the row. Offered on a date
     * grouped by y / ym / ymd or not (by day) and on the created / updated dates (not grouped: 日時
     * first); not on a month of any year, a weekday, a gauge, a list view.
     */
    public function testTheRowOfTheBoxMenu(): void
    {
        $this->createRows();
        $view = $this->dateView(GroupCondition::YM);
        $body = $this->render($view, 'bar', [])['body'];
        $menu = $this->between($body, 'class="ct-pop cs-pop"', 'exment-cs-reset');
        $this->assertNotNull($menu);
        $row = strpos($menu, 'data-pu-configured="ym"');
        $this->assertNotFalse($row);
        $this->assertGreaterThan(strpos($menu, 'data-cs-dir="asc"'), $row, 'under the sort');
        $this->assertLessThan(strpos($menu, 'data-ca-applied'), $row, 'before 集計方法');
        $this->assertStringContainsString('<ul class="cs-list cs-sep" data-pu-configured="ym" data-pu-applied="">', $menu);
        $this->assertStringContainsString('<ul class="cs-list cs-sep" data-ca-configured', $menu, '集計方法 its own group');
        $column = CustomColumn::getEloquent('date', 'parent_table')->column_view_name;
        $this->assertStringContainsString('<h4>' . e(exmtrans('dashboard.chart_period.heading', $column)) . '</h4>', $menu);
        foreach (ChartPeriod::UNITS as $unit) {
            $this->assertStringContainsString('data-pu-pick="' . $unit . '"><i class="fa fa-check"></i><span>' . e(exmtrans('dashboard.chart_period.units.' . $unit)) . '</span>', $menu);
        }
        $this->assertMatchesRegularExpression('/class="cs-row on" data-pu-pick="ym"><i class="fa fa-check"><\/i><span>[^<]+<\/span><em class="ca-badge">/u', $menu, 'the view\'s, checked');
        $this->assertSame(2, substr_count($menu, 'class="ca-badge"'), 'one badge in the 期間の単位, one in 集計方法');
        $this->assertStringContainsString('<b class="ca-cur">' . e(exmtrans('dashboard.chart_period.units.ym')) . '</b>', $menu);
        $this->assertStringNotContainsString('data-ca-title', $body, 'the configured title');

        $picked = $this->render($view, 'bar', ['pu' => GroupCondition::YQ])['body'];
        $this->assertStringContainsString('data-pu-configured="ym" data-pu-applied="yq"', $picked);
        $this->assertStringContainsString('cs-row ca-row pu-row ca-on', $picked);
        $this->assertMatchesRegularExpression('/class="cs-row on" data-pu-pick="yq"/', $picked);
        $this->assertStringContainsString('<b class="ca-cur">' . e(exmtrans('dashboard.chart_period.units.yq')) . '</b>', $picked);

        // a date not grouped: by its day
        $day = $this->render($this->dateView(null), 'bar', [])['body'];
        $this->assertStringContainsString('data-pu-configured="ymd" data-pu-applied=""', $day);
        $this->assertSame($this->expected(GroupCondition::YMD), $this->points($this->render($this->dateView(null), 'bar', [])));
        // the created date not grouped (a datetime): its own grouping (日時) offered first, checked
        $created = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'created_at', 'condition_type' => ConditionType::SYSTEM]],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]],
            'filter_settings' => [['column_name' => 'text', 'filter_condition' => FilterOption::EQ, 'filter_value_text' => self::MARK]],
        ]);
        $body = $this->render($created, 'bar', [])['body'];
        $this->assertStringContainsString('data-pu-configured="" data-pu-applied=""', $body);
        $this->assertMatchesRegularExpression('/class="cs-row on" data-pu-pick=""><i class="fa fa-check"><\/i><span>' . preg_quote(e(exmtrans('dashboard.chart_period.units.none')), '/') . '<\/span><em class="ca-badge">/u', $body);
        $today = Carbon::now();
        $this->assertSame([$today->format('Y') . '-Q' . $today->quarter => 2047.0], $this->points($this->render($created, 'bar', ['pu' => GroupCondition::YQ])), 'every row made now (the undated one too)');
        $this->assertSame([$today->format('Y') => 2047.0], $this->points($this->render($created, 'bar', ['pu' => GroupCondition::Y])));
        // the created date by the month of any year: its label "11" is no date the row can hold
        // (SystemItem kept it raw; it failed to cast before)
        $months = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'created_at', 'condition_type' => ConditionType::SYSTEM, 'options' => ['view_group_condition' => GroupCondition::M]]],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]],
            'filter_settings' => [['column_name' => 'text', 'filter_condition' => FilterOption::EQ, 'filter_value_text' => self::MARK]],
        ]);
        $render = $this->render($months, 'bar', ['pu' => GroupCondition::YQ]);
        $this->assertSame([$today->format('m') => 2047.0], $this->points($render));
        $this->assertStringNotContainsString('data-pu-', $render['body']);

        // a month of any year, a day of a month, a weekday: no row, the pick ignored
        foreach ([GroupCondition::M, GroupCondition::D, GroupCondition::W] as $cycle) {
            $render = $this->render($this->dateView($cycle), 'bar', ['pu' => GroupCondition::YQ]);
            $this->assertStringNotContainsString('data-pu-', $render['body'], $cycle);
            $this->assertStringNotContainsString('-Q', implode(',', $render['labels']), $cycle);
        }
        // a gauge; a text X
        $this->assertStringNotContainsString('data-pu-', $this->render($view, 'gauge', ['pu' => GroupCondition::YQ])['body']);
        $text = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]],
            'filter_settings' => [['column_name' => 'text', 'filter_condition' => FilterOption::EQ, 'filter_value_text' => self::MARK]],
        ]);
        $this->assertStringNotContainsString('data-pu-', $this->render($text, 'bar', ['pu' => GroupCondition::YQ])['body']);
        // several series: the X column only (here the text one; the date splits the legend)
        $series = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'date', 'options' => ['view_group_condition' => GroupCondition::YM]]],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]],
            'filter_settings' => [['column_name' => 'text', 'filter_condition' => FilterOption::EQ, 'filter_value_text' => self::MARK]],
        ]);
        $this->assertStringNotContainsString('data-pu-', $this->render($series, 'mbar', ['pu' => GroupCondition::YQ])['body']);
        // two group columns on one series: the date one, the label of the other kept
        $two = $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'date', 'options' => ['view_group_condition' => GroupCondition::YM]]],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]],
            'filter_settings' => [['column_name' => 'text', 'filter_condition' => FilterOption::EQ, 'filter_value_text' => self::MARK]],
        ]);
        $points = $this->points($this->render($two, 'bar', ['pu' => GroupCondition::Y]));
        $this->assertSame(1.0, $points['s1 2024']);
        $this->assertSame(512.0, $points['s1 2027']);
        $this->assertSame(16.0 + 2.0, $points['s2 2025']);
    }

    /**
     * Nothing picked — or the view's own unit, or no unit at all — draws the chart as before.
     */
    public function testNothingPickedIsTheChartAsBefore(): void
    {
        $this->createRows();
        $view = $this->dateView(GroupCondition::YM);
        $suuid = function (string $body): string {
            return (string) preg_replace('/\b[0-9a-f]{20}\b/', 'SUUID', $body);
        };
        $plain = $suuid($this->render($view, 'bar', ['cs' => 'x0:asc'])['body']);
        foreach (['', 'ym', 'w', 'q', 'YQ'] as $pu) {
            $this->assertSame($plain, $suuid($this->render($view, 'bar', ['cs' => 'x0:asc', 'pu' => $pu])['body']), "pu=$pu");
        }
        // the view is not changed: after a unit was drawn, the same request's caches still group
        // its column by month — another box of the view draws it as before
        System::clearCache();
        $box = $this->box($view, 'bar');
        (new ChartItem($box, ['pu' => GroupCondition::YQ, 'cs' => 'x0:asc']))->body();
        $cached = CustomView::getEloquent($view->id);
        $this->assertNotNull($cached);
        foreach ([$cached->custom_view_columns_cache, $cached->custom_view_columns] as $columns) {
            foreach ($columns as $column) {
                $this->assertSame(GroupCondition::YM, $column->view_group_condition);
            }
        }
        // (its points: a 2nd box of a view in one process names the measure twice, as before this)
        $again = (string) (new ChartItem($box, ['cs' => 'x0:asc']))->body();
        $this->assertSame(array_keys($this->expected(GroupCondition::YM)), array_map('strval', (array) $this->scriptValue($again, 'labels')), 'drawn by month afterwards');
        $this->assertStringContainsString('data-pu-configured="ym" data-pu-applied=""', $again);
        $column = CustomViewColumn::where('custom_view_id', $view->id)->first();
        $this->assertNotNull($column);
        $this->assertSame(GroupCondition::YM, $column->view_group_condition, 'nothing saved');
    }

    // Helpers -----------------------------------------------------------------------------------

    private function createRows(): void
    {
        foreach (self::ROWS as [$date, $series, $integer]) {
            $model = CustomTable::getEloquent('parent_table')->getValueModel();
            $model->setValue(['text' => self::MARK, 'date' => $date, 'odd_even' => $series, 'integer' => $integer, 'multiples_of_3' => 0]);
            $model->saved_notify(false);
            $model->save();
        }
    }

    /**
     * A view of the test's rows grouped by the date ($condition), the SUM (or $summary) of integer;
     * odd_even as a 2nd group column (the series); a COUNT besides.
     */
    private function dateView(?string $condition, string $summary = SummaryCondition::SUM, bool $series = false, bool $count = false): CustomView
    {
        $columns = [['column_name' => 'date'] + ($condition ? ['options' => ['view_group_condition' => $condition]] : [])];
        if ($series) {
            $columns[] = ['column_name' => 'odd_even'];
        }
        $summaries = [['column_name' => 'integer', 'summary_condition' => $summary]];
        if ($count) {
            $summaries[] = ['column_name' => 'integer', 'summary_condition' => SummaryCondition::COUNT];
        }
        return $this->aggregateView([
            'target_table_name' => 'parent_table',
            'column_settings' => $columns,
            'summary_settings' => $summaries,
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
     * The test's rows read back by plain SQL: [date (or null), series, integer (or null), id].
     *
     * @return array<int, array{0: string|null, 1: string, 2: float|null, 3: int}>
     */
    private function rawRows(): array
    {
        $rows = DB::select("SELECT id, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(value, '$.date')), 'null') AS d, JSON_UNQUOTE(JSON_EXTRACT(value, '$.odd_even')) AS s, "
            . "CAST(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(value, '$.integer')), 'null'), '') AS DECIMAL(30, 10)) AS v FROM " . getDBTableName('parent_table')
            . " WHERE deleted_at IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(value, '$.text')) = ? ORDER BY id", [self::MARK]);
        $this->assertCount(count(self::ROWS), $rows);
        return array_map(function ($row) {
            return [$row->d === null || $row->d === '' ? null : (string) $row->d, (string) $row->s, $row->v === null ? null : (float) $row->v, (int) $row->id];
        }, $rows);
    }

    /**
     * A date's period, worked out by Carbon (not by GroupCondition / the Grammar).
     */
    private function periodOf(?string $date, string $unit): string
    {
        if ($date === null) {
            return '';
        }
        $carbon = Carbon::parse($date);
        switch ($unit) {
            case GroupCondition::YMD:
                return $carbon->format('Y-m-d');
            case GroupCondition::YW:
                return $carbon->format('o') . '-W' . $carbon->format('W');
            case GroupCondition::YM:
                return $carbon->format('Y-m');
            case GroupCondition::YQ:
                return $carbon->format('Y') . '-Q' . (int) ceil($carbon->month / 3);
            default:
                return $carbon->format('Y');
        }
    }

    /**
     * The SUM per period of the raw rows, ordered by period ('' first): null when none of its rows has a value.
     *
     * @return array<string, float|null>
     */
    private function expected(string $unit): array
    {
        $out = [];
        foreach ($this->rawRows() as [$date, , $value]) {
            $key = $this->periodOf($date, $unit);
            $out[$key] = $value === null ? ($out[$key] ?? null) : ($out[$key] ?? 0.0) + $value;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * The SUM per period and series of the raw rows: [period][series].
     *
     * @return array<string, array<string, float|null>>
     */
    private function expectedCells(string $unit): array
    {
        $out = [];
        foreach ($this->rawRows() as [$date, $series, $value]) {
            $key = $this->periodOf($date, $unit);
            $out[$key][$series] = $value === null ? ($out[$key][$series] ?? null) : ($out[$key][$series] ?? 0.0) + $value;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Ids of the test's rows dated $dates.
     *
     * @param string[] $dates
     * @return int[]
     */
    private function rawIds(array $dates): array
    {
        $ids = [];
        foreach ($this->rawRows() as [$date, , , $id]) {
            if (in_array($date, $dates, true)) {
                $ids[] = $id;
            }
        }
        sort($ids);
        return $ids;
    }

    /**
     * The chart's points label => value (as a float, null for none), in label order.
     *
     * @param array<string, mixed> $render
     * @return array<string, float|null>
     */
    private function points(array $render): array
    {
        $this->assertIsArray($render['labels']);
        $this->assertIsArray($render['values']);
        $out = [];
        foreach (array_values($render['labels']) as $i => $label) {
            $value = $render['values'][$i];
            $out[(string) $label] = $value === null ? null : (float) $value;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * @param array<int, \stdClass> $rows
     */
    private function dayOf(array $rows, string $day): \stdClass
    {
        foreach ($rows as $row) {
            if ($row->d === $day) {
                return $row;
            }
        }
        $this->fail("no $day");
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options  box options besides
     * @return array{body: string, values: mixed, labels: mixed, axisy: string|null}
     */
    private function render(CustomView $view, string $type, array $params, array $options = []): array
    {
        System::clearCache();
        $body = (string) (new ChartItem($this->box($view, $type, $options), $params))->body();
        $multi = in_array($type, ['mbar', 'sbar', 'sbar100', 'shbar100', 'mline', 'sarea', 'heatmap', 'treemap', 'sunburst', 'boxplot'], true);
        $axisy = null;
        if (preg_match('/axisy: ("(?:[^"\\\\]|\\\\.)*"|null)/u', $body, $m) || preg_match('/var axisYName = ("(?:[^"\\\\]|\\\\.)*"|null);/u', $body, $m)) {
            $axisy = json_decode($m[1], true);
        }
        return [
            'body' => $body,
            'values' => $this->scriptValue($body, $multi ? 'matrix' : 'values'),
            'labels' => $multi ? [$this->scriptValue($body, 'xCategories'), $this->scriptValue($body, 'seriesNames')] : $this->scriptValue($body, 'labels'),
            'axisy' => $axisy,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function box(CustomView $view, string $type, array $options = []): DashboardBox
    {
        $x = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->first();
        $summary = CustomViewSummary::where('custom_view_id', $view->id)->orderBy('id')->first();
        $this->assertNotNull($x);
        $this->assertNotNull($summary);
        $box = new DashboardBox();
        $box->suuid = short_uuid();
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->dashboard_box_view_name = 'period';
        $box->options = $options + [
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
        $dashboard->dashboard_name = 'period_' . short_uuid();
        $dashboard->dashboard_view_name = 'period dashboard';
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
