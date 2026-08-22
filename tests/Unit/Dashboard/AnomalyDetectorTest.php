<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Services\AnomalyDetector;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;

class AnomalyDetectorTest extends DashboardUnitTestCase
{
    public function testTooFewPoints()
    {
        $this->assertNull(AnomalyDetector::detect(['a', 'b', 'c'], [1, 2, 100]));
        $this->assertNull(AnomalyDetector::detect(['a', 'b', 'c', 'd', 'e'], ['x', null, '', 1, 2]));
    }

    public function testFlatSeriesHasNoOutlier()
    {
        $this->assertNull(AnomalyDetector::detect(['a', 'b', 'c', 'd', 'e'], [5, 5, 5, 5, 5]));
    }

    public function testLoneExtremeAmongIdenticalValues()
    {
        $result = AnomalyDetector::detect(['a', 'b', 'c', 'd', 'e'], [5, 5, 5, 5, 100]);
        $this->assertSame('mad', $result['method']);
        $this->assertSame(1, $result['count']);
        $this->assertSame(4, $result['points'][0]['index']);
        $this->assertSame('e', $result['points'][0]['label']);
        $this->assertSame('high', $result['points'][0]['direction']);
    }

    public function testIqrOutliersAndDirection()
    {
        $result = AnomalyDetector::detect(range('a', 'h'), [60, 62, 61, 63, 59, 60, 20, 62]);
        $this->assertSame('iqr', $result['method']);
        $this->assertSame([6], array_column($result['points'], 'index'));
        $this->assertSame('low', $result['points'][0]['direction']);
        $this->assertLessThan(59, $result['lower']);
        $this->assertGreaterThan(63, $result['upper']);
    }

    public function testTightClusterIsNotOverFlagged()
    {
        $this->assertSame(0, AnomalyDetector::detect(['a', 'b', 'c', 'd', 'e'], [618, 619, 620, 622.7, 619.5])['count']);
    }

    public function testAtMostFivePointsMostSevereFirst()
    {
        $values = array_merge(array_fill(0, 20, 50), [500, 400, 300, 200, 100, 90, 80]);
        $result = AnomalyDetector::detect(array_keys($values), $values);
        $this->assertSame(5, $result['count']);
        $this->assertSame([500.0, 400.0, 300.0, 200.0, 100.0], array_column($result['points'], 'value'));
    }

    public function testPercentile()
    {
        $this->assertSame(0.0, AnomalyDetector::percentile([], 0.5));
        $this->assertSame(7.0, AnomalyDetector::percentile([7], 0.5));
        $this->assertSame(2.5, AnomalyDetector::percentile([1, 2, 3, 4], 0.5));
        $this->assertSame(1.75, AnomalyDetector::percentile([1, 2, 3, 4], 0.25));
    }
}
