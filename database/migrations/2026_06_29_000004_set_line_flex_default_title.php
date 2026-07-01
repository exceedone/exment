<?php

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Line\LineFlexBuilder;
use Illuminate\Database\Migrations\Migration;

/**
 * Đặt giá trị mặc định cho cột title của line_flex_template = "[trạng thái] tên bản ghi"
 * ([${workflow:status_name}] ${value}) -> title nêu rõ WF đang làm gì. Khi tạo
 * template mới, form tự điền sẵn; resolve biến khi gửi.
 */
return new class extends Migration
{
    public function up(): void
    {
        $column = $this->titleColumn();
        if (!$column) {
            return;
        }
        $options = $column->options ?? [];
        if (!is_nullorempty(array_get($options, 'default'))) {
            return; // đã có default, không ghi đè
        }
        $options['default'] = LineFlexBuilder::defaultTitle();
        $column->options = $options;
        $column->save();
        System::clearCache();
    }

    public function down(): void
    {
        $column = $this->titleColumn();
        if (!$column) {
            return;
        }
        $options = $column->options ?? [];
        array_forget($options, 'default');
        $column->options = $options;
        $column->save();
        System::clearCache();
    }

    protected function titleColumn(): ?CustomColumn
    {
        $table = CustomTable::getEloquent('line_flex_template');
        if (!$table) {
            return null;
        }
        return CustomColumn::getEloquent('title', $table);
    }
};
