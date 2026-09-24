<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;

/**
 * The box menu's display option (値ラベル): the request value and which chart types offer it.
 */
class ChartMenuTest extends DashboardUnitTestCase
{
    public function testDisplayOptionsParse()
    {
        $this->assertSame([], ChartItem::displayOptions(null), 'unset = nothing on');
        $this->assertSame([], ChartItem::displayOptions(''));
        $this->assertSame(['labels'], ChartItem::displayOptions('labels'));
        $this->assertSame(['labels'], ChartItem::displayOptions(' labels ,labels'), 'trimmed, deduplicated');
        $this->assertSame(['labels'], ChartItem::displayOptions('labels,avg,median,<script>'), 'unknown values dropped');
        $this->assertSame([], ChartItem::displayOptions('avg'), 'a former option is just unknown');
        $this->assertSame([], ChartItem::displayOptions(['labels']), 'not a string = nothing');
    }

    public function testToolbarStateKeepsOnlyChoicesThatDifferFromTheBox()
    {
        $this->assertSame(
            ['ct' => 'line', 'cs' => 'y:desc', 'cd' => 'labels'],
            ChartItem::toolbarState(['ct' => 'line', 'cs' => 'y:desc', 'cd' => 'labels'], 'bar')
        );
        $this->assertNull(ChartItem::toolbarState(['ct' => 'bar', 'cs' => '', 'cd' => ''], 'bar'), "the box's own type = follow the box setting: nothing kept");
        $this->assertNull(ChartItem::toolbarState([], 'bar'));
        $this->assertSame(['cs' => 'x0:asc'], ChartItem::toolbarState(['ct' => 'mbar', 'cs' => 'x0:asc'], 'bar'), 'a type the box cannot switch to is dropped');
        $this->assertSame(['ct' => 'sbar'], ChartItem::toolbarState(['ct' => 'sbar'], 'mbar'), 'multi-series switch pool');
        $this->assertNull(ChartItem::toolbarState(['ct' => 'xyz', 'cs' => 'y:sideways', 'cd' => 'avg'], 'bar'), 'malformed values dropped');
        $this->assertNull(ChartItem::toolbarState(['ct' => ['line'], 'cs' => ['y:desc'], 'cd' => ['labels']], 'bar'), 'non-strings dropped');
    }

    public function testWhichTypesOfferDataLabels()
    {
        foreach (['bar', 'line', 'pie', 'hbar', 'area', 'scatter', 'mbar', 'sbar', 'mline', 'sarea'] as $type) {
            $this->assertTrue(ChartType::supportsDataLabels($type), $type);
        }
        foreach (['doughnut', 'funnel', 'radar', 'gauge', 'heatmap', 'treemap', 'sunburst', 'boxplot', null, 'xyz'] as $type) {
            $this->assertFalse(ChartType::supportsDataLabels($type), 'always-labelled or shape types: ' . json_encode($type));
        }
    }
}
