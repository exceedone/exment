<?php

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The lists of the safety check showed the stored values (safe, not_answered,
     * mail, manual, open) (#2179): "Safety status", "Channel", "Trigger type" and
     * "Status" become "select (value and name)", with the names in the language
     * of the site. The stored values do not change.
     *
     * @return void
     */
    public function up()
    {
        if (!hasTable(SystemTableName::CUSTOM_TABLE) || !hasTable('custom_columns')) {
            return;
        }
        try {
            SafetyCheckInstaller::ensureLabels();
        } catch (\Throwable $e) {
            \Log::warning('the choices of the safety check did not get their names: ' . $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // the columns keep their names
    }
};
