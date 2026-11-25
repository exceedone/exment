<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\FilterOption;
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
        $expected = collect($data)->pluck('id')->map(fn ($id) => intval($id))->sort()->values()->all();
        $this->assertGreaterThan(0, count($expected), 'the filter finds records');

        // as the API viewdata reads it
        $paginator = $custom_view->getDataPaginate(['maxCount' => 1000, 'executeSearch' => false, 'isApi' => true]);
        $ids = collect($paginator->items())->pluck('id')->map(fn ($id) => intval($id))->sort()->values()->all();

        $this->assertSame($expected, $ids);
        $this->assertSame(count($expected), $paginator->total());
    }
}
