<?php

namespace Exceedone\Exment\Tests\Unit;

/**
 * The bar / line / pie boxes of the dashboard are drawn by Chart.js 4 (they were on 2.9.4).
 * Chart.js 4 ignores the 2.x option names without an error (xAxes, scaleLabel, tooltips...) and
 * dropped the 2.x element fields (_model, _index), so a half-ported script still draws a chart,
 * only without its axis names, tooltips or value labels.
 *
 * The drawing is javascript the test runner does not execute; this reads the script and the
 * library. The script itself was run with node against Chart.js 4 on a fake canvas (bar, line,
 * pie, every option on and off).
 */
class ChartJs4Test extends UnitTestBase
{
    protected function script(): string
    {
        return (string) file_get_contents(exment_package_path('resources/views/dashboard/chart/chart.blade.php'));
    }

    public function testTheLibraryIsChartJs4(): void
    {
        $lib = (string) file_get_contents(exment_package_path('public/vendor/exment/chartjs/chart.min.js'));
        $this->assertMatchesRegularExpression('/^\/\*!\s*\r?\n \* Chart\.js v4\.\d+\.\d+\r?\n/', $lib);
        $this->assertStringContainsString('Released under the MIT License', substr($lib, 0, 300));
    }

    public function testNoChartJs2OptionsOrFieldsRemain(): void
    {
        $script = $this->script();
        foreach (['xAxes', 'yAxes', 'scaleLabel', 'labelString', 'tooltips', 'lineTension', '_model', '_index',
            'getElementAtEvent(', 'chart.chart.', 'hover: {', 'Chart.defaults.global'] as $v2) {
            $this->assertStringNotContainsString($v2, $script, $v2);
        }
    }

    public function testTheChartJs4OptionsAreUsed(): void
    {
        $script = $this->script();
        // axes: one object per axis, the name in `title`, begin at zero on the scale
        $this->assertStringContainsString('scales: {', $script);
        $this->assertStringContainsString('x: {', $script);
        $this->assertStringContainsString('y: {', $script);
        $this->assertStringContainsString("beginAtZero: {{ \$chart_begin_zero ? 'true' : 'false' }},", $script);
        $this->assertStringContainsString('text: @json($chart_axisx)', $script);
        $this->assertStringContainsString('text: @json($chart_axisy)', $script);
        // legend and tooltip under plugins, the tooltip label from the 4.x context
        $this->assertStringContainsString('plugins: {', $script);
        $this->assertStringContainsString('tooltip: { callbacks: { label: function (context) {', $script);
        $this->assertStringContainsString('context.raw', $script);
        $this->assertStringContainsString('tension: 0', $script);
        // the value labels: the chart's own context, the element's final position
        $this->assertStringContainsString("id: 'exmentValueLabels'", $script);
        $this->assertStringContainsString('var c = chart.ctx,', $script);
        $this->assertStringContainsString("el.getProps(['x', 'y'], true)", $script);
        $this->assertStringContainsString('chart.getDataVisibility(i)', $script);
        // click, hover and the color menu on the 4.x element index and native event
        $this->assertStringContainsString('click.values[elements[0].index]', $script);
        $this->assertStringContainsString('onHover: function (evt, elements, chart)', $script);
        $this->assertStringContainsString("getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true)", $script);
        $this->assertStringContainsString('labels[el.index]', $script);
        // a responsive 4.x chart takes its height from its container
        $this->assertStringContainsString('<div style="position: relative; height: {{ (int) $chart_height }}px;">', $script);
        $this->assertStringContainsString('maintainAspectRatio: false', $script);
    }

    public function testTheLineLegendBoxIsFilledWithTheLineColor(): void
    {
        // Chart.js 4 fills the legend box of a dataset with its backgroundColor (2.x used the line
        // color): without it the box of a line chart is a hollow grey square. fill: false keeps
        // the area under the line empty.
        $script = $this->script();
        // the line dataset: from its tension to the end of its branch
        $line = substr($script, (int) strpos($script, 'tension: 0'));
        $line = substr($line, 0, (int) strpos($line, '@endif'));
        $this->assertStringContainsString('borderColor: base,', $line);
        $this->assertStringContainsString('backgroundColor: base,', $line);
        $this->assertStringContainsString('fill: false,', $line);
    }
}
