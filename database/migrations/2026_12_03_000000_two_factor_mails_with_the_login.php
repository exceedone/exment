<?php

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\Auth2factor\LoginMailTemplates;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The mails of the 2-step verification say where the login came from (ISS00268): the
     * templates still exactly as Exment wrote them get the new text; changed ones stay.
     *
     * @return void
     */
    public function up()
    {
        if (!hasTable(SystemTableName::CUSTOM_TABLE) || !hasTable(getDBTableName(SystemTableName::MAIL_TEMPLATE, false))) {
            return;
        }
        try {
            LoginMailTemplates::upgrade();
        } catch (\Throwable $e) {
            // the texts can be changed by hand (Admin setting → Mail template)
            \Log::warning('the mail templates of the 2-step verification were not updated: ' . $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // the texts stay
    }
};
