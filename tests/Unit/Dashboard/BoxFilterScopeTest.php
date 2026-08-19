<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Services\Dashboard\BoxFilterScope;

/**
 * BoxFilterScope — what the selective box refresh (JS exmentBoxSig) is told about a box:
 * `cols` = the df columns that really narrow it (table ∩ dims ∩ targeting) and `dynamic`
 * = "reads the wider filter state, always reload". The `cols` half needs a real table
 * (covered DB-side by the browser tests t_selective*.js); the guards and the `dynamic`
 * detection are pure config logic and pinned here.
 */
class BoxFilterScopeTest extends DashboardUnitTestCase
{
    public function testNullAndNonChartBoxes(): void
    {
        $this->assertSame(['cols' => [], 'dynamic' => false], BoxFilterScope::of(null));
        $d = $this->makeDashboard(['dims' => [['column' => 'region']]]);
        $list = $this->makeBox('b1', ['chart_level_views' => ['' => 1]], $d, 'list');
        $this->assertSame(['cols' => [], 'dynamic' => false], BoxFilterScope::of($list), 'only chart boxes apply df_ at all');
    }

    public function testNoFilterBarConfigMeansNothingToTrack(): void
    {
        $box = $this->makeBox('b1', ['chart_level_views' => ['' => 1]], $this->makeDashboard(null));
        $this->assertSame(['cols' => [], 'dynamic' => false], BoxFilterScope::of($box));
    }

    /** Every option that makes a box read the whole chain/pinned state flags it dynamic. */
    public function testDynamicKeys(): void
    {
        $d = $this->makeDashboard(['dims' => [['column' => 'region']]]);
        foreach ([
            ['chart_level_views' => ['' => 1]],
            ['chart_pinned_views' => ['region' => 1]],
            ['chart_level_visible' => ['']],
            ['chart_hide_when_pinned' => ['region']],
            ['chart_level_max_groups' => 12],
            ['chart_hide_when_capped' => 1],
        ] as $opts) {
            $this->assertTrue(BoxFilterScope::of($this->makeBox('b1', $opts, $d))['dynamic'], json_encode($opts));
        }
    }

    public function testStaticBoxIsNotDynamic(): void
    {
        $d = $this->makeDashboard(['dims' => [['column' => 'region']]]);
        foreach ([
            [],
            ['chart_type' => 'bar', 'chart_filters' => ['subject']],
            ['chart_level_views' => []],          // empty = unconfigured
            ['chart_level_max_groups' => ''],
        ] as $opts) {
            $r = BoxFilterScope::of($this->makeBox('b1', $opts, $d));
            $this->assertFalse($r['dynamic'], json_encode($opts));
            $this->assertSame([], $r['cols'], 'no table → no columns');
        }
    }
}
