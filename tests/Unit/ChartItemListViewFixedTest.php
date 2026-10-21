<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\ViewColumnSort;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * FIXED (both since v6): a chart box on a LIST view.
 *
 * - A Y column of a related table (the parent of a child row, a select_table target) was read
 *   off the row itself as `value.<column name>`. child_table has an "integer" column as
 *   parent_table does, so the chart drew the child's own numbers under the parent's column.
 *   Y is now read through the view column's item with its pivot (view_pivot_column /
 *   view_pivot_table, as the view's list hands its items) — the way X already read correctly,
 *   which test_an_x_column_of_the_parent_table_reads_the_parent keeps.
 * - The view's sort was not applied (filterModel defaults to no sort): the points came in the
 *   database's order, not the order the view's list shows. It is applied now; a view without a
 *   sort keeps the order it had.
 *
 * The view, the parent and the child rows are saved into the test data's tables (no DDL) and
 * rolled back.
 */
class ChartItemListViewFixedTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    private const PREFIX = 'chartlistv6_';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        if (CustomTable::getEloquent('child_table') === null || CustomTable::getEloquent('parent_table') === null) {
            $this->markTestSkipped('needs parent_table / child_table (exment:inittest)');
        }
    }

    public function test_a_y_column_of_the_parent_table_reads_the_parent(): void
    {
        [$view, $x, $y] = $this->listView(['column_name' => 'text'], $this->parentColumn('integer'), []);
        $this->createRows();

        $data = $this->listData($view, $x, $y);
        $this->assertSame([777, 777], array_map('intval', $data['chart_data']), 'the parent\'s integer, not the child\'s own (1, 2)');
    }

    public function test_an_x_column_of_the_parent_table_reads_the_parent(): void
    {
        [$view, $x, $y] = $this->listView($this->parentColumn('text'), ['column_name' => 'integer'], []);
        $this->createRows();

        $data = $this->listData($view, $x, $y);
        $this->assertSame([self::PREFIX . 'parent', self::PREFIX . 'parent'], $data['chart_label'], 'the parent\'s text, not the child\'s own');
        $this->assertSame([1, 2], array_map('intval', $data['chart_data']), 'a Y column of the row\'s own table: as before');
    }

    public function test_the_view_sort_orders_the_points(): void
    {
        [$view, $x, $y] = $this->listView(['column_name' => 'text'], ['column_name' => 'integer'], [['column_name' => 'index_text', 'sort' => ViewColumnSort::DESC]]);
        $this->createRows();

        $data = $this->listData($view, $x, $y);
        $this->assertSame([self::PREFIX . 'b', self::PREFIX . 'a'], $data['chart_label'], 'index_text descending, as the view lists them');
        $this->assertSame([2, 1], array_map('intval', $data['chart_data']));
    }

    public function test_a_view_without_a_sort_keeps_the_database_order(): void
    {
        [$view, $x, $y] = $this->listView(['column_name' => 'text'], ['column_name' => 'integer'], []);
        $this->createRows();

        $data = $this->listData($view, $x, $y);
        $this->assertSame([self::PREFIX . 'a', self::PREFIX . 'b'], $data['chart_label']);
        $this->assertSame([1, 2], array_map('intval', $data['chart_data']));
    }

    /**
     * A column of parent_table in a view of child_table, stored as the view setting form
     * stores it (the pivot: the child's parent_id).
     *
     * @return array<string, mixed>
     */
    private function parentColumn(string $name): array
    {
        return [
            'column_name' => $name,
            'reference_table' => 'parent_table',
            'options' => ['view_pivot_table_id' => CustomTable::getEloquent('child_table')->id, 'view_pivot_column_id' => 'parent_id'],
        ];
    }

    /**
     * A list view of child_table with columns X and Y, narrowed to this test's rows.
     *
     * @param array<string, mixed> $xColumn
     * @param array<string, mixed> $yColumn
     * @param array<int, array<string, mixed>> $sorts
     * @return array{0: CustomView, 1: CustomViewColumn, 2: CustomViewColumn}
     */
    private function listView(array $xColumn, array $yColumn, array $sorts): array
    {
        [, $view] = $this->createCustomViewAll([
            'target_table_name' => 'child_table',
            'column_settings' => [$xColumn, $yColumn],
            'filter_settings' => [['column_name' => 'text', 'filter_condition' => FilterOption::LIKE, 'filter_value_text' => self::PREFIX]],
            'sort_settings' => $sorts,
        ]);
        System::clearRequestSession();
        $columns = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->get();
        return [$view, $columns[0], $columns[1]];
    }

    /**
     * One parent (text "…parent", integer 777) and two children a (integer 1) and b (integer 2).
     */
    private function createRows(): void
    {
        $parent = CustomTable::getEloquent('parent_table')->getValueModel();
        $parent->setValue(['text' => self::PREFIX . 'parent', 'integer' => 777]);
        $parent->saved_notify(false);
        $parent->save();

        foreach (['a' => 1, 'b' => 2] as $suffix => $integer) {
            $child = CustomTable::getEloquent('child_table')->getValueModel();
            $child->setValue(['text' => self::PREFIX . $suffix, 'index_text' => self::PREFIX . $suffix, 'integer' => $integer]);
            $child->parent_id = $parent->id;
            $child->parent_type = 'parent_table';
            $child->saved_notify(false);
            $child->save();
        }
    }

    /**
     * @return array{chart_label: array<int, mixed>, chart_data: array<int, mixed>}
     */
    private function listData(CustomView $view, CustomViewColumn $x, CustomViewColumn $y): array
    {
        $box = new DashboardBox();
        $box->options = [
            'target_table_id' => CustomTable::getEloquent('child_table')->id,
            'target_view_id' => $view->id,
            'chart_type' => 'bar',
            'chart_axisx' => ViewKindType::DEFAULT . '_' . $x->id,
            'chart_axisy' => ViewKindType::DEFAULT . '_' . $y->id,
        ];
        $item = new ChartItem($box);
        $method = new \ReflectionMethod(ChartItem::class, 'getListData');
        $method->setAccessible(true);
        $result = $method->invoke($item);
        $this->assertIsArray($result);
        /** @var \Illuminate\Support\Collection<int, mixed> $labels */
        $labels = $result['chart_label'];
        /** @var \Illuminate\Support\Collection<int, mixed> $data */
        $data = $result['chart_data'];
        return [
            'chart_label' => $labels->values()->all(),
            'chart_data' => $data->values()->all(),
        ];
    }
}
