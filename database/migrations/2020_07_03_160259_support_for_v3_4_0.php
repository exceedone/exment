<?php

use Illuminate\Database\Migrations\Migration;

class SupportForV340 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \Artisan::call('exment:patchdata', ['action' => 'patch_freeword_search']);
        \Artisan::call('exment:patchdata', ['action' => 'set_env']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
