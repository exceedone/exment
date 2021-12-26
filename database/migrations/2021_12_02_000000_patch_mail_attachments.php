<?php

use Illuminate\Database\Migrations\Migration;

class PatchMailAttachments extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \Artisan::call('exment:patchdata', ['action' => 'append_column_mail_attachments']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    }
}
