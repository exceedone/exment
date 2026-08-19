<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Ai;

use Tests\TestCase;
use Exceedone\Exment\Services\AnomalyDetector;

/**
 * AnomalyDetector — deterministic outlier detection behind the chart markers and the AI
 * summary strip (Tukey IQR fence, MAD fallback, meaningfulness floor). Pure computation:
 * no DB, no LLM.
 */
class AnomalyDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'exment.ai.anomaly_iqr_k'      => 1.5,
            'exment.ai.anomaly_min_points' => 5,
            'exment.ai.anomaly_min_rel'    => 0.02,
            'exment.ai.anomaly_mad_k'      => 3.5,
        ]);
    }

    public function testTooFewPointsGivesNull(): void
    {
        $this->assertNull(AnomalyDetector::detect(['a', 'b', 'c', 'd'], [1, 2, 3, 100]));
        $this->assertNull(AnomalyDetector::detect([], []));
        // non-numeric values do not count as points
        $this->assertNull(AnomalyDetector::detect(['a', 'b', 'c', 'd', 'e'], [1, 2, 3, 'x', null]));
    }

    public function testIqrFlagsHighAndLowOutliersWithOriginalIndex(): void
    {
        $labels = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $values = [50, 52, 51, 49, 250, 50, 53, 5];
        $r = AnomalyDetector::detect($labels, $values);
        $this->assertNotNull($r);
        $this->assertSame('iqr', $r['method']);
        $this->assertSame(2, $r['count']);
        $this->assertSame(4, $r['points'][0]['index'], 'most severe first (250)');
        $this->assertSame('E', $r['points'][0]['label']);
        $this->assertSame('high', $r['points'][0]['direction']);
        $this->assertSame(7, $r['points'][1]['index']);
        $this->assertSame('low', $r['points'][1]['direction']);
        $this->assertLessThan($r['upper'], 53);
        $this->assertGreaterThan($r['lower'], 49);
    }

    public function testNonNumericValuesAreSkippedButIndexesKept(): void
    {
        $values = [50, 'n/a', 52, 51, 49, 250, 50, 53];
        $r = AnomalyDetector::detect(array_map('strval', range(0, 7)), $values);
        $this->assertNotNull($r);
        $this->assertSame(1, $r['count']);
        $this->assertSame(5, $r['points'][0]['index'], 'index refers to the ORIGINAL position');
    }

    public function testConstantSeriesHasNoOutlier(): void
    {
        $this->assertNull(AnomalyDetector::detect(['a', 'b', 'c', 'd', 'e'], [7, 7, 7, 7, 7]));
    }

    public function testZeroIqrFallsBackToMad(): void
    {
        $r = AnomalyDetector::detect(['a', 'b', 'c', 'd', 'e'], [5, 5, 5, 5, 100]);
        $this->assertNotNull($r);
        $this->assertSame('mad', $r['method']);
        $this->assertSame(1, $r['count']);
        $this->assertSame(4, $r['points'][0]['index']);
        $this->assertSame('high', $r['points'][0]['direction']);
    }

    public function testMinRelSuppressesTriviallyDifferentPoints(): void
    {
        // 622.7 among a tight cluster: statistically outside the fence, ~0.6% off the median
        $labels = ['a', 'b', 'c', 'd', 'e', 'f'];
        $values = [618, 619, 620, 619, 618, 622.7];
        $r = AnomalyDetector::detect($labels, $values);
        $this->assertTrue($r === null || $r['count'] === 0, 'suppressed by min_rel');

        config(['exment.ai.anomaly_min_rel' => 0]);
        $r = AnomalyDetector::detect($labels, $values);
        $this->assertNotNull($r);
        $this->assertSame(1, $r['count'], 'pure IQR flags it once the floor is off');
        $this->assertSame(5, $r['points'][0]['index']);
    }

    public function testAtMostFiveMostSeverePoints(): void
    {
        // 40 base points spread 10..19 (IQR ≈ 5) + 7 clear outliers 100..160
        $values = [];
        for ($i = 0; $i < 40; $i++) {
            $values[] = 10 + ($i % 10);
        }
        foreach ([100, 110, 120, 130, 140, 150, 160] as $v) {
            $values[] = $v;
        }
        $r = AnomalyDetector::detect(array_map('strval', array_keys($values)), $values);
        $this->assertNotNull($r);
        $this->assertSame(5, $r['count']);
        $this->assertSame([46, 45, 44, 43, 42], array_column($r['points'], 'index'), 'the 5 most severe, severity order');
    }

    public function testPercentileLinearInterpolation(): void
    {
        $this->assertSame(0.0, AnomalyDetector::percentile([], 0.5));
        $this->assertSame(4.0, AnomalyDetector::percentile([4], 0.9));
        $this->assertSame(2.5, AnomalyDetector::percentile([1, 2, 3, 4], 0.5));
        $this->assertSame(1.75, AnomalyDetector::percentile([1, 2, 3, 4], 0.25));
        $this->assertSame(3.0, AnomalyDetector::percentile([1, 2, 3, 4, 5], 0.5));
    }
}
