<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Services\Dashboard\ChartRendererRegistry;

/**
 * Feature 3 — Chart Type switcher: the `ct` request param may only move a box WITHIN its
 * dataset shape. ChartRendererRegistry is the single validation point (pure functions,
 * no DB), so these tests pin the whole contract:
 *
 *  - family(): the render-family dispatch (identical to the old body() if-chain, incl.
 *    the "unknown/empty type -> chartjs" fallthrough that keeps legacy boxes working);
 *  - switchPool(): which types share a dataset shape;
 *  - effectiveType(): whitelist validation of the runtime request (silent fallback).
 */
class ChartRendererRegistryTest extends DashboardUnitTestCase
{
    // ---- family() -----------------------------------------------------------

    public function testFamilyOfChartjsTypes(): void
    {
        foreach ([ChartType::BAR, ChartType::LINE, ChartType::PIE] as $t) {
            $this->assertSame(ChartRendererRegistry::FAMILY_CHARTJS, ChartRendererRegistry::family($t), $t);
        }
    }

    public function testFamilyOfEchartsSingleTypes(): void
    {
        foreach (ChartType::singleSeriesEchartsTypes() as $t) {
            $this->assertSame(ChartRendererRegistry::FAMILY_ECHARTS, ChartRendererRegistry::family($t), $t);
        }
    }

    public function testFamilyOfMultiSeriesTypes(): void
    {
        foreach (ChartType::multiSeriesTypes() as $t) {
            $this->assertSame(ChartRendererRegistry::FAMILY_ECHARTS_MULTI, ChartRendererRegistry::family($t), $t);
        }
    }


    /** Legacy safety: an unknown or missing chart_type still renders through Chart.js. */
    public function testFamilyFallsThroughToChartjsForUnknownOrEmpty(): void
    {
        $this->assertSame(ChartRendererRegistry::FAMILY_CHARTJS, ChartRendererRegistry::family(null));
        $this->assertSame(ChartRendererRegistry::FAMILY_CHARTJS, ChartRendererRegistry::family(''));
        $this->assertSame(ChartRendererRegistry::FAMILY_CHARTJS, ChartRendererRegistry::family('no_such_type'));
    }

    // ---- switchPool() -------------------------------------------------------

    /** Chart.js and ECharts single-series types are ONE pool (same labels[]+values[] data). */
    public function testSingleSeriesPoolIsSharedAcrossRenderers(): void
    {
        $fromBar = ChartRendererRegistry::switchPool(ChartType::BAR);
        $fromHbar = ChartRendererRegistry::switchPool(ChartType::HBAR);

        $this->assertSame($fromBar, $fromHbar, 'bar (chartjs) and hbar (echarts) must offer the same pool');
        $this->assertContains(ChartType::BAR, $fromBar);
        $this->assertContains(ChartType::LINE, $fromBar);
        $this->assertContains(ChartType::PIE, $fromBar);
        foreach (ChartType::singleSeriesEchartsTypes() as $t) {
            $this->assertContains($t, $fromBar, $t . ' missing from single-series pool');
        }
        // never a multi-series type
        foreach (ChartType::multiSeriesTypes() as $t) {
            $this->assertNotContains($t, $fromBar);
        }
        // no duplicates (the <select> is rendered straight from this list)
        $this->assertSame(count($fromBar), count(array_unique($fromBar)));
    }

    public function testMultiSeriesPoolIsExactlyTheMultiSeriesTypes(): void
    {
        $this->assertSame(ChartType::multiSeriesTypes(), ChartRendererRegistry::switchPool(ChartType::MBAR));
        $this->assertSame(ChartType::multiSeriesTypes(), ChartRendererRegistry::switchPool(ChartType::HEATMAP));
    }


    /** An unconfigured/unknown type is treated as chartjs -> it CAN switch inside the single pool. */
    public function testUnknownTypeGetsTheSingleSeriesPool(): void
    {
        $this->assertSame(ChartRendererRegistry::switchPool(ChartType::BAR), ChartRendererRegistry::switchPool(null));
    }

    // ---- effectiveType() ----------------------------------------------------

    public function testNoRequestKeepsConfiguredType(): void
    {
        $this->assertSame('bar', ChartRendererRegistry::effectiveType('bar', null));
        $this->assertSame('bar', ChartRendererRegistry::effectiveType('bar', ''));
        $this->assertSame('bar', ChartRendererRegistry::effectiveType('bar', 'bar'));
    }

    public function testSwitchWithinSingleSeriesPool(): void
    {
        $this->assertSame('line', ChartRendererRegistry::effectiveType('bar', 'line'));
        $this->assertSame('pie', ChartRendererRegistry::effectiveType('line', 'pie'));
        // cross-renderer (Chart.js -> ECharts and back) is legal: same dataset shape
        $this->assertSame('hbar', ChartRendererRegistry::effectiveType('bar', 'hbar'));
        $this->assertSame('bar', ChartRendererRegistry::effectiveType('doughnut', 'bar'));
        $this->assertSame('radar', ChartRendererRegistry::effectiveType('area', 'radar'));
    }

    public function testSwitchWithinMultiSeriesPool(): void
    {
        $this->assertSame('sbar', ChartRendererRegistry::effectiveType('mbar', 'sbar'));
        $this->assertSame('heatmap', ChartRendererRegistry::effectiveType('mline', 'heatmap'));
    }

    /** Cross-shape requests are ignored: the box renders as configured, silently. */
    public function testCrossShapeSwitchIsRejected(): void
    {
        $this->assertSame('bar', ChartRendererRegistry::effectiveType('bar', 'mbar'), 'single -> multi');
        $this->assertSame('mbar', ChartRendererRegistry::effectiveType('mbar', 'bar'), 'multi -> single');
        $this->assertSame('bar', ChartRendererRegistry::effectiveType('bar', 'ranking'), 'unknown type name is not in any pool');
    }

    /** Junk on the query string (unknown type, arrays, numbers) never leaks into rendering. */
    public function testJunkRequestIsIgnored(): void
    {
        $this->assertSame('bar', ChartRendererRegistry::effectiveType('bar', 'no_such_type'));
        $this->assertSame('bar', ChartRendererRegistry::effectiveType('bar', ['line']));
        $this->assertSame('bar', ChartRendererRegistry::effectiveType('bar', 1));
        $this->assertSame('bar', ChartRendererRegistry::effectiveType('bar', 'LINE'), 'case-sensitive whitelist');
        $this->assertSame('bar', ChartRendererRegistry::effectiveType('bar', ' line'), 'no trimming - exact match only');
    }

    /** A legacy box without chart_type keeps rendering as before unless a legal ct arrives. */
    public function testNullConfiguredType(): void
    {
        $this->assertNull(ChartRendererRegistry::effectiveType(null, null));
        $this->assertNull(ChartRendererRegistry::effectiveType(null, 'mbar'));
        $this->assertSame('line', ChartRendererRegistry::effectiveType(null, 'line'));
    }

    /** Whatever effectiveType returns must always be renderable by the SAME family pool. */
    public function testEffectiveTypeNeverLeavesThePool(): void
    {
        $all = array_merge(
            [ChartType::BAR, ChartType::LINE, ChartType::PIE, null, 'junk'],
            ChartType::singleSeriesEchartsTypes(),
            ChartType::multiSeriesTypes()
        );
        foreach ($all as $configured) {
            foreach ($all as $requested) {
                $eff = ChartRendererRegistry::effectiveType($configured, $requested);
                if ($eff === $configured) {
                    continue;
                }
                $label = var_export($configured, true) . ' -> ' . var_export($requested, true);
                $this->assertContains($eff, ChartRendererRegistry::switchPool($configured), $label);
                $this->assertSame(
                    ChartRendererRegistry::switchPool($configured),
                    ChartRendererRegistry::switchPool($eff),
                    'pool must be closed under switching (' . $label . ')'
                );
            }
        }
    }
}
