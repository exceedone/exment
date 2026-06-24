<?php

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** @var array<int, array{0:string,1:string,2:string}> */
    protected $columns = [
        ['line_user_id',   'LINE userId',    ColumnType::TEXT],
        ['line_link_code', 'LINE link code', ColumnType::TEXT],
        ['line_linked_at', 'LINE linked at', ColumnType::DATETIME],
    ];

    public function up(): void
    {
        $table = CustomTable::getEloquent(SystemTableName::USER);
        if (!$table) {
            return;
        }
        foreach ($this->columns as [$name]) {
            if ($column = CustomColumn::getEloquent($name, $table)) {
                $column->delete();
            }
        }
    }

    public function down(): void
    {
        $table = CustomTable::getEloquent(SystemTableName::USER);
        if (!$table) {
            return;
        }
        foreach ($this->columns as [$name, $view, $type]) {
            if (CustomColumn::getEloquent($name, $table)) {
                continue;
            }
            $column = new CustomColumn();
            $column->custom_table_id  = $table->id;
            $column->column_name      = $name;
            $column->column_view_name = $view;
            $column->column_type      = $type;
            $column->options          = [];
            $column->order            = 0;
            $column->save();
        }
    }
};
