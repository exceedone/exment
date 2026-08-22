<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

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
