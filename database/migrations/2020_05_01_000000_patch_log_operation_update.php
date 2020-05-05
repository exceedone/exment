<?php

use Illuminate\Database\Migrations\Migration;

class PatchLogOperationUpdate extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \Artisan::call('exment:patchdata', ['action' => 'patch_log_opelation']);
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
