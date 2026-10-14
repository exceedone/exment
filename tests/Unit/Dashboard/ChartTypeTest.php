<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;

class ChartTypeTest extends DashboardUnitTestCase
{
    public function testRendererOfType()
    {
        $this->assertFalse(ChartType::isEcharts('bar'));
        $this->assertFalse(ChartType::isEcharts('pie'));
        $this->assertFalse(ChartType::isEcharts(null), 'legacy / unknown renders as Chart.js');
        $this->assertFalse(ChartType::isEcharts('xyz'));
        $this->assertTrue(ChartType::isEcharts('doughnut'));
        $this->assertFalse(ChartType::isEcharts('heatmap'), 'multi-series types are their own renderer');
        $this->assertTrue(ChartType::isMulti('heatmap'));
    }

    public function testPointPickOnlyWherePointsMapToValues()
    {
        foreach (['bar', 'line', 'pie', 'hbar', 'area', 'doughnut', 'funnel', 'scatter', 'mbar', 'sbar', 'mline', 'sarea', 'heatmap'] as $type) {
            $this->assertTrue(ChartType::supportsPointPick($type), $type);
        }
        foreach (['radar', 'gauge', 'treemap', 'sunburst', 'boxplot', null, 'xyz'] as $type) {
            $this->assertFalse(ChartType::supportsPointPick($type), (string) $type);
        }
    }

    public function testOnlyChartTypesAreEnumValues()
    {
        $this->assertCount(18, ChartType::arrays(), 'every constant becomes a form option — no helper constants allowed');
    }

    public function testSwitchPoolStaysWithinDatasetShape()
    {
        $single = ChartType::switchPool('bar');
        $this->assertCount(10, $single);
        $this->assertContains('doughnut', $single);
        $this->assertNotContains('mbar', $single);
        $this->assertSame($single, ChartType::switchPool('gauge'));
        $this->assertSame($single, ChartType::switchPool(null));

        $multi = ChartType::switchPool('mline');
        $this->assertCount(8, $multi);
        $this->assertNotContains('bar', $multi);
    }

    public function testResolve()
    {
        $this->assertSame('pie', ChartType::resolve('bar', 'pie'));
        $this->assertSame('bar', ChartType::resolve('bar', 'heatmap'), 'cross-shape switch is refused');
        $this->assertSame('bar', ChartType::resolve('bar', 'xyz'));
        $this->assertSame('bar', ChartType::resolve('bar', null));
        $this->assertSame('bar', ChartType::resolve('bar', ['pie']));
        $this->assertSame('sbar', ChartType::resolve('mline', 'sbar'));
        $this->assertSame('mline', ChartType::resolve('mline', 'bar'));
    }

    public function testAxisOptionsBelongToTheChartJsAxisTypes()
    {
        $this->assertSame(['bar', 'line'], ChartType::axisOptionTypes());

        // the box form sanitizer (ChartItem::saving) stores the axis options and "begin at
        // zero" on those types only, the legend on the types that draw one, nothing elsewhere
        $saved = function (string $type) {
            $form = new \stdClass();
            $form->options = ['chart_type' => $type, 'chart_axis_label' => ['1', '2'], 'chart_axis_name' => ['1'], 'chart_options' => ['1', '2']];
            ChartItem::saving($form);
            return $form->options;
        };
        $bar = $saved('bar');
        $this->assertSame(['1', '2'], $bar['chart_axis_label']);
        $this->assertSame(['1'], $bar['chart_axis_name']);
        $this->assertSame(['2'], $bar['chart_options'], 'begin at zero, not the legend');

        $pie = $saved('pie');
        $this->assertSame([], $pie['chart_axis_label'], 'no axes on a circular type');
        $this->assertSame(['1'], $pie['chart_options'], 'the legend');

        $hbar = $saved('hbar');
        $this->assertSame([], $hbar['chart_axis_label'], 'ECharts lays its axes out itself: hidden on the form, not stored');
        $this->assertSame([], $hbar['chart_axis_name']);
        $this->assertSame([], $hbar['chart_options']);

        $this->assertSame(['1'], $saved('mbar')['chart_options'], 'a multi-series type keeps its legend');
    }

    public function testSwitchedTypeRendersWithItsOwnAxisDefaults()
    {
        // ChartItem's constructor: a runtime switch (`ct`) keeps the data and gives the switched
        // type its own flags. A box configured as a type that stores no axis options (every type
        // but bar / line, see saving above) would hand a switched-to bar / line empty arrays —
        // Chart.js then hides every tick — so such a switch draws both axes' labels and names.
        $flags = function (array $options, array $request) {
            $this->swapRequest($request);
            return new class ($this->makeBox('b1', $options)) extends ChartItem {
                /** @return array{label: string[], name: string[], options: string[]} */
                public function flags(): array
                {
                    return ['label' => $this->chart_axis_label, 'name' => $this->chart_axis_name, 'options' => $this->chart_options];
                }
            };
        };
        $hbar = ['chart_type' => 'hbar', 'chart_axis_label' => [], 'chart_axis_name' => [], 'chart_options' => []];
        $this->assertSame(['label' => ['1', '2'], 'name' => ['1', '2'], 'options' => ['2']], $flags($hbar, ['ct' => 'bar'])->flags(), 'an ECharts box switched to bar: both axes labelled and named, begin at zero');
        $this->assertSame(['label' => [], 'name' => [], 'options' => []], $flags($hbar, [])->flags(), 'not switched: as stored');
        $this->assertSame(['label' => [], 'name' => [], 'options' => ['1']], $flags($hbar, ['ct' => 'pie'])->flags(), 'switched to a circular type: no axes to default, the legend');

        $bar = ['chart_type' => 'bar', 'chart_axis_label' => ['1'], 'chart_axis_name' => [], 'chart_options' => ['2']];
        $this->assertSame(['label' => ['1'], 'name' => [], 'options' => ['2']], $flags($bar, ['ct' => 'line'])->flags(), "bar to line: the stored axis options are the editor's choice, kept");
        $this->assertSame(['label' => ['1'], 'name' => [], 'options' => []], $flags($bar, ['ct' => 'hbar'])->flags(), 'bar to an ECharts type: the stored axis options stay (nothing reads them there), the flag is reset to that type\'s own (none)');

        $pie = ['chart_type' => 'pie', 'chart_axis_label' => [], 'chart_axis_name' => [], 'chart_options' => ['1']];
        $this->assertSame(['label' => ['1', '2'], 'name' => ['1', '2'], 'options' => ['2']], $flags($pie, ['ct' => 'bar'])->flags(), 'a pie switched to bar draws its axes too');
    }

    public function testLegendAndCircular()
    {
        $this->assertTrue(ChartType::isCircular('pie'));
        $this->assertTrue(ChartType::isCircular('radar'));
        $this->assertFalse(ChartType::isCircular('bar'));
        $this->assertContains('sbar', ChartType::legendTypes());
        $this->assertContains('doughnut', ChartType::legendTypes());
        $this->assertNotContains('line', ChartType::legendTypes());
        $this->assertTrue(ChartType::isMulti('treemap'));
        $this->assertFalse(ChartType::isMulti('area'));
    }
}
