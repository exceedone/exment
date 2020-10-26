<?php

use Illuminate\Database\Migrations\Migration;

class PatchViewOnly extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \Artisan::call('exment:patchdata', ['action' => 'patch_view_only']);
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
