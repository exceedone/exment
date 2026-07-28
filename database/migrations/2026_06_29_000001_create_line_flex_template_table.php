<?php

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Database\Migrations\Migration;

/**
 * Custom table that manages Flex templates for LINE (Phase 3).
 */
return new class extends Migration
{
    /** @var array<int, array{0:string,1:string,2:string}> [column_name, view_name, type] */
    protected $columns = [
        ['flex_key',      'Flexキー',      ColumnType::TEXT],
        ['template_name', 'テンプレート名', ColumnType::TEXT],
        ['title',         'タイトル',       ColumnType::TEXT],
        ['body_items',    '本文項目',       ColumnType::TEXTAREA],
        ['description',   '説明',          ColumnType::TEXTAREA],
    ];

    public function up(): void
    {
        $existing = CustomTable::getEloquent('line_flex_template');
        if ($existing) {
            // Already exists: just ensure it is a system table (cannot be deleted)
            $this->markSystem($existing);
            return;
        }

        $table = CustomTable::create([
            'table_name'      => 'line_flex_template',
            'table_view_name' => 'LINE Flexテンプレート',
            'options'         => ['search_enabled' => 1],
        ]);
        $table->createTable();

        foreach ($this->columns as $order => [$name, $view, $type]) {
            CustomColumn::create([
                'custom_table_id' => $table->id,
                'column_name'     => $name,
                'column_view_name'=> $view,
                'column_type'     => $type,
                'options'         => ['index_enabled' => 1],
                'order'           => $order + 1,
            ]);
        }

        // Use template_name as the label column (shown in list/relation/getLabel)
        $nameColumn = CustomColumn::getEloquent('template_name', $table);
        if ($nameColumn) {
            CustomColumnMulti::create([
                'custom_table_id'   => $table->id,
                'multisetting_type' => MultisettingType::TABLE_LABELS,
                'priority'          => 1,
                'options'           => ['table_label_id' => $nameColumn->id],
            ]);
        }

        // Mark as a system table so it cannot be deleted (like mail_template)
        $this->markSystem($table);
    }

    /**
     * Mark the table as a system table (system_flg=true) so it cannot be deleted.
     * system_flg is in CustomTable's $guarded, so it must be assigned directly.
     */
    protected function markSystem(CustomTable $table): void
    {
        if (boolval($table->system_flg)) {
            return;
        }
        $table->system_flg = true;
        $table->save();
        System::clearCache();
    }

    public function down(): void
    {
        $table = CustomTable::getEloquent('line_flex_template');
        if (!$table) {
            return;
        }
        // Clear the system flag first to allow deletion
        $table->system_flg = false;
        $table->save();
        $table->dropTable();
        $table->delete();
        System::clearCache();
    }
};
