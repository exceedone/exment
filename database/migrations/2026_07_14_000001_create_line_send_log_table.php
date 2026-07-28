<?php

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Database\Migrations\Migration;

/**
 * Send-history table for LINE pushes.
 * Creation logic lives in LineInstaller::ensureSendLogTable().
 */
return new class extends Migration
{
    public const TABLE_NAME = 'line_send_log';

    public function up(): void
    {
        // Fresh install: skip while the system template is not imported —
        // InstallSeeder creates this table after importSystemTemplate().
        // (A custom_tables row created during plain "migrate" would make
        // InstallService::getStatus() skip seeding entirely.)
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
        $table->system_flg = false;
        $table->save();
        $table->dropTable();
        $table->delete();
        System::clearCache();
    }
};
