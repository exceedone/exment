<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;

/**
 * ChartItem hands ChartSorter the stored numbers of a numeric X column (sort_keys), so a
 * sort by that column orders numbers, not their formatted texts; any other column keeps
 * its text order and its values are not even read.
 */
class ChartItemSortKeysTest extends DashboardUnitTestCase
{
    public function testANumericColumnGivesItsStoredValues(): void
    {
        foreach ([ColumnType::INTEGER, ColumnType::DECIMAL, ColumnType::CURRENCY] as $type) {
            $keys = $this->sortKeys($this->viewColumn($type), function () {
                return collect(['1000', '-5', '1.25']);
            });
            $this->assertSame(['1000', '-5', '1.25'], $keys, $type);
        }
    }

    public function testAnyOtherColumnKeepsTheTextOrder(): void
    {
        $unread = function () {
            $this->fail('the values of a column that does not sort by number are not read');
        };
        foreach ([ColumnType::TEXT, ColumnType::SELECT, ColumnType::DATE, ColumnType::SELECT_TABLE] as $type) {
            $this->assertNull($this->sortKeys($this->viewColumn($type), $unread), $type);
        }
        $this->assertNull($this->sortKeys((object) ['custom_column' => null], $unread), 'a system column (id, created_at)');
        $this->assertNull($this->sortKeys('label', $unread), 'the record label (Define::CHARTITEM_LABEL)');
    }

    private function viewColumn(string $type): object
    {
        return (object) ['custom_column' => (object) ['column_type' => $type]];
    }

    /**
     * @param mixed $viewColumn
     * @return array<int, mixed>|null
     */
    private function sortKeys($viewColumn, callable $values): ?array
    {
        $item = (new \ReflectionClass(ChartItem::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ChartItem::class, 'numericSortKeys');
        $method->setAccessible(true);
        return $method->invoke($item, $viewColumn, $values);
    }
}
