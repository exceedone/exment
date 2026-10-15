<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;

trait ForeignTableTestHelpers
{
    /**
     * Rename the installed feature table away and put a non-system same-name table with $columns in its place.
     *
     * @param array<int, string> $columns
     */
    protected function replaceWithForeignTable(string $tableName, array $columns): CustomTable
    {
        $real = CustomTable::getEloquent($tableName);
        $real->table_name = 'zz_' . $tableName . '_backup';
        $real->save();
        System::clearCache();

        $foreign = CustomTable::create([
            'table_name'      => $tableName,
            'table_view_name' => 'Customer table',
            'options'         => [],
        ]);
        foreach ($columns as $column) {
            $this->addColumnWithoutDdl($foreign, $column);
        }
        System::clearCache();
        return $foreign;
    }

    /**
     * CustomColumn's saved hook runs ALTER TABLE, which would commit the test transaction.
     */
    protected function addColumnWithoutDdl(CustomTable $table, string $columnName): void
    {
        CustomColumn::withoutEvents(function () use ($table, $columnName) {
            (new CustomColumn())->forceFill([
                'suuid'            => short_uuid(),
                'custom_table_id'  => $table->id,
                'column_name'      => $columnName,
                'column_view_name' => $columnName,
                'column_type'      => ColumnType::TEXT,
                'options'          => [],
            ])->save();
        });
        System::clearCache();
    }
}
