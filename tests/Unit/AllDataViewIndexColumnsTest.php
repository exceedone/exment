<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\System;

/**
 * The all-data view of a new table gets its indexed columns whichever page
 * opens the table first. CustomView::getDefault (the create and detail pages,
 * select boxes) used to make it with the system columns only, and then the
 * list showed just the ID and the dates for good.
 */
class AllDataViewIndexColumnsTest extends UnitTestBase
{
    use DatabaseTransactions;
    use CustomTableTrait;

    /**
     * @return array<int>
     */
    protected function columnIdsOf(CustomView $view): array
    {
        return $view->custom_view_columns()
            ->where('view_column_type', ConditionType::COLUMN)
            ->pluck('view_column_target_id')
            ->map(fn ($id) => intval($id))
            ->all();
    }

    public function testGetDefaultMakesTheAllDataViewWithTheIndexedColumns(): void
    {
        $table = $this->_createSimpleTable('alldata_view_test_default');
        System::clearCache();
        $column = CustomColumn::getEloquent('name', $table);
        $this->assertNotNull($column);

        $view = CustomView::getDefault($table, false);
        $this->assertNotNull($view);
        $this->assertSame(ViewKindType::ALLDATA, strval($view->view_kind_type));
        $this->assertContains(intval($column->id), $this->columnIdsOf($view));
    }

    public function testGetAllDataStillMakesItWithTheIndexedColumns(): void
    {
        $table = $this->_createSimpleTable('alldata_view_test_list');
        System::clearCache();
        $column = CustomColumn::getEloquent('name', $table);
        $this->assertNotNull($column);

        $view = CustomView::getAllData($table);
        $this->assertContains(intval($column->id), $this->columnIdsOf($view));
    }
}
