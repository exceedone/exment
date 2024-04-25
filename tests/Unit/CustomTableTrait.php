<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;

trait CustomTableTrait
{
    protected function _createSimpleTable(string $table_name)
    {
        /** @var CustomTable $custom_table */
        $custom_table = CustomTable::create([
            'table_name' => $table_name,
            'table_view_name' => $table_name . ' view',
            'options' => [
                'search_enabled' => 1,
            ],
        ]);
        $custom_column = CustomColumn::create([
            'custom_table_id' => $custom_table->id,
            'column_name' => 'name',
            'column_view_name' => 'name view',
            'column_type' => ColumnType::TEXT,
            'options' => [
                'index_enabled' => 1,
                'freeword_search' => '1'
            ],
        ]);
        return $custom_table;
    }
}
