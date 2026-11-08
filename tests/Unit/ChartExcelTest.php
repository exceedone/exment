<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\ShareTargetType;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\ChartExcel;
use Exceedone\Exment\Services\Dashboard\ChartShowAs;
use Exceedone\Exment\Services\Dashboard\ChartThresholds;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\ConditionalFormatting\CellMatcher;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The chart box's Excel出力: the sheet holds the numbers the box draws for the same request — read
 * back from the box's own HTML (body()) — under the box's type, sort, 集計方法 and 表示方法, with
 * Excel's chart of the matching kind; a heatmap colors its cells by the box's thresholds through
 * conditional formatting, whose rules are evaluated cell by cell against ChartThresholds::classOf.
 * The xlsx is written to a file and read back each time.
 */
class ChartExcelTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    private const MARK = 'chart_excel_case';

    private const PARAMS = ['sv', 'ca', 'cs', 'ct', 'cd', 'bf_odd_even', 'df_index_text', 'df_odd_even'];

    /** Excel's chart per type: [plot type, grouping, direction] */
    private const CHARTS = [
        'bar' => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, DataSeries::DIRECTION_COL],
        'hbar' => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, DataSeries::DIRECTION_BAR],
        'line' => [DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD, null],
        'area' => [DataSeries::TYPE_AREACHART, DataSeries::GROUPING_STANDARD, null],
        'pie' => [DataSeries::TYPE_PIECHART, null, null],
        'doughnut' => [DataSeries::TYPE_DOUGHNUTCHART, null, null],
        'radar' => [DataSeries::TYPE_RADARCHART, null, null],
        'scatter' => [DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD, null],
        'mbar' => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, DataSeries::DIRECTION_COL],
        'sbar' => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_STACKED, DataSeries::DIRECTION_COL],
        'sbar100' => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_PERCENT_STACKED, DataSeries::DIRECTION_COL],
        'shbar100' => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_PERCENT_STACKED, DataSeries::DIRECTION_BAR],
        'mline' => [DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD, null],
        'sarea' => [DataSeries::TYPE_AREACHART, DataSeries::GROUPING_STACKED, null],
    ];

    /** the types Excel has no chart of: the table and a note */
    private const TABLE_ONLY = ['funnel', 'gauge', 'treemap', 'sunburst', 'boxplot'];

    /** @var string[] files written by the test */
    private $files = [];

    /** @var Dashboard|null the dashboard the boxes built by box() sit on (its filter bar) */
    private $on_dashboard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        foreach (self::PARAMS as $param) {
            request()->offsetUnset($param);
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // The table and the chart ----------------------------------------------------------------------

    /**
     * Every type with an Excel chart, as drawn: the categories, the series and each value of the
     * sheet are the box's own (its HTML), under the runtime sort, the 集計方法 and the 表示方法 (a
     * percent as its fraction, 0.0%); the chart is Excel's of the same kind, its data the table.
     */
    public function testTheSheetIsTheChartAsDrawn(): void
    {
        $this->createRows();
        $one = $this->rowsView(['odd_even']);
        $two = $this->rowsView(['odd_even', 'index_text']);
        $cases = [];
        foreach (['bar', 'hbar', 'line', 'area', 'pie', 'doughnut', 'radar', 'scatter'] as $type) {
            $cases[] = [$one, $type, []];
            $cases[] = [$one, $type, ['cs' => 'y:desc', 'cd' => 'labels']];
            $cases[] = [$one, $type, ['ca' => SummaryCondition::MAX]];
        }
        $cases[] = [$one, 'bar', ['sv' => 'percent_of_total']];
        $cases[] = [$one, 'line', ['sv' => 'running_total']];
        foreach (['mbar', 'sbar', 'sbar100', 'shbar100', 'mline', 'sarea'] as $type) {
            $cases[] = [$two, $type, []];
            $cases[] = [$two, $type, ['cs' => 'y:asc']];
        }
        $cases[] = [$two, 'mbar', ['sv' => 'percent_of_total']];
        $cases[] = [$two, 'sbar', ['sv' => 'difference']];
        $cases[] = [$two, 'mline', ['ca' => SummaryCondition::MIN]];
        $cases[] = [$two, 'mbar', ['ct' => 'shbar100']];

        // every 表示方法 each type offers, of a 合計 and of an 平均 (the differences only)
        $avg_one = $this->rowsView(['odd_even'], SummaryCondition::AVG);
        $avg_two = $this->rowsView(['odd_even', 'index_text'], SummaryCondition::AVG);
        foreach ([[SummaryCondition::SUM, $one, $two], [SummaryCondition::AVG, $avg_one, $avg_two]] as [$condition, $single, $multi]) {
            foreach (['bar', 'hbar', 'line', 'area', 'pie', 'doughnut', 'mbar', 'sbar', 'mline', 'sarea'] as $type) {
                foreach (ChartShowAs::choicesFor($type, $condition, true) as $show_as) {
                    if ($show_as !== ChartShowAs::NONE) {
                        $cases[] = [in_array($type, ['mbar', 'sbar', 'mline', 'sarea'], true) ? $multi : $single, $type, ['sv' => $show_as]];
                    }
                }
            }
        }
        $this->assertGreaterThan(60, count($cases));

        foreach ($cases as [$view, $type, $params]) {
            $this->assertAsDrawn($view, $type, $params);
        }
    }

    /**
     * A horizontal bar lists its first category on top as the box does: its category axis runs
     * from the top (maxMin).
     */
    public function testAHorizontalBarKeepsTheOrder(): void
    {
        $this->createRows();
        $two = $this->rowsView(['odd_even', 'index_text']);
        foreach ([[$this->rowsView(['odd_even']), 'hbar'], [$two, 'shbar100']] as [$view, $type]) {
            $xml = $this->chartXml($view, $type, []);
            $this->assertMatchesRegularExpression('/<c:catAx>.*<c:orientation val="maxMin"\/>.*<\/c:catAx>/s', $xml, $type);
        }
        $this->assertStringNotContainsString('maxMin', $this->chartXml($two, 'sbar100', []), 'a column chart as Excel draws it');
    }

    /**
     * The 合計 of a row where the series stack and add up (a SUM / COUNT shown as it is), a formula
     * of the row; the 割合 of a slice of a pie that adds up. Neither for a measure that does not
     * add up, nor for the values of a 表示方法.
     */
    public function testTheTotalAndTheShare(): void
    {
        $this->createRows();
        $two = $this->rowsView(['odd_even', 'index_text']);
        $count = $this->rowsView(['odd_even', 'index_text'], SummaryCondition::COUNT, 'text');
        $one = $this->rowsView(['odd_even']);
        $total = exmtrans('dashboard.chart_excel.total');
        $share = exmtrans('dashboard.chart_excel.share');

        foreach ([[$two, 'sbar', []], [$two, 'sbar100', []], [$two, 'shbar100', []], [$two, 'sarea', []], [$count, 'sbar', []]] as [$view, $type, $params]) {
            $drawn = $this->drawn($view, $type, $params);
            $sheet = $this->sheet($view, $type, $params);
            $col = count($drawn['series']) + 2;
            $this->assertSame($total, $sheet->getCell([$col, ChartExcel::HEADER_ROW])->getValue(), $type);
            foreach ($drawn['categories'] as $x => $category) {
                $row = ChartExcel::HEADER_ROW + 1 + $x;
                $this->assertStringStartsWith('=SUM(B' . $row . ':', (string) $sheet->getCell([$col, $row])->getValue(), "$type $category");
                $sum = array_sum(array_map(function ($values) use ($x) {
                    return (float) ($values[$x] ?? 0);
                }, $drawn['raw'] ?? $drawn['matrix']));
                $this->assertEqualsWithDelta($sum, (float) $sheet->getCell([$col, $row])->getCalculatedValue(), 1e-9, "$type $category");
            }
        }
        foreach ([[$two, 'mbar', []], [$two, 'sbar', ['sv' => 'running_total']], [$two, 'sbar', ['ca' => SummaryCondition::MAX]], [$this->rowsView(['odd_even', 'index_text'], SummaryCondition::AVG), 'sbar', []]] as [$view, $type, $params]) {
            $drawn = $this->drawn($view, $type, $params);
            $sheet = $this->sheet($view, $type, $params);
            $this->assertNull($sheet->getCell([count($drawn['series']) + 2, ChartExcel::HEADER_ROW])->getValue(), "$type " . json_encode($params) . ': no total');
        }

        foreach (['pie', 'doughnut'] as $type) {
            $drawn = $this->drawn($one, $type, []);
            $sheet = $this->sheet($one, $type, []);
            $this->assertSame($share, $sheet->getCell('C' . ChartExcel::HEADER_ROW)->getValue(), $type);
            $sum = array_sum(array_map('floatval', $drawn['matrix'][0]));
            foreach ($drawn['matrix'][0] as $x => $value) {
                $cell = $sheet->getCell('C' . (ChartExcel::HEADER_ROW + 1 + $x));
                $this->assertEqualsWithDelta((float) $value / $sum, (float) $cell->getCalculatedValue(), 1e-9, "$type [$x]");
            }
            $this->assertSame('0.0%', $sheet->getStyle('C' . (ChartExcel::HEADER_ROW + 1))->getNumberFormat()->getFormatCode());
            $this->assertNull($this->sheet($one, $type, ['sv' => 'percent_of_total'])->getCell('C' . ChartExcel::HEADER_ROW)->getValue(), "$type 比率: no share");
        }
        $this->assertNull($this->sheet($one, 'bar', [])->getCell('C' . ChartExcel::HEADER_ROW)->getValue(), 'bar: no share');
    }

    /**
     * A label is written as text whatever it looks like: "=1+2", "+x", "-5", "@s" are no formulas,
     * no numbers, and keep Excel's quote (') for an edit of the cell; an empty value stays empty.
     */
    public function testTextsStayTexts(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even', 'index_text'], SummaryCondition::MAX);
        $sheet = $this->sheet($view, 'mbar', [], '=HYPERLINK("x")');
        $this->assertSame('=HYPERLINK("x")', $sheet->getCell('A1')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
        $this->assertTrue($sheet->getStyle('A1')->getQuotePrefix());
        $this->assertSame('=HYPERLINK("x")', $sheet->getTitle(), 'the sheet name');

        $texts = [];
        for ($row = ChartExcel::HEADER_ROW; $row <= $sheet->getHighestRow(); $row++) {
            for ($col = 1; $col <= 6; $col++) {
                $cell = $sheet->getCell([$col, $row]);
                if (is_string($cell->getValue()) && preg_match('/^[=+\-@]/', $cell->getValue())) {
                    $texts[$cell->getValue()] = true;
                    $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), $cell->getValue());
                    $this->assertTrue($sheet->getStyle([$col, $row])->getQuotePrefix(), $cell->getValue());
                }
            }
        }
        foreach (['=1+2', '+x', '-5', '@s'] as $text) {
            $this->assertArrayHasKey($text, $texts, $text);
        }
        // a MAX cell no row fell into is empty (null), as on the chart, not 0
        $drawn = $this->drawn($view, 'mbar', []);
        $empty = 0;
        foreach ($drawn['matrix'] as $s => $values) {
            foreach ($values as $x => $value) {
                if ($value === null) {
                    $empty++;
                    $this->assertNull($sheet->getCell([$s + 2, ChartExcel::HEADER_ROW + 1 + $x])->getValue());
                }
            }
        }
        $this->assertGreaterThan(0, $empty, 'the case has empty cells');
    }

    /**
     * The heatmap: a row per series, a column per category as on the chart, each cell colored by
     * the first threshold its value meets (the boundaries included as the rule says), the cells
     * no rule takes by the chart's plain color, the empty ones not at all — Excel's own rules,
     * evaluated here cell by cell.
     */
    public function testTheHeatmapByItsThresholds(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even', 'index_text'], SummaryCondition::MAX);
        $rules = [
            ['color' => '#f8696b', 'condition' => 'lt', 'value' => 0],
            ['color' => '#63be7b', 'condition' => 'range', 'value' => 30, 'value2' => 90],
            ['color' => '#1d39c4', 'condition' => 'gte', 'value' => 90],
            ['color' => '#ffeb84', 'condition' => 'lte', 'value' => 7],
        ];
        $thresholds = ChartThresholds::fromOption($rules);
        $drawn = $this->drawn($view, 'heatmap', [], $rules);
        $sheet = $this->sheet($view, 'heatmap', [], null, $rules);
        $this->assertCount(0, $sheet->getChartCollection());

        $this->assertSame($drawn['categories'], array_map(function ($x) use ($sheet) {
            return $sheet->getCell([$x + 2, ChartExcel::HEADER_ROW])->getValue();
        }, array_keys($drawn['categories'])), 'a column per category');
        $this->assertSame($drawn['series'], $this->column($sheet, 1, count($drawn['series'])), 'a row per series');

        $range = 'B' . (ChartExcel::HEADER_ROW + 1) . ':' . chr(ord('A') + count($drawn['categories'])) . (ChartExcel::HEADER_ROW + count($drawn['series']));
        $conditionals = $sheet->getConditionalStyles($range);
        $this->assertCount(count($rules) + 1, $conditionals, 'a rule per threshold and the plain cells');
        $colors = array_merge(array_column($rules, 'color'), [ChartThresholds::NONE_COLOR]);
        foreach ($conditionals as $i => $conditional) {
            $this->assertSame(Conditional::CONDITION_EXPRESSION, $conditional->getConditionType());
            $this->assertTrue($conditional->getStopIfTrue(), "rule $i stops");
            $this->assertSame('FF' . strtoupper(ltrim($colors[$i], '#')), $conditional->getStyle()->getFill()->getStartColor()->getARGB(), "rule $i color");
        }
        // white text on the dark blue, dark on the others (the chart's)
        $this->assertSame('FFFFFFFF', $conditionals[2]->getStyle()->getFont()->getColor()->getARGB());
        $this->assertSame('FF2B2B3A', $conditionals[0]->getStyle()->getFont()->getColor()->getARGB());

        $met = [];
        foreach ($drawn['matrix'] as $s => $values) {
            foreach ($values as $x => $value) {
                $cell = $sheet->getCell([$x + 2, ChartExcel::HEADER_ROW + 1 + $s]);
                $matcher = new CellMatcher($cell, $range);
                $first = null;
                foreach ($conditionals as $i => $conditional) {
                    if ($matcher->evaluateConditional($conditional)) {
                        $first = $i;
                        break;
                    }
                }
                $class = $thresholds->classOf($value);
                $want = $class === null ? null : ($class === ChartThresholds::NONE ? count($rules) : $class);
                $this->assertSame($want, $first, "[$s][$x] " . json_encode($value));
                $met[(string) $want] = true;
            }
        }
        // the case meets every rule, the plain color and an empty cell
        foreach (array_merge(array_keys($rules), [count($rules), '']) as $rule) {
            $this->assertArrayHasKey((string) $rule, $met, "rule $rule met");
        }
        // the note names the rules as the chart's legend does, in its order
        $legend = array_column(array_filter($thresholds->pieces(), function ($piece) {
            return $piece['rule'] !== ChartThresholds::NONE;
        }), 'label');
        $this->assertSame(exmtrans('dashboard.chart_excel.heatmap_rules', implode('、', $legend)), $sheet->getCell('A4')->getValue());
    }

    /**
     * Without thresholds — or while the chart draws other numbers (集計方法), which the box's
     * thresholds do not color — a heatmap's cells stay plain, and the sheet says so.
     */
    public function testAPlainHeatmap(): void
    {
        $this->createRows();
        $view = $this->rowsView(['odd_even', 'index_text'], SummaryCondition::MAX);
        $rules = [['color' => '#f8696b', 'condition' => 'lt', 'value' => 0]];
        foreach ([[[], null], [['ca' => SummaryCondition::MIN], $rules]] as [$params, $set]) {
            $sheet = $this->sheet($view, 'heatmap', $params, null, $set);
            $this->assertCount(0, $sheet->getConditionalStylesCollection(), (string) json_encode($params));
            $this->assertSame(exmtrans('dashboard.chart_excel.heatmap_plain'), $sheet->getCell('A4')->getValue(), (string) json_encode($params));
        }
    }

    /**
     * A type Excel has no chart of: the table, no chart, a note naming the type.
     */
    public function testTypesWithoutAnExcelChart(): void
    {
        $this->createRows();
        $one = $this->rowsView(['odd_even']);
        $two = $this->rowsView(['odd_even', 'index_text']);
        foreach (self::TABLE_ONLY as $type) {
            $view = in_array($type, ['funnel', 'gauge'], true) ? $one : $two;
            $drawn = $this->drawn($view, $type, []);
            $sheet = $this->sheet($view, $type, []);
            $this->assertCount(0, $sheet->getChartCollection(), $type);
            $this->assertSame(exmtrans('dashboard.chart_excel.table_only', exmtrans('chart.chart_type_options.' . $type)), $sheet->getCell('A4')->getValue(), $type);
            $this->assertSame($drawn['categories'], $this->column($sheet, 1, count($drawn['categories'])), $type);
            $this->assertEqualsWithDelta((float) $drawn['matrix'][0][0], (float) $sheet->getCell('B' . (ChartExcel::HEADER_ROW + 1))->getValue(), 1e-9, $type);
        }
    }

    /**
     * The heading: the title, the view, the box's filter as the box captions it — and the rows are
     * the filtered ones; the series in the box's colors, a painted one too.
     */
    public function testTheHeadingAndTheColors(): void
    {
        $this->createRows();
        $two = $this->rowsView(['odd_even', 'index_text']);
        $sheet = $this->sheet($two, 'sbar', []);
        $this->assertSame('売上 sbar', $sheet->getCell('A1')->getValue());
        $this->assertStringContainsString(exmtrans('dashboard.chart_excel.view', $two->view_view_name), (string) $sheet->getCell('A2')->getValue());
        $this->assertSame(exmtrans('dashboard.chart_excel.filters', exmtrans('dashboard.chart_excel.no_filter')), $sheet->getCell('A3')->getValue());

        $params = ['bf_odd_even' => ['a', 'b']];
        $extra = ['chart_filters' => ['odd_even']];
        $sheet = $this->sheet($two, 'sbar', $params, null, null, $extra);
        $this->assertSame(['a', 'b'], $this->column($sheet, 1, 2));
        $this->assertNull($sheet->getCell('A' . (ChartExcel::HEADER_ROW + 3))->getValue(), 'the filtered rows only');
        $this->assertStringContainsString('a', (string) $sheet->getCell('A3')->getValue());
        $this->assertNotSame(exmtrans('dashboard.chart_excel.filters', exmtrans('dashboard.chart_excel.no_filter')), $sheet->getCell('A3')->getValue());

        $palette = array_values(stringToArray(config('exment.chart_backgroundColor')));
        $xml = $this->chartXml($two, 'mbar', [], ['chart_colors' => ['series' => ['s2' => '#123abc']]]);
        $this->assertStringContainsString('<a:srgbClr val="123ABC"', $xml, 'the painted series');
        $this->assertStringContainsString('<a:srgbClr val="' . strtoupper(ltrim($palette[0], '#')) . '"', $xml, 'the palette');
    }

    /**
     * The download: the workbook named after the box and the date; a box that draws a message
     * instead answers it; an unknown box is missing.
     */
    public function testTheDownload(): void
    {
        $login = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertNotNull($login);
        $this->actingAs($login, 'admin');
        $this->createRows();
        $one = $this->rowsView(['odd_even']);
        $dashboard = $this->dashboard();
        $box = $this->savedBox($dashboard, $one, 'bar', '月別/売上');

        $response = $this->get($this->url('dashboardbox/excel/' . $box->suuid . '?ct=hbar&cs=y:desc'));
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString(rawurlencode('月別_売上_' . date('Ymd') . '.xlsx'), (string) $response->headers->get('Content-Disposition'));
        $sent = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $sent);
        $file = $sent->getFile()->getPathname();
        $book = $this->read($file);
        $this->assertSame('月別 売上', $book->getTitle());
        $this->assertSame(DataSeries::DIRECTION_BAR, $this->plot($book->getChartCollection()[0])->getPlotDirection());
        $drawn = $this->drawn($one, 'bar', ['ct' => 'hbar', 'cs' => 'y:desc']);
        $this->assertSame($drawn['categories'], $this->column($book, 1, count($drawn['categories'])), 'sorted as asked');

        $message = $this->savedBox($dashboard, $one, 'mbar', 'need two columns');
        $response = $this->get($this->url('dashboardbox/excel/' . $message->suuid));
        $response->assertStatus(422)->assertJson(['message' => exmtrans('dashboard.message.need_multiseries')]);

        $this->get($this->url('dashboardbox/excel/nosuchbox00000000000'))->assertStatus(404);
    }

    /**
     * The names: no character a file or a sheet name cannot hold, a sheet name of 31 at most.
     */
    public function testTheNames(): void
    {
        $this->assertSame('a b c d e f g h', ChartExcel::sheetName('a/b:c*d?e[f]g\\h'));
        $this->assertSame(31, mb_strlen(ChartExcel::sheetName(str_repeat('売', 40))));
        $this->assertSame('Chart', ChartExcel::sheetName(" '/' "));
        $this->assertSame("it's", ChartExcel::sheetName("'it's'"));
        $excel = new ChartExcel(['title' => 'a/b:c*d?"e<f>g|h'], \Carbon\Carbon::create(2026, 11, 7));
        $this->assertSame('a_b_c_d_e_f_g_h_20261107.xlsx', $excel->fileName());
        $this->assertSame('chart_20261107.xlsx', (new ChartExcel(['title' => ' '], \Carbon\Carbon::create(2026, 11, 7)))->fileName());
    }

    // Who exports, under which filter, of which view, how much ---------------------------------

    /**
     * Who may export: the box of a dashboard they cannot view is missing (404); shared to them
     * but with no permission on the table, the box draws 権限がありません and the export answers
     * it (422); allowed to view the table, they get the workbook — the rows they may see.
     */
    public function testWhoMayExport(): void
    {
        $this->createRows();
        $one = $this->rowsView(['odd_even']);
        $owner = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertNotNull($owner);
        $this->asUser($owner);
        $dashboard = $this->dashboard();
        $box = $this->savedBox($dashboard, $one, 'bar', 'クラス別 売上');
        $url = $this->url('dashboardbox/excel/' . $box->suuid);
        $this->get($url)->assertOk();
        $drawn = $this->drawn($one, 'bar', []);

        $viewer = $this->newLogin();
        $this->asUser($viewer);
        $this->get($url)->assertStatus(404);

        $this->share($dashboard, $viewer);
        $this->asUser($viewer);
        $this->assertNotNull(Dashboard::find($dashboard->id), 'shared: the viewer sees the dashboard');
        $this->get($url)->assertStatus(422)->assertExactJson(['message' => trans('admin.deny')]);
        $this->assertSame(trans('admin.deny'), (new ChartItem($box->fresh()))->excelData(), 'the message the box draws');

        $this->grant($viewer, Permission::CUSTOM_VALUE_VIEW_ALL);
        $this->asUser($viewer);
        $sent = $this->get($url)->assertOk()->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $sent);
        $sheet = $this->read($sent->getFile()->getPathname());
        $this->assertSame($drawn['categories'], $this->column($sheet, 1, count($drawn['categories'])));
        foreach ($drawn['matrix'][0] as $x => $want) {
            // allowed every row: the owner's numbers
            $this->assertEqualsWithDelta((float) $want, (float) $sheet->getCell([2, ChartExcel::HEADER_ROW + 1 + $x])->getValue(), 1e-9, "[$x]");
        }
    }

    /**
     * A user who may see part of the table's rows: the workbook holds the numbers of their
     * chart — of their rows — not the whole table's.
     */
    public function testAUserGetsTheNumbersOfTheirRows(): void
    {
        $view = $this->createCustomViewAll([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::COUNT]],
        ], ViewKindType::AGGREGATE)[1];
        $whole = $this->sheet($view, 'bar', []);

        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertNotNull($user);
        $this->asUser($user);
        $sheet = $this->assertAsDrawn($view, 'bar', [], 'user2');
        $this->assertLessThan($this->total($whole), $this->total($sheet), 'their rows only');
        $this->assertGreaterThan(0, $this->total($sheet));
    }

    /**
     * The dashboard's filter bar (df_*): an item on another column narrows the rows, and the
     * heading lists it; an item on the chart's X column highlights instead (the chart keeps
     * every category), so it is no filter of the sheet either. Through the download too, the
     * way the page asks (?df_index_text[]=s1).
     */
    public function testTheDashboardFilter(): void
    {
        $this->createRows();
        $one = $this->rowsView(['odd_even']);
        $two = $this->rowsView(['odd_even', 'index_text']);
        $dashboard = $this->dashboard(['source_table' => 'parent_table', 'dims' => [['column' => 'index_text'], ['column' => 'odd_even']]]);
        $this->on_dashboard = $dashboard;
        $none = exmtrans('dashboard.chart_excel.filters', exmtrans('dashboard.chart_excel.no_filter'));

        $sheet = $this->assertAsDrawn($one, 'bar', ['df_index_text' => ['s1']]);
        $this->assertSame(['a', 'b'], $this->column($sheet, 1, 2));
        $this->assertNull($sheet->getCell('A' . (ChartExcel::HEADER_ROW + 3))->getValue(), 'the rows of s1 only');
        $this->assertEquals([150, 0], $this->column($sheet, 2, 2));
        $this->assertStringContainsString('s1', (string) $sheet->getCell('A3')->getValue());
        $this->assertNotSame($none, $sheet->getCell('A3')->getValue());

        $sheet = $this->assertAsDrawn($two, 'sbar', ['df_index_text' => ['s1', 's2'], 'cs' => 'y:desc']);
        $this->assertSame(['s1', 's2'], [$sheet->getCell('B5')->getValue(), $sheet->getCell('C5')->getValue()]);

        // the X column's item: highlighted, every category kept, no filter in the heading
        $sheet = $this->assertAsDrawn($one, 'bar', ['df_odd_even' => ['a']]);
        $this->assertCount(5, array_filter($this->column($sheet, 1, 6), function ($v) {
            return $v !== null;
        }));
        $this->assertSame($none, $sheet->getCell('A3')->getValue());

        // the download, its params from the query string
        $this->on_dashboard = null;
        $box = $this->savedBox($dashboard, $one, 'bar', 'df');
        $sent = $this->get($this->url('dashboardbox/excel/' . $box->suuid . '?df_index_text[]=s1'))->assertOk()->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $sent);
        $book = $this->read($sent->getFile()->getPathname());
        $this->assertSame(['a', 'b', null], $this->column($book, 1, 3));
        $this->assertStringContainsString('s1', (string) $book->getCell('A3')->getValue());
    }

    /**
     * A box of a list view (no 集計): a point per row, in the view's order; its value is no
     * summary, so no 割合 / 合計.
     */
    public function testAListView(): void
    {
        $this->createRows();
        $view = $this->createCustomViewAll([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'integer']],
            'filter_settings' => [['column_name' => 'text', 'filter_condition' => FilterOption::EQ, 'filter_value_text' => self::MARK]],
        ], ViewKindType::DEFAULT)[1];
        foreach (array_keys(self::CHARTS) as $type) {
            if (in_array($type, ['mbar', 'sbar', 'sbar100', 'shbar100', 'mline', 'sarea'], true)) {
                continue;
            }
            $sheet = $this->assertAsDrawn($view, $type, []);
            $this->assertSame(9, count(array_filter($this->column($sheet, 1, 10), function ($v) {
                return $v !== null;
            })), "$type: a point per row");
            $this->assertNull($sheet->getCell('C' . ChartExcel::HEADER_ROW)->getValue(), "$type: no 割合 / 合計");
            $this->assertAsDrawn($view, $type, ['cs' => 'y:desc']);
        }
    }

    /**
     * Many categories: every one in the table and the chart; a horizontal bar grows with them,
     * the chart under the table, never over it.
     */
    public function testManyCategories(): void
    {
        $count = 120;
        for ($i = 1; $i <= $count; $i++) {
            $model = CustomTable::getEloquent('parent_table')->getValueModel();
            $model->setValue(['text' => self::MARK, 'odd_even' => sprintf('k%03d', $i), 'index_text' => 's' . ($i % 3), 'integer' => $i, 'multiples_of_3' => 0]);
            $model->saved_notify(false);
            $model->save();
        }
        $one = $this->rowsView(['odd_even']);
        $two = $this->rowsView(['odd_even', 'index_text']);
        foreach ([[$one, 'bar'], [$one, 'hbar'], [$two, 'sbar'], [$two, 'shbar100'], [$two, 'mline']] as [$view, $type]) {
            $sheet = $this->assertAsDrawn($view, $type, ['cs' => 'y:desc']);
            $last = ChartExcel::HEADER_ROW + $count;
            $this->assertNotNull($sheet->getCell('A' . $last)->getValue(), $type);
            $this->assertNull($sheet->getCell('A' . ($last + 1))->getValue(), $type);
            $chart = $sheet->getChartCollection()[0];
            $top = (int) preg_replace('/\D/', '', $chart->getTopLeftCell());
            $bottom = (int) preg_replace('/\D/', '', $chart->getBottomRightCell());
            $this->assertGreaterThan($last, $top, "$type: under the table");
            $this->assertGreaterThanOrEqual(in_array($type, ['hbar', 'shbar100'], true) ? $count : 20, $bottom - $top, "$type: its height");
        }
    }

    // Helpers ------------------------------------------------------------------------------------

    /**
     * The sheet of the box as drawn: its categories, its series and each value (a percent as
     * its fraction, 0.0%); Excel's chart of the kind of the type shown, its data the table.
     *
     * @param array<string, mixed> $params
     */
    private function assertAsDrawn(CustomView $view, string $type, array $params, string $label = ''): Worksheet
    {
        $label = trim("$label $type " . json_encode($params));
        $drawn = $this->drawn($view, $type, $params);
        $sheet = $this->sheet($view, $type, $params);
        $shown = $params['ct'] ?? $type;
        $percent = in_array($params['sv'] ?? null, [ChartShowAs::PERCENT_OF_TOTAL, ChartShowAs::PERCENT_DIFFERENCE], true);

        $this->assertSame($drawn['categories'], $this->column($sheet, 1, count($drawn['categories'])), "$label: the categories");
        $this->assertNull($sheet->getCell('A' . (ChartExcel::HEADER_ROW + 1 + count($drawn['categories'])))->getValue(), "$label: no more");
        foreach ($drawn['series'] as $s => $name) {
            $this->assertSame((string) $name, $sheet->getCell([$s + 2, ChartExcel::HEADER_ROW])->getValue(), "$label: series $s");
            foreach ($drawn['matrix'][$s] as $x => $want) {
                $cell = $sheet->getCell([$s + 2, ChartExcel::HEADER_ROW + 1 + $x]);
                if ($want === null || $want === '') {
                    $this->assertNull($cell->getValue(), "$label: [$s][$x] empty");
                    continue;
                }
                $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType(), "$label: [$s][$x] a number");
                $this->assertEqualsWithDelta($percent ? (float) $want / 100 : (float) $want, (float) $cell->getValue(), 1e-9, "$label: [$s][$x]");
            }
        }
        if ($percent) {
            $this->assertSame('0.0%', $sheet->getStyle('B' . (ChartExcel::HEADER_ROW + 1))->getNumberFormat()->getFormatCode(), $label);
        }

        $charts = $sheet->getChartCollection();
        $this->assertCount(1, $charts, $label);
        $group = $this->plot($charts[0]);
        [$plot, $grouping, $direction] = self::CHARTS[$shown];
        $this->assertSame($plot, $group->getPlotType(), "$label: the kind");
        if ($grouping !== null) {
            $this->assertSame($grouping, $group->getPlotGrouping(), "$label: grouping");
        }
        if ($direction !== null) {
            $this->assertSame($direction, $group->getPlotDirection(), "$label: direction");
        }
        $this->assertCount(count($drawn['series']), $group->getPlotValues(), "$label: a series per column");
        $last = ChartExcel::HEADER_ROW + count($drawn['categories']);
        $values = $group->getPlotValuesByIndex(0);
        $categories = $group->getPlotCategoryByIndex(0);
        $this->assertInstanceOf(DataSeriesValues::class, $values, $label);
        $this->assertInstanceOf(DataSeriesValues::class, $categories, $label);
        $this->assertStringEndsWith('!$B$' . (ChartExcel::HEADER_ROW + 1) . ':$B$' . $last, (string) $values->getDataSource(), "$label: the values");
        $this->assertStringEndsWith('!$A$' . (ChartExcel::HEADER_ROW + 1) . ':$A$' . $last, (string) $categories->getDataSource(), "$label: the categories");
        return $sheet;
    }

    /**
     * The sum of the table's numbers (column B on).
     */
    private function total(Worksheet $sheet): float
    {
        $total = 0.0;
        for ($row = ChartExcel::HEADER_ROW + 1; $sheet->getCell('A' . $row)->getValue() !== null; $row++) {
            for ($col = 2; $sheet->getCell([$col, ChartExcel::HEADER_ROW])->getValue() !== null; $col++) {
                $total += (float) $sheet->getCell([$col, $row])->getValue();
            }
        }
        return $total;
    }

    private function asUser(LoginUser $login): void
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        System::clearCache();
        $this->actingAs($login, 'admin');
        $this->be($login);
    }

    /**
     * A login user of a user of their own, in no role group.
     */
    private function newLogin(): LoginUser
    {
        $code = 'chart_excel_' . short_uuid();
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
        $user->setValue(['user_code' => $code, 'user_name' => $code, 'email' => $code . '@example.com']);
        $user->saved_notify(false);
        $user->save();
        $login = new LoginUser();
        $login->base_user_id = $user->id;
        $login->password = 'chart_excel_password';
        $login->save();
        return $login;
    }

    private function share(Dashboard $dashboard, LoginUser $login): void
    {
        DB::table('data_share_authoritables')->insert([
            'parent_id' => $dashboard->id, 'parent_type' => ShareTargetType::DASHBOARD, 'authoritable_type' => Permission::DATA_SHARE_VIEW,
            'authoritable_user_org_type' => SystemTableName::USER, 'authoritable_target_id' => $login->base_user_id,
        ]);
    }

    /**
     * A role group with $permission on parent_table, for the login user's user.
     */
    private function grant(LoginUser $login, string $permission): void
    {
        $name = 'chart_excel_' . short_uuid();
        $group = RoleGroup::create(['role_group_name' => $name, 'role_group_view_name' => $name, 'role_group_order' => 0]);
        RoleGroupPermission::create([
            'role_group_id' => $group->id, 'role_group_permission_type' => RoleType::TABLE,
            'role_group_target_id' => CustomTable::getEloquent('parent_table')->id, 'permissions' => [$permission],
        ]);
        RoleGroupUserOrganization::create([
            'role_group_id' => $group->id, 'role_group_user_org_type' => SystemTableName::USER, 'role_group_target_id' => $login->base_user_id,
        ]);
    }

    /**
     * Rows of parent_table marked self::MARK (text): odd_even × index_text, integer — a: 120, 30
     * (s1), 50 (s2); b: 0 (s1), -40 (s2); c: 90 (s2); "=1+2": 7 ("+x"); "-5": 3, b: 20 ("@s").
     */
    private function createRows(): void
    {
        $rows = [['a', 's1', 120], ['a', 's1', 30], ['a', 's2', 50], ['b', 's1', 0], ['b', 's2', -40], ['c', 's2', 90], ['=1+2', '+x', 7], ['-5', '@s', 3], ['b', '@s', 20]];
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
        System::clearCache();
        return $this->createCustomViewAll([
            'target_table_name' => 'parent_table',
            'column_settings' => array_map(function ($column) {
                return ['column_name' => $column];
            }, $groups),
            'summary_settings' => [['column_name' => $measure, 'summary_condition' => $condition]],
            'filter_settings' => [['column_name' => 'text', 'filter_condition' => FilterOption::EQ, 'filter_value_text' => self::MARK]],
        ], ViewKindType::AGGREGATE)[1];
    }

    /**
     * @param array<int, array<string, mixed>>|null $thresholds
     * @param array<string, mixed> $extra  more box options
     */
    private function box(CustomView $view, string $type, ?string $title = null, ?array $thresholds = null, array $extra = []): DashboardBox
    {
        $columns = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->orderBy('id')->get();
        $x = $columns->first();
        $this->assertNotNull($x);
        if ($view->view_kind_type == ViewKindType::AGGREGATE) {
            $summary = CustomViewSummary::where('custom_view_id', $view->id)->first();
            $this->assertNotNull($summary);
            $y = ViewKindType::AGGREGATE . '_' . $summary->id;
        } else {
            $y = ViewKindType::DEFAULT . '_' . $columns->last()->id;
        }
        $box = new DashboardBox();
        $box->suuid = 'xl' . substr(md5($view->id . $type . microtime()), 0, 18);
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->dashboard_box_view_name = $title ?? '売上 ' . $type;
        $box->options = [
            'target_table_id' => $view->custom_table_id,
            'target_view_id' => $view->id,
            'chart_type' => $type,
            'chart_axisx' => ViewKindType::DEFAULT . '_' . $x->id,
            'chart_axisy' => $y,
            'chart_options' => ['1'],
        ] + ($thresholds === null ? [] : [ChartThresholds::OPTION => $thresholds]) + $extra;
        if ($this->on_dashboard !== null) {
            $box->setRelation('dashboard', $this->on_dashboard);
        }
        return $box;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function request(array $params): void
    {
        System::clearCache();
        foreach (self::PARAMS as $param) {
            request()->offsetUnset($param);
        }
        request()->merge($params);
    }

    /**
     * What the box's HTML draws: the categories, the series and the values (matrix[series][x]) —
     * a 100% stacked chart's numbers in `raw`, the shares it draws in `matrix`.
     *
     * @param array<string, mixed> $params
     * @param array<int, array<string, mixed>>|null $thresholds
     * @return array{categories: array<int, mixed>, series: array<int, mixed>, matrix: array<int, array<int, mixed>>, raw?: array<int, array<int, mixed>>}
     */
    private function drawn(CustomView $view, string $type, array $params, ?array $thresholds = null): array
    {
        $this->request($params);
        $body = (string) (new ChartItem($this->box($view, $type, null, $thresholds)))->body();
        $this->request([]);
        $categories = $this->scriptJson($body, 'xCategories');
        if ($categories !== null) {
            $raw = $this->scriptJson($body, 'values');
            return ['categories' => $categories, 'series' => $this->scriptJson($body, 'seriesNames'), 'matrix' => $raw ?? $this->scriptJson($body, 'matrix')]
                + ($raw === null ? [] : ['raw' => $raw]);
        }
        $labels = $this->scriptJson($body, 'labels');
        $this->assertIsArray($labels, "$type: drawn");
        $values = $this->scriptJson($body, 'values');
        $axisy = preg_match('/var axisYName = ("(?:[^"\\\\]|\\\\.)*"|null);/u', $body, $m) ? json_decode($m[1], true) : null;
        if ($axisy === null && preg_match('/label: ("(?:[^"\\\\]|\\\\.)*"),\s*\n\s*data: values/u', $body, $m)) {
            $axisy = json_decode($m[1], true);
        }
        return ['categories' => $labels, 'series' => [$axisy], 'matrix' => [$values]];
    }

    /**
     * The box's Excel sheet for a request, written and read back.
     *
     * @param array<string, mixed> $params
     * @param array<int, array<string, mixed>>|null $thresholds
     * @param array<string, mixed> $extra
     */
    private function sheet(CustomView $view, string $type, array $params, ?string $title = null, ?array $thresholds = null, array $extra = []): Worksheet
    {
        $this->request($params);
        $data = (new ChartItem($this->box($view, $type, $title, $thresholds, $extra)))->excelData();
        $this->request([]);
        $this->assertIsArray($data, "$type: exported");
        $file = $this->tempFile();
        (new ChartExcel($data))->save($file);
        return $this->read($file);
    }

    /**
     * The chart part of the box's workbook, as written.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $extra
     */
    private function chartXml(CustomView $view, string $type, array $params, array $extra = []): string
    {
        $this->request($params);
        $data = (new ChartItem($this->box($view, $type, null, null, $extra)))->excelData();
        $this->request([]);
        $this->assertIsArray($data);
        $file = $this->tempFile();
        (new ChartExcel($data))->save($file);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file) === true);
        $xml = (string) $zip->getFromName('xl/charts/chart1.xml');
        $zip->close();
        $this->assertNotSame('', $xml, "$type: a chart");
        return $xml;
    }

    private function tempFile(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'chart_excel_test_');
        $this->files[] = $file;
        return $file;
    }

    private function read(string $file): Worksheet
    {
        $reader = new XlsxReader();
        $reader->setIncludeCharts(true);
        return $reader->load($file)->getActiveSheet();
    }

    private function plot(Chart $chart): DataSeries
    {
        $groups = $chart->getPlotArea()?->getPlotGroup() ?? [];
        $this->assertCount(1, $groups);
        return $groups[0];
    }

    /**
     * Column $col of the table's rows (from the header's next row), $count of them.
     *
     * @return array<int, mixed>
     */
    private function column(Worksheet $sheet, int $col, int $count): array
    {
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $values[] = $sheet->getCell([$col, ChartExcel::HEADER_ROW + 1 + $i])->getValue();
        }
        return $values;
    }

    /**
     * @param array<string, mixed>|null $filterBar
     */
    private function dashboard(?array $filterBar = null): Dashboard
    {
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::USER;
        $dashboard->dashboard_name = 'chart_excel_' . short_uuid();
        $dashboard->dashboard_view_name = 'chart excel';
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0] + ($filterBar === null ? [] : ['filter_bar' => $filterBar]);
        $dashboard->save();
        return $dashboard;
    }

    private function savedBox(Dashboard $dashboard, CustomView $view, string $type, string $title): DashboardBox
    {
        $box = $this->box($view, $type, $title);
        $box->suuid = null;
        $box->dashboard_id = $dashboard->id;
        $box->row_no = 1;
        $box->column_no = 1;
        $box->save();
        return $box;
    }

    private function url(string $path): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/' . $path;
    }

    /**
     * The JSON array a body's script assigns to `var $name` (null: none / null).
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
        if (substr($body, $at, 1) === '(') {
            $at++; // var values = ([...] || []).map(...)
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
}
