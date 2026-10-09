<?php

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (!\Exceedone\Exment\Services\Line\LineInstaller::systemTemplateImported()) {
            return;
        }

        \Exceedone\Exment\Services\Line\LineInstaller::ensureFlexTemplateTable();
    }

    public function down(): void
    {
        $table = CustomTable::getEloquent('line_flex_template');
        if (!$table) {
            return;
        }
        if (!\Exceedone\Exment\Services\Line\LineInstaller::isOwnedTable($table, \Exceedone\Exment\Services\Line\LineInstaller::OWNED_MARKERS['line_flex_template'])) {
            return;
        }
        $table->system_flg = false;
        $table->save();
        $table->dropTable();
        $table->delete();
        System::clearCache();
    }
};
