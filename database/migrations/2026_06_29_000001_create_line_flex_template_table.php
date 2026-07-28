<?php

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Database\Migrations\Migration;

/**
 * Custom table that manages Flex templates for LINE (Phase 3).
 * Creation logic lives in LineInstaller::ensureFlexTemplateTable().
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fresh install: the system template is not imported yet. Creating a
        // custom_tables row here would make InstallService::getStatus() think
        // installation is done and skip seeding — InstallSeeder creates this
        // table instead, after importSystemTemplate().
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
        // Clear the system flag first to allow deletion
        $table->system_flg = false;
        $table->save();
        $table->dropTable();
        $table->delete();
        System::clearCache();
    }
};
