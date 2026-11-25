<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * A view filtered on a column of the table a column selects (the pivot) joins that table: the
 * list of the view through the API (viewdata, getDataPaginate without a search) failed with
 * "Duplicate column name 'id'" (500). It reads the records the view filters, as the data list.
 */
class ViewDataPivotFilterTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    protected function init(): void
    {
        $this->initAllTest();
    }

    public function testFilterThroughThePivot(): void
    {
        $this->init();
        $options = [
            'filter_settings' => [[
                'reference_table' => 'custom_value_view_all',
                'column_name' => 'index_text',
                'reference_column' => 'select_table',
                'filter_condition' => FilterOption::LIKE,
                'filter_value_text' => 'index_003',
            ]],
        ];
        list($custom_view, $data) = $this->getCustomView($options);
        $expected = [];
        foreach ($data as $value) {
            $expected[] = intval($value->id);
        }
        sort($expected);
        $this->assertGreaterThan(0, count($expected), 'the filter finds records');

        // as the API viewdata reads it: a view of its own, as a request has (the search service
        // of a view joins a table once, so the view of the query above would not join it again)
        $fresh = CustomView::query()->find($custom_view->id);
        $this->assertNotNull($fresh);
        $paginator = $fresh->getDataPaginate(['maxCount' => 1000, 'executeSearch' => false, 'isApi' => true]);
        $ids = [];
        foreach ($paginator->items() as $item) {
            $ids[] = intval($item->id);
        }
        sort($ids);

        $this->assertSame($expected, $ids);
        $this->assertSame(count($expected), $paginator->total());
    }
}
