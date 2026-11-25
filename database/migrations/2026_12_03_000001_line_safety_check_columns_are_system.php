<?php

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\Line\LineInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The columns the safety check and LINE features made are system columns, as their tables
     * (#2160): they cannot be deleted from "Custom column settings" (a deleted column took its
     * values of all the records). Columns a user added stay deletable.
     *
     * @return void
     */
    public function up()
    {
        if (!hasTable(SystemTableName::CUSTOM_TABLE) || !hasTable('custom_columns')) {
            return;
        }
        try {
            LineInstaller::markOwnedColumns();
        } catch (\Throwable $e) {
            \Log::warning('the columns of the safety check and LINE were not marked as system columns: ' . $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // the columns stay system columns
    }
};
