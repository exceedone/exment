<?php

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Database\Migrations\Migration;

/**
 * Mark the line_flex_template table as a system table (system_flg=true) for
 * databases that created this table before migration 000001 set the flag. When
 * system_flg=true, getDisabledDeleteAttribute() returns true, so the table
 * cannot be deleted (same as mail_template).
 *
 * Forward-only: does not create or drop the table, only updates the flag, so no
 * data is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = CustomTable::getEloquent('line_flex_template');
        if (!$table) {
            return; // if it does not exist, 000001 already created it with system_flg set
        }
        if (boolval($table->system_flg)) {
            return; // already a system table
        }
        // system_flg is in $guarded, so it must be assigned directly
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
