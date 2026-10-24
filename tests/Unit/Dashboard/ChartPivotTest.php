<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Services\Dashboard\ChartPivot;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;

/**
 * The pivot of a multi-series chart (ChartPivot): a category per stored X value, the rows
 * of a cell folded as the measure's summary condition folds rows.
 */
class ChartPivotTest extends DashboardUnitTestCase
{
    /**
     * Rows of a view grouped by prefecture × gender × year: several rows per prefecture × gender cell.
     *
     * @return array<int, array{x: string, x_text: string, series: string, y: string}>
     */
    private function rows(): array
    {
        return [
            ['x' => 'hokkaido', 'x_text' => '北海道', 'series' => '男', 'y' => '10'],
            ['x' => 'hokkaido', 'x_text' => '北海道', 'series' => '男', 'y' => '4'],
            ['x' => 'hokkaido', 'x_text' => '北海道', 'series' => '女', 'y' => '7.5'],
            ['x' => 'aomori', 'x_text' => '青森県', 'series' => '女', 'y' => '2'],
        ];
    }

    public function testCellsFoldAsTheSummaryConditionFoldsRows(): void
    {
        $sum = ChartPivot::build($this->rows(), SummaryCondition::SUM);
        $this->assertSame(['北海道', '青森県'], $sum['x_categories'], "categories in the view's order");
        $this->assertSame(['hokkaido', 'aomori'], $sum['x_values'], 'the stored value behind each category');
        $this->assertSame(['男', '女'], $sum['series_names']);
        $this->assertSame([[14.0, 0], [7.5, 2.0]], $sum['matrix'], 'the rows of a cell add up; a cell no row fell into is 0');

        $this->assertSame([[14.0, 0], [7.5, 2.0]], ChartPivot::build($this->rows(), SummaryCondition::COUNT)['matrix'], 'the count of a cell is the sum of its rows\' counts');
        $this->assertSame([[14.0, 0], [7.5, 2.0]], ChartPivot::build($this->rows(), null)['matrix'], 'a group column as the measure adds up');
        $this->assertSame([[4.0, null], [7.5, 2.0]], ChartPivot::build($this->rows(), SummaryCondition::MIN)['matrix'], 'MIN keeps the lowest row; a cell no row fell into has no minimum');
        $this->assertSame([[10.0, null], [7.5, 2.0]], ChartPivot::build($this->rows(), SummaryCondition::MAX)['matrix'], 'MAX keeps the highest row; a cell no row fell into has no maximum');
    }

    /**
     * A cell no row falls into: 0 is the sum or count of no rows, but no row's minimum or
     * maximum — a 0 there was drawn as a measured 0 (heatmap, lines) and counted into the
     * boxplot's spread (A = {S1: 10, S2: 20} with an empty S3 cell had the median 10, not 15).
     */
    public function testAnEmptyCellIsZeroForAnAddingMeasureAndNullForMinMax(): void
    {
        $this->assertSame(0, ChartPivot::emptyCell(SummaryCondition::SUM));
        $this->assertSame(0, ChartPivot::emptyCell(SummaryCondition::COUNT));
        $this->assertSame(0, ChartPivot::emptyCell(null), 'a group column as the measure adds up');
        $this->assertNull(ChartPivot::emptyCell(SummaryCondition::MIN));
        $this->assertNull(ChartPivot::emptyCell(SummaryCondition::MAX));
        $this->assertNull(ChartPivot::emptyCell((int) SummaryCondition::MIN), 'the condition may come as an int');

        $rows = [
            ['x' => 'A', 'x_text' => 'A', 'series' => 'S1', 'y' => '10'],
            ['x' => 'A', 'x_text' => 'A', 'series' => 'S2', 'y' => '20'],
            ['x' => 'B', 'x_text' => 'B', 'series' => 'S3', 'y' => '30'],
            ['x' => 'B', 'x_text' => 'B', 'series' => 'S1', 'y' => '5'],
        ];
        $this->assertSame([[10.0, 5.0], [20.0, null], [null, 30.0]], ChartPivot::build($rows, SummaryCondition::MIN)['matrix']);
        $this->assertSame([[10.0, 5.0], [20.0, 0], [0, 30.0]], ChartPivot::build($rows, SummaryCondition::SUM)['matrix'], 'unchanged for SUM');
    }

    /**
     * DATA-05: two records that share a name (山田商事, id 1 and 2) behind the series column are
     * two series, as the single-series chart keeps them two points — they were one series
     * holding both records' sums (872,900 + 815,500 = 1,688,400).
     */
    public function testSeriesAreToldApartByTheirStoredValue(): void
    {
        $rows = [
            ['x' => 'A', 'x_text' => 'A', 'series_key' => '1', 'series' => '山田商事', 'y' => '872900'],
            ['x' => 'A', 'x_text' => 'A', 'series_key' => '2', 'series' => '山田商事', 'y' => '815500'],
            ['x' => 'B', 'x_text' => 'B', 'series_key' => '1', 'series' => '山田商事', 'y' => '798000'],
            ['x' => 'B', 'x_text' => 'B', 'series_key' => '3', 'series' => '鈴木工業', 'y' => '858400'],
        ];
        $pivot = ChartPivot::build($rows, SummaryCondition::SUM);
        $this->assertSame(['山田商事', '山田商事', '鈴木工業'], $pivot['series_names'], 'named by their text, one series per stored value');
        $this->assertSame([[872900.0, 798000.0], [815500.0, 0], [0, 858400.0]], $pivot['matrix']);

        // rows without a stored value: told apart by their text, as before
        $rows = array_map(function ($row) {
            unset($row['series_key']);
            return $row;
        }, $rows);
        $this->assertSame(['山田商事', '鈴木工業'], ChartPivot::build($rows, SummaryCondition::SUM)['series_names']);
    }

    public function testAValueThatIsNoNumberFoldsNothing(): void
    {
        $rows = [
            ['x' => 'a', 'x_text' => 'A', 'series' => 's', 'y' => null],
            ['x' => 'a', 'x_text' => 'A', 'series' => 's', 'y' => '3'],
            ['x' => 'a', 'x_text' => 'A', 'series' => 's', 'y' => 'abc'],
            ['x' => 'b', 'x_text' => 'B', 'series' => 's', 'y' => null],
        ];
        $this->assertSame([[3.0, null]], ChartPivot::build($rows, SummaryCondition::MIN)['matrix'], 'a null row is no 0 that MIN would keep');
        $this->assertSame([[3.0, 0]], ChartPivot::build($rows, SummaryCondition::SUM)['matrix']);
        $this->assertSame(['A', 'B'], ChartPivot::build($rows, SummaryCondition::SUM)['x_categories'], 'the category is there without a value');
    }

    public function testACategoryIsOneStoredValueEvenWhenTextsCoincide(): void
    {
        // two students named 田中 (select_table ids 5 and 8): two bars, and a click on either picks its own id
        $rows = [
            ['x' => '5', 'x_text' => '田中', 'series' => '国語', 'y' => '80'],
            ['x' => '8', 'x_text' => '田中', 'series' => '国語', 'y' => '60'],
            ['x' => '5', 'x_text' => '田中', 'series' => '数学', 'y' => '70'],
        ];
        $pivot = ChartPivot::build($rows, SummaryCondition::SUM);
        $this->assertSame(['田中', '田中'], $pivot['x_categories']);
        $this->assertSame(['5', '8'], $pivot['x_values']);
        $this->assertSame([[80.0, 60.0], [70.0, 0]], $pivot['matrix']);
    }

    public function testStoredValuesCompareAsTheirText(): void
    {
        $rows = [
            ['x' => '7', 'x_text' => '7', 'series' => 's', 'y' => 1],
            ['x' => '007', 'x_text' => '007', 'series' => 's', 'y' => 2],
            ['x' => 7, 'x_text' => '7', 'series' => 's', 'y' => 3],
            ['x' => null, 'x_text' => null, 'series' => null, 'y' => 4],
        ];
        $pivot = ChartPivot::build($rows, SummaryCondition::SUM);
        $this->assertSame(['7', '007', ''], $pivot['x_values'], '"7" and "007" stay apart, 7 is "7", null is the empty value');
        $this->assertSame(['7', '007', ''], $pivot['x_categories']);
        $this->assertSame(['s', ''], $pivot['series_names']);
        $this->assertSame([[4.0, 2.0, 0], [0, 0, 4.0]], $pivot['matrix']);
        $this->assertSame(['x_categories' => [], 'x_values' => [], 'series_names' => [], 'matrix' => []], ChartPivot::build([], SummaryCondition::SUM), 'no rows');
    }

    public function testFold(): void
    {
        $this->assertSame(7.0, ChartPivot::fold(3.0, 4.0, SummaryCondition::SUM));
        $this->assertSame(7.0, ChartPivot::fold(3.0, 4.0, SummaryCondition::COUNT));
        $this->assertSame(3.0, ChartPivot::fold(3.0, 4.0, SummaryCondition::MIN));
        $this->assertSame(4.0, ChartPivot::fold(3.0, 4.0, SummaryCondition::MAX));
        $this->assertSame(7.0, ChartPivot::fold(3.0, 4.0, null), 'no condition: the sum');
        $this->assertSame(3.0, ChartPivot::fold(3.0, 4.0, (int) SummaryCondition::MIN), 'the condition may come as an int');
    }
}
