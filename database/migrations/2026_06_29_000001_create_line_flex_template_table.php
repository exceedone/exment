<?php

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Database\Migrations\Migration;

/**
 * Custom table quản lý Flex template cho LINE (GĐ3).
 */
return new class extends Migration
{
    /** @var array<int, array{0:string,1:string,2:string}> [column_name, view_name, type] */
    protected $columns = [
        ['flex_key',      'Flex key',      ColumnType::TEXT],
        ['template_name', 'Template name', ColumnType::TEXT],
        ['title',         'Title',         ColumnType::TEXT],
        ['body_items',    'Body items',    ColumnType::TEXTAREA],
        ['description',   'Description',   ColumnType::TEXTAREA],
    ];

    public function up(): void
    {
        $existing = CustomTable::getEloquent('line_flex_template');
        if ($existing) {
            // đã tồn tại: chỉ đảm bảo là bảng hệ thống (không xóa được)
            $this->markSystem($existing);
            return;
        }

        $table = CustomTable::create([
            'table_name'      => 'line_flex_template',
            'table_view_name' => 'LINE Flex Template',
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

        // Đặt template_name làm cột label (nhãn hiển thị ở list/relation/getLabel)
        $nameColumn = CustomColumn::getEloquent('template_name', $table);
        if ($nameColumn) {
            CustomColumnMulti::create([
                'custom_table_id'   => $table->id,
                'multisetting_type' => MultisettingType::TABLE_LABELS,
                'priority'          => 1,
                'options'           => ['table_label_id' => $nameColumn->id],
            ]);
        }

        // Đánh dấu là bảng hệ thống: không cho xóa (giống mail_template)
        $this->markSystem($table);
    }

    /**
     * Đánh dấu bảng là bảng hệ thống (system_flg=true) -> không xóa được.
     * system_flg nằm trong $guarded của CustomTable nên phải gán trực tiếp.
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
        // bỏ cờ hệ thống trước để cho phép xóa
        $table->system_flg = false;
        $table->save();
        $table->dropTable();
        $table->delete();
        System::clearCache();
    }
};
