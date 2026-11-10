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
use Exceedone\Exment\Services\Dashboard\ChartExcel;
use Exceedone\Exment\Services\Dashboard\ChartSecondValue;
use Exceedone\Exment\Services\Dashboard\ChartShowAs;
use Exceedone\Exment\Services\Dashboard\ChartSummarySwitch;
use Exceedone\Exment\Services\Dashboard\ChartTopN;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

/**
 * The 複合 type (Excel's 組み合わせグラフ): a box's 2つ目の値 — another summary column of its view —
 * drawn with the 値, checked against parent_table's rows by raw SQL; with the sort, 表示件数, the
 * target line and the 集計方法; a box without one (or whose view lost it) drawing as before; the
 * form's save and validation; the Excel download's secondary axis; the template.
 */
class ChartComboTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    private const PARAMS = [ChartShowAs::PARAM, ChartSummarySwitch::PARAM, ChartTopN::PARAM, 'cs', 'ct', 'options'];

    private const SECONDARY = 'xl/charts/chart1.xml';

    /** @var string[] temporary files to remove */
    private $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        $this->forgetParams();
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // The chart ----------------------------------------------------------------------------------

    /**
     * parent_table per text: the 値 SUM(integer), the 2つ目の値 COUNT(integer), point by point as raw
     * SQL counts them; the names, axes and units of both; a 明細 per point, the picker offering 複合.
     */
    public function testBothValuesAgainstRawSql(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $sql = $this->rawSql();
        $this->assertGreaterThan(5, count($sql));
        $view = $this->comboView();
        $render = $this->render($view, 'combo', $this->second($view));

        $this->assertEqualsCanonicalizing(array_keys($sql), array_map('strval', $render['labels']), 'a point per text');
        foreach ($render['labels'] as $i => $label) {
            $this->assertEqualsWithDelta($sql[(string) $label]['sum'], (float) $render['values'][$i], 1e-9, "sum $label");
            $this->assertEquals($sql[(string) $label]['count'], $render['values2'][$i], "count $label");
        }
        $combo = $render['combo'];
        $this->assertSame(['合計', '件数', '合計（左軸）', '件数（右軸）', 'line', true], [$combo['name1'], $combo['name2'], $combo['legend1'], $combo['legend2'], $combo['type2'], $combo['right']]);
        $this->assertSame(['prefix' => '', 'suffix' => '件', 'digits' => 0], $combo['unit2']);
        $this->assertSame(['prefix' => '', 'suffix' => '', 'digits' => 0], $combo['unit1'], 'an integer column');
        $this->assertNotSame($combo['color1'], $combo['color2'], 'two colors apart');
        $this->assertCount(count($render['labels']), $render['detail']['points'] ?? [], 'a 明細 per point');
        $this->assertStringContainsString('data-ct-type="combo"', $render['body']);
        $this->assertStringContainsString('echart_combo', $this->bladeName($render['body']));

        // the same box drawn as a bar: the 値 alone, 複合 offered with its 2つ目の値 for the preview
        $bar = $this->render($view, 'bar', $this->second($view));
        $this->assertNull($bar['values2']);
        $this->assertStringContainsString('data-ct-type="combo"', $bar['body']);
        $this->assertMatchesRegularExpression('/data-second="[^"]*&quot;values&quot;/', $bar['body']);
    }

    /**
     * Sorted by the 値: the 2つ目の値 goes with its point. 上位5件＋その他: ranked by the 値, the
     * rest's 2つ目の値 its total (a count adds up), none for an average. The 集計方法 switches the 値
     * only; no 表示方法 on 複合.
     */
    public function testSortTopNAndSummarySwitch(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $sql = $this->rawSql();
        $view = $this->comboView();
        $options = $this->second($view);

        $sorted = $this->render($view, 'combo', $options, ['cs' => 'y:desc']);
        $values = array_map('floatval', $sorted['values']);
        $desc = $values;
        rsort($desc);
        $this->assertSame($desc, $values, 'sorted by the 値');
        foreach ($sorted['labels'] as $i => $label) {
            $this->assertEquals($sql[(string) $label]['count'], $sorted['values2'][$i], "the 2つ目の値 of $label goes along");
        }

        $ranked = $sql;
        uasort($ranked, function ($a, $b) {
            return $b['sum'] <=> $a['sum'];
        });
        $top = array_slice($ranked, 0, 5, true);
        $rest = array_slice($ranked, 5, null, true);
        $cut = $this->render($view, 'combo', $options, [ChartTopN::PARAM => '5:other']);
        $this->assertCount(6, $cut['labels']);
        $this->assertSame(array_map('strval', array_keys($top)), array_map('strval', array_slice($cut['labels'], 0, 5)));
        $this->assertEqualsWithDelta(array_sum(array_column($rest, 'sum')), (float) $cut['values'][5], 1e-6, 'その他: the rest of the 値');
        $this->assertEquals(array_sum(array_column($rest, 'count')), $cut['values2'][5], 'その他: the rest of a count');
        foreach (array_keys($top) as $i => $label) {
            $this->assertEquals($sql[$label]['count'], $cut['values2'][$i]);
        }
        $average = $this->render($view, 'combo', $this->second($view, '平均'), [ChartTopN::PARAM => '5:other']);
        $this->assertNull($average['values2'][5], 'その他 of an average: none');
        $this->assertEqualsWithDelta($sql[(string) $average['labels'][0]]['avg'], (float) $average['values2'][0], 0.005);

        // 集計方法 → 件数: the 値 is the count, the 2つ目の値 (平均) as it is
        $switched = $this->render($view, 'combo', $this->second($view, '平均'), [ChartSummarySwitch::PARAM => SummaryCondition::COUNT]);
        foreach ($switched['labels'] as $i => $label) {
            $this->assertEquals($sql[(string) $label]['count'], $switched['values'][$i], "件数 $label");
            $this->assertEqualsWithDelta($sql[(string) $label]['avg'], (float) $switched['values2'][$i], 0.005, "平均 $label");
        }
        $this->assertSame('件', $switched['combo']['unit1']['suffix'], 'the left axis in 件');
        $this->assertStringContainsString('data-ca-configured', $switched['body'], 'the 集計方法 offered');

        // no 表示方法: a request for one draws the values as they are
        $plain = $this->render($view, 'combo', $options);
        $percent = $this->render($view, 'combo', $options, [ChartShowAs::PARAM => ChartShowAs::PERCENT_OF_TOTAL]);
        $this->assertSame($plain['values'], $percent['values']);
        $this->assertStringNotContainsString('data-sv-applied', $percent['body']);
    }

    /**
     * The target line is the 値's, on the left axis: the bars under it in its color, その他 never.
     */
    public function testTheTargetLineOfTheValue(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $view = $this->comboView();
        $sums = array_column($this->rawSql(), 'sum');
        sort($sums);
        $median = $sums[intdiv(count($sums), 2)];
        $options = $this->second($view) + ['chart_target_value' => $median, 'chart_target_label' => '目標', 'chart_target_below' => '1'];
        $render = $this->render($view, 'combo', $options);
        $this->assertNotNull($render['target']);
        $under = [];
        foreach ($render['values'] as $i => $v) {
            if ((float) $v < $median) {
                $under[] = $i;
            }
        }
        $this->assertNotEmpty($under);
        $this->assertSame($under, $render['target']['below'], 'the bars under it, worked out by hand');

        $cut = $this->render($view, 'combo', array_merge($options, ['chart_target_value' => 1e12]), [ChartTopN::PARAM => '5:other']);
        $this->assertCount(6, $cut['values']);
        $this->assertSame([0, 1, 2, 3, 4], $cut['target']['below'], 'every bar under 1e12 but その他');
    }

    /**
     * A box without a 2つ目の値 draws as before: no 複合 on its picker, a request for it drawn as the
     * box's type, nothing of a second value in the page. A bar box with one draws the same bars.
     */
    public function testABoxWithoutOneDrawsAsBefore(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $view = $this->comboView();
        foreach (['bar', 'line', 'area', 'pie', 'hbar'] as $type) {
            $plain = $this->render($view, $type, []);
            $this->assertStringNotContainsString('data-ct-type="combo"', $plain['body'], $type);
            $this->assertStringNotContainsString('data-second', $plain['body'], $type);
            $this->assertStringNotContainsString('values2', $plain['body'], $type);
            $this->assertSame($plain['body'], $this->render($view, $type, [], ['ct' => 'combo'])['body'], "$type: 複合 refused");
        }
        // with a 2つ目の値: the chart itself as without one
        foreach (['bar', 'line', 'area'] as $type) {
            $this->assertSame($this->chartPart($this->render($view, $type, [])['body']), $this->chartPart($this->render($view, $type, $this->second($view))['body']), $type);
        }
    }

    /**
     * The view changed since: the 2つ目の値's column gone, made the 値, of another view — the 複合 box
     * draws its 値 as bars, with no error; its Excel too.
     */
    public function testAViewChangedAfterwards(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $view = $this->comboView();
        $other = $this->comboView();
        $plainBar = $this->render($view, 'bar', []);
        $cases = [
            'of another view' => $this->second($other),
            'the 値 itself' => [ChartSecondValue::COLUMN => $this->axisy($view, '合計')],
            'a group column' => [ChartSecondValue::COLUMN => ViewKindType::DEFAULT . '_' . CustomViewColumn::where('custom_view_id', $view->id)->value('id')],
            'garbage' => [ChartSecondValue::COLUMN => ['1_1']],
        ];
        foreach ($cases as $case => $options) {
            $render = $this->render($view, 'combo', $options);
            $this->assertSame('chartjs', $this->bladeName($render['body']), $case);
            $this->assertSame($plainBar['values'], $render['values'], $case);
            $this->assertStringNotContainsString('data-ct-type="combo"', $render['body'], $case);
        }
        $options = $this->second($view);
        CustomViewSummary::where('custom_view_id', $view->id)->where('view_column_name', '件数')->delete();
        System::clearCache();
        $gone = $this->render($view, 'combo', $options);
        $this->assertSame('chartjs', $this->bladeName($gone['body']), 'the column gone');
        $this->assertSame($plainBar['values'], $gone['values']);
        $data = $this->excelData($view, 'combo', $options);
        $this->assertSame('bar', $data['type']);
        $this->assertNull($data['second']);
    }

    // The box form ---------------------------------------------------------------------------------

    /**
     * Saved with the box's options — a line unless bars, the right axis only when ticked —, shown on
     * the edit form; none on a type it is not drawn with. Errors on the field: 複合 without one, the
     * 値 itself, a column of another view, a group column, a style of no kind; nothing saved.
     */
    public function testTheBoxFormSavesAndValidates(): void
    {
        $this->login();
        $dashboard = $this->dashboard();
        $view = $this->comboView();
        $count = $this->axisy($view, '件数');
        $this->post($this->url('dashboardbox'), $this->boxForm($dashboard, $view, 'combo', [ChartSecondValue::COLUMN => $count, ChartSecondValue::TYPE => 'bar', ChartSecondValue::RIGHT => '']))->assertSessionHasNoErrors();
        $box = DashboardBox::where('dashboard_id', $dashboard->id)->first();
        $this->assertNotNull($box);
        $stored = [ChartSecondValue::COLUMN => $count, ChartSecondValue::TYPE => 'bar', ChartSecondValue::RIGHT => '0'];
        $this->assertEqualsCanonicalizing($stored, array_intersect_key($box->options, $stored));
        $this->assertSame('combo', $box->options['chart_type']);

        $html = $this->get($this->url("dashboardbox/{$box->id}/edit"))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/<option value="' . preg_quote($count, '/') . '"\s+selected/', $html);
        $this->assertStringContainsString(exmtrans('dashboard.chart_second.header'), $html);

        $this->put($this->url("dashboardbox/{$box->id}"), $this->boxForm($dashboard, $view, 'bar', [ChartSecondValue::COLUMN => $count, ChartSecondValue::RIGHT => '1']))->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([ChartSecondValue::COLUMN => $count, ChartSecondValue::TYPE => 'line', ChartSecondValue::RIGHT => '1'], array_intersect_key((array) DashboardBox::where('id', $box->id)->first()?->options, $stored), 'a bar keeps it: 複合 on its picker');
        $this->put($this->url("dashboardbox/{$box->id}"), $this->boxForm($dashboard, $view, 'pie', [ChartSecondValue::COLUMN => $count, ChartSecondValue::TYPE => 'bar']))->assertSessionHasNoErrors();
        $this->assertSame([], array_intersect_key((array) DashboardBox::where('id', $box->id)->first()?->options, $stored), 'a pie: none, not checked');

        $other = $this->comboView();
        $cases = [
            '複合 without one' => ['combo', []],
            'the 値 itself' => ['combo', [ChartSecondValue::COLUMN => $this->axisy($view, '合計')]],
            'of another view' => ['bar', [ChartSecondValue::COLUMN => $this->axisy($other, '件数')]],
            'a group column' => ['line', [ChartSecondValue::COLUMN => ViewKindType::DEFAULT . '_' . CustomViewColumn::where('custom_view_id', $view->id)->value('id')]],
        ];
        foreach ($cases as [$type, $fields]) {
            $response = $this->put($this->url("dashboardbox/{$box->id}"), $this->boxForm($dashboard, $view, $type, $fields));
            $response->assertSessionHasErrors(['options.' . ChartSecondValue::COLUMN]);
        }
        $this->put($this->url("dashboardbox/{$box->id}"), $this->boxForm($dashboard, $view, 'combo', [ChartSecondValue::COLUMN => $count, ChartSecondValue::TYPE => 'pie']))
            ->assertSessionHasErrors(['options.' . ChartSecondValue::TYPE]);
        $this->assertSame('pie', DashboardBox::where('id', $box->id)->first()?->options['chart_type'], 'nothing saved');
        $this->assertSame(1, DashboardBox::where('dashboard_id', $dashboard->id)->count());
    }

    /**
     * The form's list of the 2つ目の値 (chart_axis/y2): the summary columns of an aggregate view after
     * an empty choice; none of a list view.
     */
    public function testTheChoicesOfTheForm(): void
    {
        $this->login();
        $view = $this->comboView();
        $list = $this->get($this->url('dashboardbox/chart_axis/y2?q=' . $view->id))->assertOk()->json();
        $this->assertSame(['', $this->axisy($view, '合計'), $this->axisy($view, '件数'), $this->axisy($view, '平均')], array_column($list, 'id'));
        $listView = CustomView::where('custom_table_id', $view->custom_table_id)->where('view_kind_type', ViewKindType::DEFAULT)->first();
        $this->assertNotNull($listView);
        $this->assertSame([], $this->get($this->url('dashboardbox/chart_axis/y2?q=' . $listView->id))->assertOk()->json());
    }

    /**
     * A dashboard template carries it — the count of the column the 値 sums is told apart from the
     * sum by its summary condition.
     */
    public function testTheTemplateKeepsIt(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $view = $this->comboView();
        $options = array_merge($this->second($view), [ChartSecondValue::TYPE => 'bar', ChartSecondValue::RIGHT => '0']);
        $box = $this->box($view, 'combo', $options);
        $box->dashboard_id = $this->dashboard()->id;
        $box->row_no = 1;
        $box->column_no = 1;
        $box->dashboard_box_view_name = 'template';
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->save();

        $exported = $box->getTemplateExportItems();
        $this->assertSame((string) SummaryCondition::COUNT, array_get($exported, 'options.chart_axisy2_summary_condition'));
        $this->assertArrayNotHasKey(ChartSecondValue::COLUMN, (array) array_get($exported, 'options'), 'no id in a template');
        $other = $this->dashboard();
        DashboardBox::importTemplate($exported, false, ['parent' => $other]);
        $imported = DashboardBox::where('dashboard_id', $other->id)->first();
        $this->assertNotNull($imported);
        $this->assertEqualsCanonicalizing($options, array_intersect_key($imported->options, $options));
        $this->assertSame($this->axisy($view, '件数'), $imported->options[ChartSecondValue::COLUMN], 'the count, not the sum of the same column');
        $this->assertArrayNotHasKey('chart_axisy2_summary_condition', $imported->options);
    }

    // Excel --------------------------------------------------------------------------------------

    /**
     * Excel's 組み合わせグラフ: the 値's column as bars, the 2つ目の値's as a line labelled in 件 on the
     * secondary axis (a value axis on the right, crossing at the end, a hidden category axis); on one
     * axis without 第2軸; as bars of their own on it; the cells in each value's units.
     */
    public function testTheExcelDownload(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $view = $this->comboView();
        $render = $this->render($view, 'combo', $this->second($view));
        $data = $this->excelData($view, 'combo', $this->second($view));
        $this->assertSame(['合計', '件数'], $data['series']);
        $this->assertEquals([$render['values'], $render['values2']], $data['matrix']);

        $file = $this->saveExcel($data);
        $sheet = $this->read($file);
        $this->assertSame('件数', $sheet->getCell([3, ChartExcel::HEADER_ROW])->getValue());
        $this->assertEquals($render['values2'][0], $sheet->getCell([3, ChartExcel::HEADER_ROW + 1])->getValue());
        $this->assertSame('#,##0"件"', $sheet->getStyle([3, ChartExcel::HEADER_ROW + 1])->getNumberFormat()->getFormatCode());
        $groups = $sheet->getChartCollection()[0]->getPlotArea()?->getPlotGroup() ?? [];
        $this->assertCount(2, $groups, 'the bars and the line, read back');

        $xml = $this->chartXml($file);
        $this->assertSame(['barChart', 'lineChart'], $xml['groups']);
        $this->assertSame([['l', null], ['r', 'max']], $xml['valAx'], 'a value axis on the right, crossing at the end');
        $this->assertSame(['0', '1'], $xml['catDeleted'], 'its category axis hidden');
        $this->assertSame($xml['valIds'][1], $xml['groupIds'][1][1], 'the line on the right axis');
        $this->assertSame($xml['valIds'][0], $xml['groupIds'][0][1], 'the bars on the left one');
        $this->assertSame('件数', $xml['valTitles'][1]);
        $this->assertSame('#,##0"件"', $xml['valFormats'][1]);
        $this->assertStringContainsString('<c:dLbls><c:numFmt formatCode="#,##0&quot;件&quot;"', $xml['raw'], 'the line labelled in 件');
        // 25 points or more: the line unlabelled — Excel hides no label that overlaps — unless 値ラベル
        $many = $data;
        $many['categories'] = array_map('strval', range(1, 30));
        $many['matrix'] = [array_fill(0, 30, 10), array_fill(0, 30, 3)];
        $many['point_colors'] = array_fill(0, 30, '#5b8ff9');
        $this->assertStringNotContainsString('<c:dLbls><c:numFmt', $this->chartXml($this->saveExcel($many))['raw']);
        $this->assertStringContainsString('<c:dLbls><c:numFmt', $this->chartXml($this->saveExcel(['labels' => true] + $many))['raw']);

        $shared = $this->chartXml($this->saveExcel($this->excelData($view, 'combo', array_merge($this->second($view), [ChartSecondValue::RIGHT => '0']))));
        $this->assertCount(1, $shared['valAx'], '第2軸 off: one value axis');
        $bars = $this->chartXml($this->saveExcel($this->excelData($view, 'combo', array_merge($this->second($view), [ChartSecondValue::TYPE => 'bar']))));
        $this->assertSame(['barChart', 'barChart'], $bars['groups']);
        $this->assertCount(2, $bars['valAx']);
        $together = $this->chartXml($this->saveExcel($this->excelData($view, 'combo', array_merge($this->second($view), [ChartSecondValue::TYPE => 'bar', ChartSecondValue::RIGHT => '0']))));
        $this->assertSame(['barChart'], $together['groups'], 'bars side by side on one axis');

        // PhpSpreadsheet writes the groups of a type as one: the 2つ目の値's bars split off the 値's onto
        // the right axis; its line split off the target's, which stays the 値's on the left
        $this->assertSame([$bars['valIds'][0], $bars['valIds'][1]], [$bars['groupIds'][0][1], $bars['groupIds'][1][1]], 'bars: the 値 left, the 2つ目の値 right');
        $this->assertSame(1, substr_count($bars['groupXml'][0], '<c:ser>'));
        $this->assertSame(1, substr_count($bars['groupXml'][1], '<c:ser>'));
        $target = $this->chartXml($this->saveExcel($this->excelData($view, 'combo', $this->second($view) + ['chart_target_value' => 100, 'chart_target_label' => '目標'])));
        $this->assertSame(['barChart', 'lineChart', 'lineChart'], $target['groups']);
        $axisOf = [];
        foreach ($target['groupXml'] as $g => $xml) {
            $this->assertSame(1, substr_count($xml, '<c:ser>'), "group $g: one series");
            $axisOf[strpos($xml, '$D$6') !== false ? 'target' : (strpos($xml, '$C$6') !== false ? 'second' : 'value')] = $target['groupIds'][$g][1];
        }
        $this->assertSame(['value' => $target['valIds'][0], 'second' => $target['valIds'][1], 'target' => $target['valIds'][0]], array_merge(['value' => null, 'second' => null, 'target' => null], $axisOf), 'the line right, the target left');
    }

    /**
     * PhpSpreadsheet writes the chart groups of one type as one element with the type's settings
     * once per group, which Excel refuses to open: a line with its target line (no 複合), the 複合
     * type's line with the target's, its bars with the 値's — each setting once in every group.
     */
    public function testGroupsOfOneTypeAreWrittenAsExcelReadsThem(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $view = $this->comboView();
        $target = ['chart_target_value' => 100, 'chart_target_label' => '目標'];
        $cases = [
            'line + target' => ['line', $target, ['lineChart'], 2],
            '複合 line + target' => ['combo', $this->second($view) + $target, ['barChart', 'lineChart', 'lineChart'], 3],
            '複合 bars on 第2軸' => ['combo', array_merge($this->second($view), [ChartSecondValue::TYPE => 'bar']), ['barChart', 'barChart'], 2],
            '複合 bars + target' => ['combo', array_merge($this->second($view), [ChartSecondValue::TYPE => 'bar']) + $target, ['barChart', 'barChart', 'lineChart'], 3],
        ];
        foreach ($cases as $case => [$type, $options, $groups, $series]) {
            $xml = $this->chartXml($this->saveExcel($this->excelData($view, $type, $options)));
            $this->assertEqualsCanonicalizing($groups, $xml['groups'], $case);
            $this->assertSame($series, substr_count($xml['raw'], '<c:ser>'), "$case: every series");
            foreach ($xml['groupXml'] as $g => $group) {
                foreach (['grouping', 'barDir', 'varyColors'] as $setting) {
                    $this->assertLessThanOrEqual(1, substr_count($group, "<c:$setting "), "$case group $g: one $setting");
                }
            }
        }
    }

    /**
     * How a measure's numbers read: a count in 件, a currency with its symbol before or after, the
     * decimals of the column (an average 2 without); Excel's number format of it.
     */
    public function testTheUnits(): void
    {
        $column = function (string $type, array $options = []) {
            return (object) ['custom_column' => (object) ['column_type' => $type, 'options' => $options]];
        };
        $this->assertSame(['prefix' => '', 'suffix' => '件', 'digits' => 0], ChartSecondValue::unitOf($column('text'), SummaryCondition::COUNT));
        $this->assertSame(['prefix' => '¥', 'suffix' => '', 'digits' => null], ChartSecondValue::unitOf($column('currency', ['currency_symbol' => 'JPY1']), SummaryCondition::SUM));
        $this->assertSame(['prefix' => '', 'suffix' => '円', 'digits' => 1], ChartSecondValue::unitOf($column('currency', ['currency_symbol' => 'JPY2', 'decimal_digit' => '1']), SummaryCondition::MAX));
        $this->assertSame(['prefix' => '', 'suffix' => '', 'digits' => 2], ChartSecondValue::unitOf($column('integer'), SummaryCondition::AVG));
        $this->assertSame(['prefix' => '', 'suffix' => '', 'digits' => 3], ChartSecondValue::unitOf($column('decimal', ['decimal_digit' => 3]), SummaryCondition::AVG));
        $this->assertSame(['prefix' => '', 'suffix' => '', 'digits' => 0], ChartSecondValue::unitOf($column('integer'), SummaryCondition::SUM));

        $this->assertSame('#,##0"件"', ChartSecondValue::excelFormat(['prefix' => '', 'suffix' => '件', 'digits' => 0], []));
        $this->assertSame('"¥"#,##0.00', ChartSecondValue::excelFormat(['prefix' => '¥', 'suffix' => '', 'digits' => null], [1, 2.5, '3.25']));
        $this->assertSame('#,##0,,"百万""円"', ChartSecondValue::excelFormat(['prefix' => '', 'suffix' => '円', 'digits' => 0], [], true));
        $this->assertSame([[100000000, '億'], [10000, '万']], ChartSecondValue::compactUnits());
    }

    // Helpers ------------------------------------------------------------------------------------

    /**
     * parent_table's rows per text: SUM / COUNT / AVG (rounded as the view rounds it) of integer.
     *
     * @return array<string, array{sum: float, count: int, avg: float|null}>
     */
    private function rawSql(): array
    {
        $table = getDBTableName(CustomTable::getEloquent('parent_table'));
        $value = function (string $column) {
            return "NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(value, '$.$column')), 'null'), '')";
        };
        $sql = [];
        foreach (DB::select("SELECT {$value('text')} AS x, SUM(CAST({$value('integer')} AS DECIMAL(30, 10))) AS s, COUNT({$value('integer')}) AS n, ROUND(AVG(CAST({$value('integer')} AS DECIMAL(30, 10))), 2) AS a FROM $table WHERE deleted_at IS NULL GROUP BY x") as $row) {
            $sql[(string) $row->x] = ['sum' => (float) $row->s, 'count' => (int) $row->n, 'avg' => $row->a === null ? null : (float) $row->a];
        }
        return $sql;
    }

    /**
     * An aggregate view of parent_table per text: 合計 (SUM), 件数 (COUNT), 平均 (AVG) of integer.
     */
    private function comboView(): CustomView
    {
        System::clearCache();
        return $this->createCustomViewAll([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'text']],
            'summary_settings' => [
                ['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM, 'view_column_name' => '合計'],
                ['column_name' => 'integer', 'summary_condition' => SummaryCondition::COUNT, 'view_column_name' => '件数'],
                ['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG, 'view_column_name' => '平均'],
            ],
        ], ViewKindType::AGGREGATE)[1];
    }

    /**
     * The option value naming the view's summary column $name.
     */
    private function axisy(CustomView $view, string $name): string
    {
        $id = CustomViewSummary::where('custom_view_id', $view->id)->where('view_column_name', $name)->value('id');
        $this->assertNotNull($id, $name);
        return ViewKindType::AGGREGATE . '_' . $id;
    }

    /**
     * The 2つ目の値 options of a box of $view: its summary $name, a line on the right axis.
     *
     * @return array<string, string>
     */
    private function second(CustomView $view, string $name = '件数'): array
    {
        return [ChartSecondValue::COLUMN => $this->axisy($view, $name), ChartSecondValue::TYPE => 'line', ChartSecondValue::RIGHT => '1'];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function box(CustomView $view, string $type, array $options): DashboardBox
    {
        $box = new DashboardBox();
        $box->options = $options + [
            'target_table_id' => $view->custom_table_id,
            'target_view_id' => $view->id,
            'chart_type' => $type,
            'chart_axisx' => 'chartitem_label',
            'chart_axisy' => $this->axisy($view, '合計'),
            'chart_options' => [],
        ];
        return $box;
    }

    /**
     * A box of $view drawn as $type with the options $options and the request values $params.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $params
     * @return array{body: string, labels: array<int, mixed>, values: array<int, mixed>, values2: array<int, mixed>|null, combo: array<string, mixed>, target: mixed, detail: mixed}
     */
    private function render(CustomView $view, string $type, array $options, array $params = []): array
    {
        System::clearCache();
        $this->forgetParams();
        request()->merge($params);
        $body = (string) (new ChartItem($this->box($view, $type, $options)))->body();
        $this->forgetParams();
        return [
            'body' => $body,
            'labels' => (array) $this->scriptVar($body, 'labels', true),
            'values' => (array) $this->scriptVar($body, 'values', true),
            'values2' => is_array($v = $this->scriptVar($body, 'values2', false)) ? $v : null,
            'combo' => (array) $this->scriptVar($body, 'combo', false),
            'target' => $this->scriptVar($body, 'target', false),
            'detail' => $this->scriptVar($body, 'detail', false),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function excelData(CustomView $view, string $type, array $options, array $params = []): array
    {
        System::clearCache();
        $this->forgetParams();
        request()->merge($params);
        $data = (new ChartItem($this->box($view, $type, $options)))->excelData();
        $this->forgetParams();
        $this->assertIsArray($data);
        return $data;
    }

    /**
     * Which blade drew the chart: chartjs / echart_combo / echart.
     */
    private function bladeName(string $body): string
    {
        if (strpos($body, 'data-canvas-id') !== false) {
            return 'chartjs';
        }
        return strpos($body, 'var combo = ') !== false ? 'echart_combo' : 'echart';
    }

    /**
     * The chart of a box's body without its toolbar.
     */
    private function chartPart(string $body): string
    {
        if (preg_match('/<div>\s*<canvas data-canvas-id|<div data-echart-id/', $body, $m, PREG_OFFSET_CAPTURE) !== 1) {
            $this->fail('no chart');
        }
        return substr($body, $m[0][1]);
    }

    /**
     * The JSON a blade assigns to `var $name = …;` (an array mapped to numbers, too); null when the
     * page has none and it need not have one.
     *
     * @return mixed
     */
    private function scriptVar(string $body, string $name, bool $required)
    {
        if (preg_match('/var ' . preg_quote($name, '/') . ' = \(?(.*?)(?: \|\| \[\]\)\.map\(.*\))?;\s*$/m', $body, $m) !== 1) {
            $this->assertFalse($required, "var $name");
            return null;
        }
        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveExcel(array $data): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'chart_combo_test_');
        $this->files[] = $file;
        (new ChartExcel($data))->save($file);
        return $file;
    }

    private function read(string $file): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $reader = new XlsxReader();
        $reader->setIncludeCharts(true);
        return $reader->load($file)->getActiveSheet();
    }

    /**
     * The chart's XML of a workbook: its groups, their axis ids, the value axes (position, crosses),
     * their ids / titles / number formats, whether the category axes are hidden.
     *
     * @return array{raw: string, groupXml: string[], groups: string[], groupIds: array<int, string[]>, valAx: array<int, array{0: string|null, 1: string|null}>, valIds: array<int, string|null>, valTitles: array<int, string>, valFormats: array<int, string|null>, catDeleted: array<int, string|null>}
     */
    private function chartXml(string $file): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file));
        $raw = (string) $zip->getFromName(self::SECONDARY);
        $zip->close();
        $dom = new \DOMDocument();
        $dom->loadXML($raw);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('c', 'http://schemas.openxmlformats.org/drawingml/2006/chart');
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        // the elements $path finds (under $node)
        $elements = function (string $path, ?\DOMNode $node = null) use ($xpath): array {
            $found = $xpath->query($path, $node);
            return array_values(array_filter($found === false ? [] : iterator_to_array($found), function ($item) {
                return $item instanceof \DOMElement;
            }));
        };
        $val = function (\DOMElement $node, string $path) use ($elements): ?string {
            $first = $elements($path, $node)[0] ?? null;
            return $first instanceof \DOMElement ? $first->getAttribute('val') : null;
        };
        $out = ['raw' => $raw, 'groupXml' => [], 'groups' => [], 'groupIds' => [], 'valAx' => [], 'valIds' => [], 'valTitles' => [], 'valFormats' => [], 'catDeleted' => []];
        foreach ($elements('//c:plotArea/c:barChart|//c:plotArea/c:lineChart') as $group) {
            $out['groups'][] = $group->localName;
            $out['groupXml'][] = (string) $dom->saveXML($group);
            $out['groupIds'][] = array_map(function (\DOMElement $id) {
                return $id->getAttribute('val');
            }, $elements('c:axId', $group));
        }
        foreach ($elements('//c:plotArea/c:valAx') as $axis) {
            $out['valAx'][] = [$val($axis, 'c:axPos'), $val($axis, 'c:crosses') === 'max' ? 'max' : null];
            $out['valIds'][] = $val($axis, 'c:axId');
            $title = $elements('c:title//a:t', $axis)[0] ?? null;
            $out['valTitles'][] = $title === null ? '' : trim((string) $title->textContent);
            $format = $elements('c:numFmt', $axis)[0] ?? null;
            $out['valFormats'][] = $format === null ? null : $format->getAttribute('formatCode');
        }
        foreach ($elements('//c:plotArea/c:catAx') as $axis) {
            $out['catDeleted'][] = $val($axis, 'c:delete');
        }
        return $out;
    }

    private function forgetParams(): void
    {
        foreach (self::PARAMS as $param) {
            request()->offsetUnset($param);
        }
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
        $dashboard->dashboard_name = 'combo_' . uniqid();
        $dashboard->dashboard_view_name = 'combo';
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();
        return $dashboard;
    }

    /**
     * @param array<string, mixed> $fields  the posted 2つ目の値 fields
     * @return array<string, mixed>
     */
    private function boxForm(Dashboard $dashboard, CustomView $view, string $type, array $fields): array
    {
        return [
            'dashboard_id' => $dashboard->id,
            'dashboard_box_type' => DashboardBoxType::CHART,
            'row_no' => 1,
            'column_no' => 1,
            'dashboard_box_view_name' => 'combo chart',
            'options' => [
                'chart_type' => $type,
                'target_table_id' => $view->custom_table_id,
                'target_view_id' => $view->id,
                'chart_axisx' => 'chartitem_label',
                'chart_axisy' => $this->axisy($view, '合計'),
            ] + $fields,
        ];
    }

    private function url(string $path): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/' . $path;
    }
}
