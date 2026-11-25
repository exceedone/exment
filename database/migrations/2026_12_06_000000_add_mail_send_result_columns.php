<?php

use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The mail send log keeps the result of each mail, and why it failed (ISS00161): the columns
     * send_result and send_error of a new installation are added to a site made before.
     *
     * @return void
     */
    public function up()
    {
        if (!hasTable(SystemTableName::CUSTOM_TABLE) || !hasTable('custom_columns')) {
            return;
        }
        try {
            \Artisan::call('exment:patchdata', ['action' => 'append_column_mail_send_result']);
        } catch (\Throwable $e) {
            \Log::warning('the result columns of the mail send log were not added: ' . $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // the columns stay
    }
};
