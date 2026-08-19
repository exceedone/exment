<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Services\Dashboard\BoxChartConfig;

/**
 * BoxChartConfig — typed reader of a chart box's options. The two keys the new features
 * add (chart_filters, chart_type_lock) are sanitized HERE and nowhere else, so this is
 * the contract every consumer (toolbar, FilterState::boxFilters, admin form) relies on.
 * Also pins that pre-existing keys keep their exact call-site defaults.
 */
class BoxChartConfigTest extends DashboardUnitTestCase
{
    public function testFiltersSanitizedToIdentifiers(): void
    {
        $cfg = BoxChartConfig::of(['options' => ['chart_filters' => ['region', '', 'bad name', 'ok_2', 5, null, 'x;y', 'Z']]]);
        $this->assertSame(['region', 'ok_2', 'Z'], $cfg->filters());
    }

    public function testFiltersDefaultsAndShapes(): void
    {
        $this->assertSame([], BoxChartConfig::of(['options' => []])->filters());
        $this->assertSame([], BoxChartConfig::of(['options' => ['chart_filters' => null]])->filters());
        $this->assertSame([], BoxChartConfig::of(['options' => ['chart_filters' => 'region']])->filters(), 'a bare string is not a list');
        $this->assertSame([], BoxChartConfig::of([])->filters());
        // works on the model too (array access via array_get)
        $box = $this->makeBox('b1', ['chart_filters' => ['a', 'b']]);
        $this->assertSame(['a', 'b'], BoxChartConfig::of($box)->filters());
    }

    public function testFiltersOrderIsPreservedAndReindexed(): void
    {
        $cfg = BoxChartConfig::of(['options' => ['chart_filters' => [3 => 'c', 1 => 'a', 2 => '']]]);
        $this->assertSame(['c', 'a'], $cfg->filters(), 'toolbar renders fields in the configured order');
    }

    public function testTypeLock(): void
    {
        $this->assertFalse(BoxChartConfig::of(['options' => []])->typeLock());
        $this->assertTrue(BoxChartConfig::of(['options' => ['chart_type_lock' => 1]])->typeLock());
        $this->assertTrue(BoxChartConfig::of(['options' => ['chart_type_lock' => '1']])->typeLock());
        $this->assertFalse(BoxChartConfig::of(['options' => ['chart_type_lock' => '0']])->typeLock());
        $this->assertFalse(BoxChartConfig::of(['options' => ['chart_type_lock' => null]])->typeLock());
    }

    public function testMaxGroups(): void
    {
        $this->assertSame(0, BoxChartConfig::of(['options' => []])->maxGroups());
        $this->assertSame(10, BoxChartConfig::of(['options' => ['chart_max_groups' => 10]])->maxGroups());
        $this->assertSame(10, BoxChartConfig::of(['options' => ['chart_max_groups' => '10']])->maxGroups());
        $this->assertSame(0, BoxChartConfig::of(['options' => ['chart_max_groups' => 0]])->maxGroups());
        $this->assertSame(0, BoxChartConfig::of(['options' => ['chart_max_groups' => '-3']])->maxGroups());
        $this->assertSame(0, BoxChartConfig::of(['options' => ['chart_max_groups' => 'abc']])->maxGroups());
        $this->assertSame(0, BoxChartConfig::of(['options' => ['chart_max_groups' => null]])->maxGroups());
    }

    public function testValueMean(): void
    {
        $this->assertFalse(BoxChartConfig::of(['options' => []])->valueMean());
        $this->assertTrue(BoxChartConfig::of(['options' => ['chart_value_mean' => true]])->valueMean());
        $this->assertTrue(BoxChartConfig::of(['options' => ['chart_value_mean' => '1']])->valueMean());
        $this->assertFalse(BoxChartConfig::of(['options' => ['chart_value_mean' => 0]])->valueMean());
    }

    public function testSortsByValueHonoursNaturalViews(): void
    {
        $this->assertFalse(BoxChartConfig::of(['options' => []])->sortsByValue(7), 'off by default');
        $this->assertFalse(BoxChartConfig::of(['options' => ['chart_sort_value' => 'asc']])->sortsByValue(7), 'only desc is a sort');
        $cfg = BoxChartConfig::of(['options' => ['chart_sort_value' => 'desc', 'chart_sort_natural_views' => ['7', 9]]]);
        $this->assertTrue($cfg->sortsByValue(8));
        $this->assertFalse($cfg->sortsByValue(7), 'natural-order view keeps its order (string id)');
        $this->assertFalse($cfg->sortsByValue('9'), 'natural-order view keeps its order (int id)');
        $this->assertTrue(BoxChartConfig::of(['options' => ['chart_sort_value' => 'desc']])->sortsByValue(null));
    }

    /** Legacy getters keep their original defaults (the "byte-identical" contract of the refactor). */
    public function testLegacyDefaults(): void
    {
        $cfg = BoxChartConfig::of(['options' => []]);
        $this->assertNull($cfg->chartType());
        $this->assertNull($cfg->targetTableId());
        $this->assertNull($cfg->targetViewId());
        $this->assertSame([], $cfg->chartOptions());
        $this->assertSame([], $cfg->axisLabel());
        $this->assertSame([], $cfg->axisName());
        $this->assertSame([], $cfg->drillUrls());
        $this->assertFalse($cfg->benchmark());
        $this->assertSame([], $cfg->levelViews());
        $this->assertSame([], $cfg->levelVisible());
        $this->assertSame(0, $cfg->levelMaxGroups());
        $this->assertFalse($cfg->levelHintEnabled());
        $this->assertFalse($cfg->hideWhenCapped());
        $this->assertSame([], $cfg->pinnedViews());
        $this->assertSame([], $cfg->hideWhenPinned());
        $this->assertSame([], $cfg->shadeViewIds());
    }

    public function testLegacyValuesPassThrough(): void
    {
        $cfg = BoxChartConfig::of(['options' => [
            'chart_type' => 'bar',
            'target_table_id' => '12',
            'chart_level_views' => ['' => 5, 'region' => 6],
            'chart_level_max_groups' => '15',
            'chart_category_shade_views' => ['7', 8],
            'chart_benchmark' => '1',
        ]]);
        $this->assertSame('bar', $cfg->chartType());
        $this->assertSame('12', $cfg->targetTableId());
        $this->assertSame(['' => 5, 'region' => 6], $cfg->levelViews());
        $this->assertSame(15, $cfg->levelMaxGroups());
        $this->assertSame([7, 8], $cfg->shadeViewIds());
        $this->assertTrue($cfg->benchmark());
        // a non-array where an array is expected degrades to the empty default, never throws
        $this->assertSame([], BoxChartConfig::of(['options' => ['chart_level_views' => 'x']])->levelViews());
    }
}
