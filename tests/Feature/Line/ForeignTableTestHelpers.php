<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
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
     *
     * @param array<string, mixed> $options
     */
    protected function addColumnWithoutDdl(CustomTable $table, string $columnName, string $columnType = ColumnType::TEXT, array $options = []): void
    {
        CustomColumn::withoutEvents(function () use ($table, $columnName, $columnType, $options) {
            (new CustomColumn())->forceFill([
                'suuid'            => short_uuid(),
                'custom_table_id'  => $table->id,
                'column_name'      => $columnName,
                'column_view_name' => $columnName,
                'column_type'      => $columnType,
                'options'          => $options,
            ])->save();
        });
        System::clearCache();
    }

    /**
     * An admin's own table (no physical table: never pass it to getModelName()) that selects $target
     * in a SELECT_TABLE column, as the admin screen would let them build.
     */
    protected function createTableSelecting(CustomTable $target): CustomTable
    {
        $table = CustomTable::create([
            'table_name'      => 'zz_admin_ref_' . $target->table_name,
            'table_view_name' => 'Admin table',
            'options'         => [],
        ]);
        $this->addColumnWithoutDdl($table, 'ref', ColumnType::SELECT_TABLE, ['select_target_table' => $target->id]);
        return $table;
    }

    /**
     * An admin's own table made a 1:n child of $parent. ONE_TO_MANY creates no pivot table, so no DDL.
     */
    protected function createChildTableOf(CustomTable $parent): CustomTable
    {
        $child = CustomTable::create([
            'table_name'      => 'zz_admin_child_' . $parent->table_name,
            'table_view_name' => 'Admin child table',
            'options'         => [],
        ]);
        (new CustomRelation())->forceFill([
            'parent_custom_table_id' => $parent->id,
            'child_custom_table_id'  => $child->id,
            'relation_type'          => RelationType::ONE_TO_MANY,
        ])->save();
        System::clearCache();
        return $child;
    }
}
