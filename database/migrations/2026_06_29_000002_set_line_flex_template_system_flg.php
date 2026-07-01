<?php

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Database\Migrations\Migration;

/**
 * Đánh dấu bảng line_flex_template là bảng hệ thống (system_flg=true) cho các
 * DB đã tạo bảng này trước khi 000001 set cờ. Khi system_flg=true thì
 * getDisabledDeleteAttribute() trả true -> không xóa được (giống mail_template).
 *
 * Forward-only: không tạo/xóa bảng, chỉ cập nhật cờ -> không mất dữ liệu.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = CustomTable::getEloquent('line_flex_template');
        if (!$table) {
            return; // chưa có thì 000001 đã tạo kèm system_flg
        }
        if (boolval($table->system_flg)) {
            return; // đã là bảng hệ thống
        }
        // system_flg nằm trong $guarded nên phải gán trực tiếp
        $table->system_flg = true;
        $table->save();
        System::clearCache();
    }

    public function down(): void
    {
        $table = CustomTable::getEloquent('line_flex_template');
        if (!$table || !boolval($table->system_flg)) {
            return;
        }
        $table->system_flg = false;
        $table->save();
        System::clearCache();
    }
};
