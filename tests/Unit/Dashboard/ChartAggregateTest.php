<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\ChartAggregate;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Services\Dashboard\SummaryAverage;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;

class ChartAggregateTest extends DashboardUnitTestCase
{
    public function testOnlyAggregatesAreEnumValues()
    {
        $this->assertSame(['sum', 'avg', 'count', 'min', 'max'], ChartAggregate::arrays(), 'every constant becomes a form option — no helper constants allowed');
    }

    public function testResolve()
    {
        $this->assertSame('avg', ChartAggregate::resolve('avg'));
        $this->assertSame('max', ChartAggregate::resolve('max'));
        $this->assertNull(ChartAggregate::resolve(null), 'unset = as the view');
        $this->assertNull(ChartAggregate::resolve(''));
        $this->assertNull(ChartAggregate::resolve('AVG'));
        $this->assertNull(ChartAggregate::resolve('median'));
        $this->assertNull(ChartAggregate::resolve(['avg']));
    }

    public function testEngineConditionOfEachAggregate()
    {
        $this->assertSame(SummaryCondition::SUM, ChartAggregate::summaryCondition('sum'));
        $this->assertSame(SummaryCondition::COUNT, ChartAggregate::summaryCondition('count'));
        $this->assertSame(SummaryCondition::MIN, ChartAggregate::summaryCondition('min'));
        $this->assertSame(SummaryCondition::MAX, ChartAggregate::summaryCondition('max'));
        $this->assertNull(ChartAggregate::summaryCondition('avg'), 'the engine has no AVG: derived from a SUM and a COUNT run');
    }

    public function testFormOptionsLeadWithAsTheView()
    {
        $options = ChartAggregate::formOptions();
        $this->assertSame(['', 'sum', 'avg', 'count', 'min', 'max'], array_keys($options));
        foreach ($options as $label) {
            $this->assertNotSame('', $label);
        }
    }

    public function testSavingKeepsOnlyKnownAggregate()
    {
        // TEMPORARILY DISABLED (2026-09-23): 集計方法 is switched off in ChartItem (constructor, form
        // field, saving sanitizer commented out); this test comes back with them.
        $this->markTestSkipped('集計方法 (chart_aggregate) temporarily disabled in ChartItem');
        $form = new \stdClass();
        $form->options = ['chart_type' => 'bar', 'chart_aggregate' => 'avg', 'chart_options' => []];
        ChartItem::saving($form);
        $this->assertSame('avg', $form->options['chart_aggregate']);

        $form->options = ['chart_type' => 'bar', 'chart_aggregate' => '', 'chart_options' => []];
        ChartItem::saving($form);
        $this->assertArrayNotHasKey('chart_aggregate', $form->options, 'empty = as the view stores nothing');

        $form->options = ['chart_type' => 'bar', 'chart_aggregate' => 'median', 'chart_options' => []];
        ChartItem::saving($form);
        $this->assertArrayNotHasKey('chart_aggregate', $form->options);
    }

    public function testMergeMatchesGroupsNotPositions()
    {
        $sums = collect([
            ['g' => 'A', 'v' => 300],
            ['g' => 'B', 'v' => 1000],
            ['g' => 'C', 'v' => 50],   // no count row
            ['g' => 'D', 'v' => 10],   // count 0
        ]);
        $counts = collect([
            ['g' => 'B', 'v' => 7],    // other order than the sums
            ['g' => 'A', 'v' => 4],
            ['g' => 'D', 'v' => 0],
        ]);
        $rows = SummaryAverage::merge($sums, $counts, ['g'], 'v');
        $this->assertSame(['A', 'B', 'C', 'D'], $rows->pluck('g')->all(), 'the SUM rows and their order are kept');
        $this->assertSame([75.0, 142.9, null, null], $rows->pluck('v')->all());
        $this->assertSame([300.0, 1000.0, 50.0, 10.0], $rows->pluck('v__sum')->all());
        $this->assertSame([4, 7, 0, 0], $rows->pluck('v__n')->all());
    }

    public function testMergeKeysOnEveryGroupColumn()
    {
        $sums = collect([['a' => 1, 'b' => 'x', 'v' => 10], ['a' => 1, 'b' => 'y', 'v' => 20]]);
        $counts = collect([['a' => 1, 'b' => 'y', 'v' => 2], ['a' => 1, 'b' => 'x', 'v' => 5]]);
        $this->assertSame([2.0, 10.0], SummaryAverage::merge($sums, $counts, ['a', 'b'], 'v')->pluck('v')->all());
    }

    public function testMeanAndCellMeans()
    {
        $this->assertSame(70.6, SummaryAverage::mean(3388657, 48000));
        $this->assertSame(142.9, SummaryAverage::mean('1000', '7'));
        $this->assertNull(SummaryAverage::mean(10, 0));
        $this->assertNull(SummaryAverage::mean(null, 3));
        $this->assertNull(SummaryAverage::mean('abc', 3));
        $this->assertSame([[75.0, 0], [2.5, 10.0]], SummaryAverage::cellMeans([[300, 0], [5, 20]], [[4, 0], [2, 2]]));
    }

    public function testValueLeadsOrder()
    {
        $this->assertFalse(SummaryAverage::valueLeadsOrder(null, [null]), 'unset = 1 on both sides: the group columns are registered first');
        $this->assertFalse(SummaryAverage::valueLeadsOrder('2', ['1']));
        $this->assertFalse(SummaryAverage::valueLeadsOrder(1, [1, 2]));
        $this->assertTrue(SummaryAverage::valueLeadsOrder(1, [2, 3]));
        $this->assertTrue(SummaryAverage::valueLeadsOrder('0', [null]));
        $this->assertTrue(SummaryAverage::valueLeadsOrder(null, []), 'no group column at all');
    }

    public function testSortByValueKeepsNullsLastAndIsStable()
    {
        $rows = collect([['v' => 2.5, 'k' => 'first'], ['v' => null], ['v' => 9.0], ['v' => 2.5, 'k' => 'second']]);
        $this->assertSame([9.0, 2.5, 2.5, null], SummaryAverage::sortByValue($rows, 'v', true)->pluck('v')->all());
        $asc = SummaryAverage::sortByValue($rows, 'v', false);
        $this->assertSame([2.5, 2.5, 9.0, null], $asc->pluck('v')->all());
        $this->assertSame('first', $asc[0]['k']);
    }
}
