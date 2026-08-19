<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Enums\ChartType;

/**
 * ChartType — the type catalogue every renderer gate reads (Chart.js legacy + ECharts
 * single / multi-series families). Pins the family membership, the "legend vs begin-at-zero"
 * rule shared by the box form / saving() / runtime switch, and that the pruned decorative
 * types are really gone.
 */
class ChartTypeTest extends DashboardUnitTestCase
{
    public function testFamilies(): void
    {
        $this->assertSame(['hbar', 'area', 'doughnut', 'radar', 'funnel', 'gauge', 'scatter'], ChartType::singleSeriesEchartsTypes());
        $this->assertSame(['mbar', 'sbar', 'mline', 'heatmap', 'sarea', 'treemap', 'sunburst', 'boxplot'], ChartType::multiSeriesTypes());
        $this->assertSame(
            array_merge(ChartType::singleSeriesEchartsTypes(), ChartType::multiSeriesTypes()),
            ChartType::echartsTypes()
        );
        foreach (['bar', 'line', 'pie'] as $legacy) {
            $this->assertFalse(ChartType::isEcharts($legacy), "$legacy stays on Chart.js");
            $this->assertFalse(ChartType::isMultiSeries($legacy));
        }
        $this->assertTrue(ChartType::isEcharts('hbar'));
        $this->assertTrue(ChartType::isEcharts('sbar'));
        $this->assertTrue(ChartType::isMultiSeries('heatmap'));
        $this->assertFalse(ChartType::isMultiSeries('doughnut'));
        $this->assertFalse(ChartType::isEcharts(null));
        $this->assertFalse(ChartType::isEcharts('wordcloud'), 'pruned type is unknown');
    }

    public function testCircularAndLegendRule(): void
    {
        $this->assertSame(['pie', 'doughnut', 'funnel', 'radar'], ChartType::circularTypes());
        $this->assertTrue(ChartType::isCircular('pie'));
        $this->assertFalse(ChartType::isCircular('bar'));
        $legend = ChartType::legendTypes();
        // legend option for every circular + multi-series type, exactly once each
        $this->assertSame(array_values(array_unique($legend)), $legend);
        foreach (array_merge(ChartType::circularTypes(), ChartType::multiSeriesTypes()) as $t) {
            $this->assertContains($t, $legend, $t);
        }
        foreach (['bar', 'line', 'hbar', 'area', 'gauge', 'scatter'] as $t) {
            $this->assertNotContains($t, $legend, "$t shows begin-at-zero instead");
        }
    }

    public function testPrunedTypesAreGone(): void
    {
        $all = array_merge([ChartType::BAR, ChartType::LINE, ChartType::PIE], ChartType::echartsTypes());
        foreach (['rose', 'pictorialbar', 'bubble', 'polarbar', 'effectscatter', 'wordcloud', 'liquidfill',
                  'themeriver', 'sankey', 'graph', 'tree', 'parallel', 'candlestick', 'ranking', 'kpi'] as $gone) {
            $this->assertNotContains($gone, $all, $gone);
        }
        $this->assertCount(18, $all, '3 Chart.js + 7 single-series + 8 multi-series ECharts types');
    }
}
