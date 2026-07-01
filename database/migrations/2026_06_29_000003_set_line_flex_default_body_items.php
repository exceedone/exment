<?php

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Line\LineFlexBuilder;
use Illuminate\Database\Migrations\Migration;

/**
 * "Bảng là nguồn chính": điền sẵn giá trị mặc định cho cột body_items của
 * line_flex_template = 5 trường workflow chuẩn (status/created_user/action/
 * executed_user/comment). Khi tạo template mới, form tự điền sẵn -> bảng khớp thẻ.
 */
return new class extends Migration
{
    public function up(): void
    {
        $column = $this->bodyItemsColumn();
        if (!$column) {
            return;
        }
        $options = $column->options ?? [];
        if (!is_nullorempty(array_get($options, 'default'))) {
            return; // đã có default, không ghi đè
        }
        $options['default'] = LineFlexBuilder::defaultBodyItems();
        $column->options = $options;
        $column->save();
        System::clearCache();
    }

    public function down(): void
    {
        $column = $this->bodyItemsColumn();
        if (!$column) {
            return;
        }
        $options = $column->options ?? [];
        array_forget($options, 'default');
        $column->options = $options;
        $column->save();
        System::clearCache();
    }

    protected function bodyItemsColumn(): ?CustomColumn
    {
        $table = CustomTable::getEloquent('line_flex_template');
        if (!$table) {
            return null;
        }
        return CustomColumn::getEloquent('body_items', $table);
    }
};
