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
use Exceedone\Exment\Services\Dashboard\ChartShowAs;
use Exceedone\Exment\Services\Dashboard\ChartSummarySwitch;
use Exceedone\Exment\Services\Dashboard\ChartTarget;
use Exceedone\Exment\Services\Dashboard\ChartTopN;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

/**
 * The target line of a chart box (目標線, as Excel's combo chart with a column of the target): the
 * line on the types it is drawn on, the bars of a single-series bar / horizontal bar under it in
 * its color — checked against bars worked out by hand, on numbers made up and on the table's own
 * rows by raw SQL —, hidden while the chart draws other numbers, the form's save and validation,
 * the Excel download, and that a box without a target draws as before.
 */
class ChartTargetTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    private const PARAMS = [ChartShowAs::PARAM, ChartSummarySwitch::PARAM, ChartTopN::PARAM, 'cs', 'ct', 'options'];

    private const RED = '#f8696b';

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

    // The target ---------------------------------------------------------------------------------

    /**
     * Under the target: a number less than it — one equal to it reaches it; "59.99" as the
     * database gives a summarized value; an empty point, a text, and その他 never.
     */
    public function testTheBarsUnderItByHand(): void
    {
        $target = $this->target(['chart_target_value' => '60', 'chart_target_below' => '1']);
        $values = [59.99, 60, '60.00', '59.99', 61, null, '', 'abc', -5, '45.64', 0];
        $this->assertSame([0, 3, 8, 9, 10], $target->belowPoints($values));
        $this->assertSame([0, 3, 9, 10], $target->belowPoints($values, 8), 'その他 (8) is no bar of its own');

        $target = $this->target(['chart_target_value' => '-2.5']);
        $this->assertSame([1], $target->belowPoints([-2.5, -2.51, 0]));

        // the payload: the bars only when asked for and handed values
        $payload = $this->target(['chart_target_value' => '1000.5', 'chart_target_label' => ' 合格ライン ', 'chart_target_below' => '1'])->payload([1000, 1001]);
        $this->assertSame(['value' => 1000.5, 'name' => '合格ライン', 'text' => '1,000.5', 'diff' => '（差 {d}）', 'color' => self::RED, 'below' => [0]], $payload);
        $this->assertSame([], $this->target(['chart_target_value' => '60'])->payload([1, 2])['below'], 'not asked');
        $this->assertSame([], $this->target(['chart_target_value' => '60', 'chart_target_below' => '1'])->payload(null)['below'], 'no values');
        $this->assertSame('目標', $this->target(['chart_target_value' => '60'])->name(), 'no label: 目標');
    }

    /**
     * What the form may post, field by field; the options a save stores (a number, the name
     * trimmed, the color in lower case — 赤 when none —, the bars on a bar / horizontal bar
     * only), none on a type the line is not drawn on or without a value.
     */
    public function testErrorsAndTheSavedOptions(): void
    {
        $this->assertSame([], ChartTarget::errors([]));
        $this->assertSame([], ChartTarget::errors(['chart_target_value' => ' 59.5 ', 'chart_target_label' => str_repeat('あ', ChartTarget::LABEL_MAX), 'chart_target_color' => '#ABCDEF']));
        $this->assertSame([], ChartTarget::errors(['chart_target_value' => '', 'chart_target_color' => '']));
        $cases = [
            [['chart_target_value' => 'abc'], ['chart_target_value']],
            [['chart_target_value' => '1e400'], ['chart_target_value']],
            [['chart_target_value' => '60', 'chart_target_label' => str_repeat('あ', ChartTarget::LABEL_MAX + 1)], ['chart_target_label']],
            [['chart_target_value' => '60', 'chart_target_label' => "a\nb"], ['chart_target_label']],
            [['chart_target_value' => '60', 'chart_target_label' => ['x']], ['chart_target_label']],
            [['chart_target_value' => '', 'chart_target_label' => '合格ライン'], ['chart_target_value']],
            [['chart_target_value' => '60', 'chart_target_color' => 'red'], ['chart_target_color']],
            [['chart_target_value' => '60', 'chart_target_color' => '#ffffff" onmouseover="alert(1)'], ['chart_target_color']],
        ];
        foreach ($cases as $i => [$options, $keys]) {
            $this->assertSame($keys, array_keys(ChartTarget::errors($options)), "case $i");
            $this->assertNull(ChartTarget::fromOptions($options), "case $i: no target");
        }

        $posted = ['chart_type' => 'bar', 'chart_target_value' => ' 60 ', 'chart_target_label' => ' 合格ライン ', 'chart_target_color' => '#F8696B', 'chart_target_below' => '1', 'other' => 'x'];
        $this->assertSame(['chart_type' => 'bar', 'other' => 'x', 'chart_target_value' => 60, 'chart_target_label' => '合格ライン', 'chart_target_color' => self::RED, 'chart_target_below' => '1'], ChartTarget::saved($posted));
        $this->assertSame(['chart_type' => 'line', 'other' => 'x', 'chart_target_value' => 60, 'chart_target_label' => '合格ライン', 'chart_target_color' => self::RED], ChartTarget::saved(['chart_type' => 'line'] + $posted), 'line: no bars');
        $this->assertSame(['chart_type' => 'hbar', 'chart_target_value' => 59.5, 'chart_target_color' => self::RED, 'chart_target_below' => '1'], ChartTarget::saved(['chart_type' => 'hbar', 'chart_target_value' => '59.5', 'chart_target_label' => '', 'chart_target_color' => '', 'chart_target_below' => '1']), '赤 by default');
        foreach (['pie', 'doughnut', 'funnel', 'radar', 'gauge', 'scatter', 'heatmap', 'sbar100', 'shbar100', 'treemap', 'sunburst', 'boxplot'] as $type) {
            $this->assertSame(['chart_type' => $type, 'other' => 'x'], ChartTarget::saved(['chart_type' => $type] + $posted), $type);
        }
        $this->assertSame(['chart_type' => 'bar'], ChartTarget::saved(['chart_type' => 'bar', 'chart_target_value' => '', 'chart_target_color' => self::RED, 'chart_target_below' => '1']), 'no value');
    }

    // The chart -----------------------------------------------------------------------------------

    /**
     * parent_table's own rows against raw SQL: AVG(integer) per text, rounded as the view rounds it
     * (2 decimals); the target the median, so some bars are under it — those, worked out by hand
     * from the SQL values, are the bars drawn in the line's color; on the horizontal bar too, on
     * the line none (no bars). Sorted, they follow their values.
     */
    public function testTheBarsAgainstRawSql(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $table = getDBTableName(CustomTable::getEloquent('parent_table'));
        $value = function (string $column) {
            return "NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(value, '$.$column')), 'null'), '')";
        };
        $sql = [];
        foreach (DB::select("SELECT {$value('text')} AS x, ROUND(AVG(CAST({$value('integer')} AS DECIMAL(30, 10))), 2) AS y FROM $table WHERE deleted_at IS NULL GROUP BY x") as $row) {
            $sql[(string) $row->x] = $row->y === null ? null : (float) $row->y;
        }
        $numbers = array_values(array_filter($sql, 'is_float'));
        sort($numbers);
        $this->assertGreaterThan(5, count($numbers));
        $target = $numbers[intdiv(count($numbers), 2)];
        $under = array_keys(array_filter($sql, function ($v) use ($target) {
            return $v !== null && $v < $target;
        }));
        $this->assertNotEmpty($under);

        $view = $this->aggregateView(['text'], SummaryCondition::AVG);
        $options = ['chart_target_value' => $target, 'chart_target_label' => '合格ライン', 'chart_target_below' => '1'];
        foreach (['bar', 'hbar'] as $type) {
            $render = $this->render($view, $type, $options);
            $this->assertNotNull($render['target'], $type);
            $this->assertEquals($target, $render['target']['value'], $type);
            $labels = $render['labels'];
            $this->assertEqualsCanonicalizing(array_keys($sql), array_map('strval', $labels), "$type: a bar per text");
            foreach ($labels as $i => $label) {
                $this->assertEqualsWithDelta($sql[(string) $label], (float) $render['values'][$i], 1e-9, "$type $label");
            }
            $drawn = array_map(function ($i) use ($labels) {
                return (string) $labels[$i];
            }, $render['target']['below']);
            $this->assertEqualsCanonicalizing($under, $drawn, "$type: the bars under {$target}");
        }

        $sorted = $this->render($view, 'bar', $options, ['cs' => 'y:asc']);
        $this->assertSame(range(0, count($under) - 1), $sorted['target']['below'], 'sorted ascending: the first bars');

        $line = $this->render($view, 'line', $options);
        $this->assertSame([], $line['target']['below'], 'a line has no bars');
        $this->assertSame('合格ライン', $line['target']['name']);
    }

    /**
     * The line is drawn on the types it is meant for, in the units of the view's own numbers:
     * a switched 集計方法 or a 表示方法 hides it, the view's own 集計方法 picked again shows it;
     * switched on the toolbar to a type it is not drawn on, none; back, the line again.
     */
    public function testWhenTheLineIsDrawn(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $single = $this->aggregateView(['text'], SummaryCondition::AVG);
        $multi = $this->aggregateView(['text', 'odd_even'], SummaryCondition::SUM);
        // above every bar: each one is under it
        $options = ['chart_target_value' => 1e9, 'chart_target_below' => '1'];

        foreach (['bar', 'line', 'hbar', 'area'] as $type) {
            $this->assertNotNull($this->render($single, $type, $options)['target'], $type);
        }
        foreach (['pie', 'doughnut', 'funnel', 'radar', 'gauge', 'scatter'] as $type) {
            $this->assertNull($this->render($single, 'bar', $options, ['ct' => $type])['target'], "switched to $type");
        }
        $back = $this->render($single, 'bar', $options, ['ct' => 'hbar']);
        $this->assertNotEmpty($back['target']['below'], 'switched to hbar: the bars under it too');

        $this->assertNull($this->render($single, 'bar', $options, [ChartSummarySwitch::PARAM => SummaryCondition::COUNT])['target'], '件数');
        $this->assertNotNull($this->render($single, 'bar', $options, [ChartSummarySwitch::PARAM => SummaryCondition::AVG])['target'], "平均: the view's own");
        $this->assertNull($this->render($single, 'bar', $options, [ChartShowAs::PARAM => ChartShowAs::DIFFERENCE])['target'], '表示方法');

        foreach (['mbar', 'sbar', 'mline', 'sarea'] as $type) {
            $render = $this->render($multi, $type, $options);
            $this->assertNotNull($render['target'], $type);
            $this->assertSame([], $render['target']['below'], "$type: series keep their colors");
        }
        foreach (['sbar100', 'shbar100', 'heatmap', 'treemap', 'sunburst', 'boxplot'] as $type) {
            $this->assertNull($this->render($multi, 'mbar', $options, ['ct' => $type])['target'], "switched to $type");
        }
        $this->assertNull($this->render($multi, 'sbar', $options, [ChartShowAs::PARAM => ChartShowAs::PERCENT_OF_TOTAL])['target'], 'multi 表示方法');
    }

    /**
     * Cut to its top N with その他: its bar is no bar under the target, whatever its value.
     */
    public function testTheRestIsNoBarUnderIt(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $view = $this->aggregateView(['text'], SummaryCondition::SUM);
        $render = $this->render($view, 'bar', ['chart_target_value' => 1e9, 'chart_target_below' => '1'], [ChartTopN::PARAM => '5:other']);
        $this->assertCount(6, $render['values'], 'top 5 + その他');
        $this->assertSame([0, 1, 2, 3, 4], $render['target']['below'], 'every bar under 1e9 but その他');
    }

    /**
     * No target, or none that is one: the boxes draw as before — nothing of a line in the page but
     * `var target = null`, the same bars, colors and axis options.
     */
    public function testABoxWithoutATarget(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $single = $this->aggregateView(['text'], SummaryCondition::AVG);
        $multi = $this->aggregateView(['text', 'odd_even'], SummaryCondition::SUM);
        $bad = ['chart_target_value' => '60', 'chart_target_color' => '#ffffff" onmouseover="alert(1)'];
        foreach ([[], ['chart_target_value' => ''], $bad, ['chart_target_value' => 'abc', 'chart_target_below' => '1']] as $i => $options) {
            foreach ([[$single, 'bar'], [$single, 'line'], [$single, 'hbar'], [$single, 'area'], [$multi, 'mbar'], [$multi, 'sbar']] as [$view, $type]) {
                $render = $this->render($view, $type, $options);
                $plain = $this->render($view, $type, []);
                $this->assertNull($render['target'], "case $i $type");
                $this->assertSame($plain['body'], $render['body'], "case $i $type: as without one");
                $this->assertStringNotContainsString('onmouseover', $render['body']);
            }
        }
    }

    /**
     * The label is written into the page's script hex-encoded: a tag in it is never markup.
     */
    public function testTheLabelIsNoMarkup(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $view = $this->aggregateView(['text'], SummaryCondition::AVG);
        $label = '<img onerror=x>"\'</script>';
        $render = $this->render($view, 'hbar', ['chart_target_value' => 5, 'chart_target_label' => $label]);
        $this->assertSame($label, $render['target']['name']);
        $this->assertStringNotContainsString('<img', $render['body']);
        $this->assertStringNotContainsString('</script>"', $render['body']);
    }

    // The box form ---------------------------------------------------------------------------------

    /**
     * Saved with the box's options, the painted colors kept; the edit form shows it; saved as a
     * type it is not drawn on, or without a value, none. Errors on their fields; nothing saved.
     */
    public function testTheBoxFormSavesAndValidates(): void
    {
        $this->login();
        $dashboard = $this->dashboard();
        $view = $this->aggregateView(['text'], SummaryCondition::AVG);
        $target = ['chart_target_value' => '59.5', 'chart_target_label' => ' 合格ライン ', 'chart_target_color' => '#F8696B', 'chart_target_below' => '1'];
        $this->post($this->url('dashboardbox'), $this->boxForm($dashboard, $view, 'bar', $target))->assertSessionHasNoErrors();
        $box = DashboardBox::where('dashboard_id', $dashboard->id)->first();
        $this->assertNotNull($box);
        $stored = ['chart_target_value' => 59.5, 'chart_target_label' => '合格ライン', 'chart_target_color' => self::RED, 'chart_target_below' => '1'];
        $this->assertSame($stored, array_intersect_key($box->options, $stored));
        $this->assertSame('bar', $box->options['chart_type']);

        $html = $this->get($this->url("dashboardbox/{$box->id}/edit"))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/name="options\[chart_target_value\]"[^>]*value="59.5"|value="59.5"[^>]*name="options\[chart_target_value\]"/', $html);
        $this->assertStringContainsString(exmtrans('dashboard.chart_target.header'), $html);
        $this->assertStringContainsString('"single":"input.options_chart_target_color"', $html);

        // painted colors kept; a line: no bars; a pie: none; no value: none
        $options = $box->options;
        $options['chart_colors'] = ['points' => ['a' => '#123456']];
        DB::table('dashboard_boxes')->where('id', $box->id)->update(['options' => json_encode($options)]);
        $this->put($this->url("dashboardbox/{$box->id}"), $this->boxForm($dashboard, $view, 'line', $target))->assertSessionHasNoErrors();
        $options = (array) DashboardBox::where('id', $box->id)->first()?->options;
        $this->assertSame(59.5, $options['chart_target_value']);
        $this->assertArrayNotHasKey('chart_target_below', $options);
        $this->assertSame(['points' => ['a' => '#123456']], $options['chart_colors']);
        $this->put($this->url("dashboardbox/{$box->id}"), $this->boxForm($dashboard, $view, 'pie', $target + ['chart_target_color' => 'not a color']))->assertSessionHasNoErrors();
        $this->assertSame([], array_intersect_key((array) DashboardBox::where('id', $box->id)->first()?->options, $stored), 'pie: none, not checked');
        $this->put($this->url("dashboardbox/{$box->id}"), $this->boxForm($dashboard, $view, 'bar', ['chart_target_value' => '', 'chart_target_below' => '1']))->assertSessionHasNoErrors();
        $this->assertSame([], array_intersect_key((array) DashboardBox::where('id', $box->id)->first()?->options, $stored), 'no value: none');

        // errors on their fields, as the form's others
        $bad = ['chart_target_value' => 'abc', 'chart_target_label' => str_repeat('あ', ChartTarget::LABEL_MAX + 1), 'chart_target_color' => 'red'];
        $this->put($this->url("dashboardbox/{$box->id}"), $this->boxForm($dashboard, $view, 'bar', $bad))
            ->assertSessionHasErrors(['options.chart_target_value', 'options.chart_target_label', 'options.chart_target_color']);
        $this->post($this->url('dashboardbox'), $this->boxForm($dashboard, $view, 'hbar', ['chart_target_value' => '', 'chart_target_label' => '合格ライン']))
            ->assertSessionHasErrors(['options.chart_target_value']);
        $this->assertSame(1, DashboardBox::where('dashboard_id', $dashboard->id)->count(), 'nothing saved');
        $this->assertSame([], array_intersect_key((array) DashboardBox::where('id', $box->id)->first()?->options, $stored));
    }

    /**
     * A dashboard template carries the target: exported with the box's options, imported with them.
     */
    public function testTheTemplateKeepsIt(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $view = $this->aggregateView(['text'], SummaryCondition::AVG);
        $target = ['chart_target_value' => 59.5, 'chart_target_label' => '合格ライン', 'chart_target_color' => self::RED, 'chart_target_below' => '1'];
        $dashboard = $this->dashboard();
        $box = $this->box($view, 'bar', $target);
        $box->dashboard_id = $dashboard->id;
        $box->row_no = 1;
        $box->column_no = 1;
        $box->dashboard_box_view_name = 'template';
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->save();

        $exported = $box->getTemplateExportItems();
        $other = $this->dashboard();
        DashboardBox::importTemplate($exported, false, ['parent' => $other]);
        $imported = DashboardBox::where('dashboard_id', $other->id)->first();
        $this->assertNotNull($imported);
        $this->assertSame($target, array_intersect_key($imported->options, $target));
    }

    // Excel --------------------------------------------------------------------------------------

    /**
     * The Excel download: the target as a column under its name, a dashed line of it over the bars
     * (a combo chart), the bars under it in its color; on a horizontal bar the column only and a
     * note; none while hidden.
     */
    public function testTheExcelDownload(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $view = $this->aggregateView(['text'], SummaryCondition::AVG);
        // the median of the bars: some under it
        $values = array_map('floatval', $this->render($view, 'bar', [])['values']);
        sort($values);
        $median = $values[intdiv(count($values), 2)];
        $options = ['chart_target_value' => $median, 'chart_target_label' => '合格ライン', 'chart_target_below' => '1'];

        $render = $this->render($view, 'bar', $options);
        $this->assertNotEmpty($render['target']['below']);
        $data = $this->excelData($view, 'bar', $options);
        $this->assertEquals(['value' => $median, 'name' => '合格ライン', 'color' => self::RED], $data['target']);
        foreach ($data['point_colors'] as $i => $color) {
            $this->assertSame(in_array($i, $render['target']['below'], true), $color === self::RED, "point $i");
        }
        $sheet = $this->sheet($data);
        $count = count($data['categories']);
        $this->assertSame('合格ライン', $sheet->getCell([3, ChartExcel::HEADER_ROW])->getValue());
        for ($r = 1; $r <= $count; $r++) {
            $this->assertEquals($median, $sheet->getCell([3, ChartExcel::HEADER_ROW + $r])->getValue());
        }
        $groups = $sheet->getChartCollection()[0]->getPlotArea()?->getPlotGroup() ?? [];
        $this->assertCount(2, $groups, 'bars + the line');
        $this->assertSame(DataSeries::TYPE_BARCHART, $groups[0]->getPlotType());
        $this->assertSame(DataSeries::TYPE_LINECHART, $groups[1]->getPlotType());
        $line = array_values($groups[1]->getPlotValues())[0];
        $this->assertStringContainsString('$C$' . (ChartExcel::HEADER_ROW + 1), (string) $line->getDataSource());
        $this->assertSame('dash', $line->getLineStyleProperty('dash'));

        $hbar = $this->sheet($this->excelData($view, 'hbar', $options));
        $this->assertCount(1, $hbar->getChartCollection()[0]->getPlotArea()?->getPlotGroup() ?? []);
        $this->assertSame('合格ライン', $hbar->getCell([3, ChartExcel::HEADER_ROW])->getValue());
        $this->assertSame(exmtrans('dashboard.chart_excel.target_column_only', '合格ライン'), $hbar->getCell('A4')->getValue());

        $hidden = $this->excelData($view, 'bar', $options, [ChartSummarySwitch::PARAM => SummaryCondition::COUNT]);
        $this->assertNull($hidden['target']);
        $this->assertNull($this->sheet($hidden)->getCell([3, ChartExcel::HEADER_ROW])->getValue(), 'no column');
    }

    // Helpers ------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $options
     */
    private function target(array $options): ChartTarget
    {
        $target = ChartTarget::fromOptions($options);
        $this->assertNotNull($target);
        return $target;
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
     * A box of $view drawn as $type with the target options $options and the request values $params.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $params
     * @return array{body: string, target: mixed, labels: mixed, values: mixed}
     */
    private function render(CustomView $view, string $type, array $options, array $params = []): array
    {
        System::clearCache();
        $this->forgetParams();
        request()->merge($params);
        $body = (string) (new ChartItem($this->box($view, $type, $options)))->body();
        $this->forgetParams();
        $target = preg_match('/var target = (.*?);\s*$/m', $body, $m) === 1 ? $m[1] : '';
        $this->assertNotSame('', $target, 'var target');
        $multi = in_array($type, ['mbar', 'sbar', 'mline', 'sarea', 'sbar100', 'shbar100', 'heatmap', 'treemap', 'sunburst', 'boxplot'], true);
        return [
            'body' => $body,
            'target' => json_decode($target, true, 512, JSON_THROW_ON_ERROR),
            'labels' => $multi ? null : $this->scriptJson($body, 'labels'),
            'values' => $multi ? null : $this->scriptJson($body, 'values'),
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
     * The sheet of the workbook ChartExcel writes for $data, read back.
     *
     * @param array<string, mixed> $data
     */
    private function sheet(array $data): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'chart_target_test_');
        $this->files[] = $file;
        (new ChartExcel($data))->save($file);
        $reader = new XlsxReader();
        $reader->setIncludeCharts(true);
        return $reader->load($file)->getActiveSheet();
    }

    /**
     * @param array<string, mixed> $target
     */
    private function box(CustomView $view, string $type, array $target): DashboardBox
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
            'chart_options' => [],
        ] + (count($columns) > 1 ? ['chart_series' => ViewKindType::DEFAULT . '_' . $columns[1]->id] : []) + $target;
        return $box;
    }

    private function forgetParams(): void
    {
        foreach (self::PARAMS as $param) {
            request()->offsetUnset($param);
        }
    }

    /**
     * The JSON array a chart blade assigns to `var $name = …;` (the ECharts single-series values:
     * the array mapped to numbers).
     *
     * @return mixed
     */
    private function scriptJson(string $body, string $name)
    {
        $json = preg_match('/var ' . preg_quote($name, '/') . ' = \(?(\[.*?\])(?: \|\| \[\]\)\.map\(.*\))?;\s*$/m', $body, $m) === 1 ? $m[1] : '';
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
        $dashboard->dashboard_name = 'target_' . uniqid();
        $dashboard->dashboard_view_name = 'target';
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();
        return $dashboard;
    }

    /**
     * @param array<string, string> $target  the posted 目標線 fields
     * @return array<string, mixed>
     */
    private function boxForm(Dashboard $dashboard, CustomView $view, string $type, array $target): array
    {
        $summary = CustomViewSummary::where('custom_view_id', $view->id)->first();
        $this->assertNotNull($summary);
        return [
            'dashboard_id' => $dashboard->id,
            'dashboard_box_type' => DashboardBoxType::CHART,
            'row_no' => 1,
            'column_no' => 1,
            'dashboard_box_view_name' => 'target chart',
            'options' => [
                'chart_type' => $type,
                'target_table_id' => $view->custom_table_id,
                'target_view_id' => $view->id,
                'chart_axisx' => 'chartitem_label',
                'chart_axisy' => ViewKindType::AGGREGATE . '_' . $summary->id,
            ] + $target,
        ];
    }

    private function url(string $path): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/' . $path;
    }
}
