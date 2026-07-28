<?php

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Database\Migrations\Migration;

/**
 * Unify the system display language to Japanese: rename the English view names
 * of the line_flex_template table/columns that were created by the original
 * migration. Only values still equal to the old English defaults are renamed,
 * so names customized by the administrator are left untouched.
 */
return new class extends Migration
{
    /** @var array<string, array{0:string,1:string}> column_name => [old English, new Japanese] */
    protected $columns = [
        'flex_key'      => ['Flex key',      'Flexキー'],
        'template_name' => ['Template name', 'テンプレート名'],
        'title'         => ['Title',         'タイトル'],
        'body_items'    => ['Body items',    '本文項目'],
        'description'   => ['Description',   '説明'],
    ];

    protected const TABLE_VIEW_NAME_OLD = 'LINE Flex Template';
    protected const TABLE_VIEW_NAME_NEW = 'LINE Flexテンプレート';

    public function up(): void
    {
        $this->rename(
            static::TABLE_VIEW_NAME_OLD,
            static::TABLE_VIEW_NAME_NEW,
            collect($this->columns)
        );
    }

    public function down(): void
    {
        $this->rename(
            static::TABLE_VIEW_NAME_NEW,
            static::TABLE_VIEW_NAME_OLD,
            collect($this->columns)->map(function ($pair) {
                return [$pair[1], $pair[0]];
            })
        );
    }

    /**
     * @param string $tableFrom
     * @param string $tableTo
     * @param \Illuminate\Support\Collection $columns column_name => [from, to]
     */
    protected function rename($tableFrom, $tableTo, $columns): void
    {
        $table = CustomTable::getEloquent('line_flex_template');
        if (!$table) {
            return;
        }

        if ($table->table_view_name === $tableFrom) {
            $table->table_view_name = $tableTo;
            $table->save();
        }

        foreach ($columns as $columnName => [$from, $to]) {
            $column = CustomColumn::getEloquent($columnName, $table);
            if ($column && $column->column_view_name === $from) {
                $column->column_view_name = $to;
                $column->save();
            }
        }

        System::clearCache();
    }
};
