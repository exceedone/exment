<?php

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public const TABLE_NAME = 'line_send_log';

    public function up(): void
    {
        if (!\Exceedone\Exment\Services\Line\LineInstaller::systemTemplateImported()) {
            return;
        }

        \Exceedone\Exment\Services\Line\LineInstaller::ensureSendLogTable();
    }

    public function down(): void
    {
        $table = CustomTable::getEloquent(static::TABLE_NAME);
        if (!$table) {
            return;
        }
        if (!\Exceedone\Exment\Services\Line\LineInstaller::isOwnedTable($table, \Exceedone\Exment\Services\Line\LineInstaller::OWNED_MARKERS['line_send_log'])) {
            return;
        }
        $table->system_flg = false;
        $table->save();
        $table->dropTable();
        $table->delete();
        System::clearCache();
    }
};
