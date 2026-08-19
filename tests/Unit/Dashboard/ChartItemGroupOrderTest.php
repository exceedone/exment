<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use ReflectionMethod;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\ViewKindType;

/**
 * "Top N as an ordinary chart" (box options chart_sort_value / chart_sort_natural_views /
 * chart_max_groups) — the SUM and mean data paths share ChartItem::groupOrder(), so pinning
 * it here pins the ranking contract of both: descending by value, non-numeric last, natural
 * views keep their order, cut AFTER the sort, null when nothing applies (arrays untouched).
 * Also the static series-column option builder used by the box form / chartAxis endpoint.
 */
class ChartItemGroupOrderTest extends DashboardUnitTestCase
{
    private function item(array $options): ChartItem
    {
        $this->swapRequest([]);
        return new ChartItem($this->makeBox('b1', $options));
    }

    private function order(array $options, array $values, bool $allowSort = true)
    {
        $m = new ReflectionMethod(ChartItem::class, 'groupOrder');
        $m->setAccessible(true);
        return $m->invoke($this->item($options), $values, $allowSort);
    }

    private function pick(array $items, array $order): array
    {
        $m = new ReflectionMethod(ChartItem::class, 'pickIndexes');
        $m->setAccessible(true);
        return $m->invoke(null, $items, $order);
    }

    public function testNothingConfiguredKeepsArraysUntouched(): void
    {
        $this->assertNull($this->order([], [3, 1, 2]));
        $this->assertNull($this->order(['chart_sort_value' => 'asc'], [3, 1, 2]), 'only desc sorts');
        $this->assertNull($this->order(['chart_max_groups' => 5], [3, 1, 2]), 'cap larger than the list = no change');
    }

    public function testDescendingByValueStableAndNonNumericLast(): void
    {
        $order = $this->order(['chart_sort_value' => 'desc'], [10, 30, 'x', 30, null, 20]);
        $this->assertSame([1, 3, 5, 0, 2, 4], $order, 'ties keep input order; non-numeric sort last');
        $labels = ['a', 'b', 'c', 'd', 'e', 'f'];
        $this->assertSame(['b', 'd', 'f', 'a', 'c', 'e'], $this->pick($labels, $order), 'aligned arrays follow the same order');
    }

    public function testTopNIsCutAfterTheSort(): void
    {
        $this->assertSame([1, 3, 5], $this->order(['chart_sort_value' => 'desc', 'chart_max_groups' => 3], [10, 30, 'x', 30, null, 20]));
        // cap alone keeps the incoming order
        $this->assertSame([0, 1], $this->order(['chart_max_groups' => 2], [10, 30, 20]));
        // string / junk caps: same normalization as BoxChartConfig::maxGroups
        $this->assertSame([0, 1], $this->order(['chart_max_groups' => '2'], [10, 30, 20]));
        $this->assertNull($this->order(['chart_max_groups' => 'abc'], [10, 30, 20]));
    }

    public function testCompoundLabelShapeNeverSortsButStillCuts(): void
    {
        $this->assertNull($this->order(['chart_sort_value' => 'desc'], [10, 30, 20], false));
        $this->assertSame([0, 1], $this->order(['chart_sort_value' => 'desc', 'chart_max_groups' => 2], [10, 30, 20], false), 'no ranking without a single group column, but the top-N cut still applies');
    }

    public function testNaturalOrderViewsAreNotSorted(): void
    {
        // custom_view is null in a table-less item → sortsByValue(null) → id 0
        $this->assertNull($this->order(['chart_sort_value' => 'desc', 'chart_sort_natural_views' => [0]], [10, 30, 20]));
        $this->assertSame([1, 2, 0], $this->order(['chart_sort_value' => 'desc', 'chart_sort_natural_views' => [7]], [10, 30, 20]));
    }

    public function testSeriesSelectOptionsListGroupByColumnsOfAggregateViews(): void
    {
        $col = function ($id, $text) {
            $conditionItem = new class ($text) {
                private $t;

                public function __construct($t)
                {
                    $this->t = $t;
                }

                public function getSelectColumnText($column, $table)
                {
                    return $this->t;
                }
            };
            return (object) ['id' => $id, 'condition_item' => $conditionItem];
        };
        $view = (object) [
            'view_kind_type' => ViewKindType::AGGREGATE,
            'custom_table' => null,
            'custom_view_columns_cache' => [$col(11, '学年'), $col(12, '教科'), (object) ['id' => 13, 'condition_item' => null]],
        ];
        $this->assertSame([
            ['id' => ViewKindType::DEFAULT . '_11', 'text' => '学年'],
            ['id' => ViewKindType::DEFAULT . '_12', 'text' => '教科'],
            ['id' => ViewKindType::DEFAULT . '_13', 'text' => null],
        ], ChartItem::seriesSelectOptions($view));

        $list = (object) ['view_kind_type' => ViewKindType::DEFAULT, 'custom_table' => null, 'custom_view_columns_cache' => [$col(1, 'x')]];
        $this->assertSame([], ChartItem::seriesSelectOptions($list), 'a list view has no series split');
        $this->assertSame([], ChartItem::seriesSelectOptions(null));
    }
}
