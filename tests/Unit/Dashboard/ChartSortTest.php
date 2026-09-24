<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Services\Dashboard\ChartSort;
use Exceedone\Exment\Services\Dashboard\ChartSorter;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;

class ChartSortTest extends DashboardUnitTestCase
{
    public function testParse()
    {
        $sort = ChartSort::parse('x1:desc');
        $this->assertSame('x1', $sort->field);
        $this->assertTrue($sort->desc);
        $this->assertSame(1, $sort->xIndex());
        $this->assertFalse($sort->byValue());

        $measure = ChartSort::parse('y:asc');
        $this->assertTrue($measure->byValue());
        $this->assertSame(-1, $measure->xIndex());
        $this->assertFalse($measure->desc);

        foreach ([null, '', 'desc', 'y', 'x:asc', 'z0:asc', 'x0:down', ['y:asc'], 'x123:asc'] as $bad) {
            $this->assertNull(ChartSort::parse($bad), 'rejects ' . json_encode($bad));
        }
    }

    public function testValueOrderKeepsNonNumericLastAndTiesStable()
    {
        $this->assertSame([2, 0, 3, 1, 4], ChartSorter::order([5, null, 9, 5, 'x'], true, true), 'ties keep their order; null and text last');
        $this->assertSame([0, 3, 2, 1, 4], ChartSorter::order([5, null, 9, 5, 'x'], false, true));
    }

    public function testTextOrderIsNaturalAndCaseInsensitive()
    {
        $labels = ['10年', '2年', 'b', 'A', '1年'];
        $this->assertSame([4, 1, 0, 3, 2], ChartSorter::order($labels, false, false), '"2年" before "10年"; A before b');
        $this->assertSame([2, 3, 0, 1, 4], ChartSorter::order($labels, true, false));
    }

    public function testSingleSeriesByMeasureMovesEverythingTogether()
    {
        $result = [
            'chart_label' => collect(['東北', '関東', '北海道']),
            'chart_data' => collect([62.3, 69.3, 69.7]),
            'chart_fields' => [['東北', '関東', '北海道']],
            'chart_counts' => [48000, 48000, 47990],
            'chart_click' => ['column' => 'region', 'values' => ['2', '3', '1']],
            'axisx_label' => '地方',
        ];
        $sorted = ChartSorter::applySingle($result, ChartSort::parse('y:desc'));
        $this->assertSame(['北海道', '関東', '東北'], $sorted['chart_label']);
        $this->assertSame([69.7, 69.3, 62.3], $sorted['chart_data']);
        $this->assertSame([['北海道', '関東', '東北']], $sorted['chart_fields']);
        $this->assertSame([47990, 48000, 48000], $sorted['chart_counts'], 'the record counts follow their points');
        $this->assertSame(['1', '3', '2'], $sorted['chart_click']['values'], 'a bar keeps its filter value');
        $this->assertSame('地方', $sorted['axisx_label'], 'other keys untouched');
    }

    public function testSingleSeriesByOneOfSeveralXColumns()
    {
        // a view grouped by 都道府県 then 学年: the label is "pref grade", the fields are the columns
        $result = [
            'chart_label' => ['東京 2年', '大阪 1年', '東京 1年'],
            'chart_data' => [70, 65, 68],
            'chart_fields' => [['東京', '大阪', '東京'], ['2年', '1年', '1年']],
        ];
        $byGrade = ChartSorter::applySingle($result, ChartSort::parse('x1:asc'));
        $this->assertSame(['大阪 1年', '東京 1年', '東京 2年'], $byGrade['chart_label'], 'by the 2nd column, ties keep their order');
        $this->assertSame([65, 68, 70], $byGrade['chart_data']);

        $byPref = ChartSorter::applySingle($result, ChartSort::parse('x0:desc'));
        $this->assertSame(['東京 2年', '東京 1年', '大阪 1年'], $byPref['chart_label']);

        $this->assertSame($result, ChartSorter::applySingle($result, ChartSort::parse('x2:asc')), 'a column the chart lacks changes nothing');
    }

    public function testSingleSeriesFallsBackToTheLabelWithoutFields()
    {
        $sorted = ChartSorter::applySingle(['chart_label' => ['b', 'a'], 'chart_data' => [1, 2], 'chart_click' => null], ChartSort::parse('x0:asc'));
        $this->assertSame(['a', 'b'], $sorted['chart_label']);
        $this->assertSame([2, 1], $sorted['chart_data']);
        $this->assertNull($sorted['chart_click']);
    }

    public function testMultiSeriesRanksCategoriesByTotalOrByText()
    {
        $result = [
            'x_categories' => ['1学期', '2学期', '3学期'],
            'series_names' => ['国語', '算数'],
            'matrix' => [[73.4, 75.0, 60.0], [67.4, 57.0, 80.0]],   // totals 140.8, 132.0, 140.0
            'chart_click' => ['column' => 'semester', 'values' => ['1', '2', '3']],
        ];
        $byTotal = ChartSorter::applyMulti($result, ChartSort::parse('y:desc'));
        $this->assertSame(['1学期', '3学期', '2学期'], $byTotal['x_categories']);
        $this->assertSame([[73.4, 60.0, 75.0], [67.4, 80.0, 57.0]], $byTotal['matrix'], 'every series row follows the new column order');
        $this->assertSame(['1', '3', '2'], $byTotal['chart_click']['values']);
        $this->assertSame(['国語', '算数'], $byTotal['series_names'], 'series order is not the sort\'s business');

        $this->assertSame(['3学期', '2学期', '1学期'], ChartSorter::applyMulti($result, ChartSort::parse('x0:desc'))['x_categories']);
        $this->assertSame($result, ChartSorter::applyMulti($result, ChartSort::parse('x1:asc')), 'the series column is not an X field');
    }
}
