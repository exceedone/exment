<?php

use Illuminate\Database\Migrations\Migration;

class SupportForV21 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \Artisan::call('exment:patchdata', ['action' => 'move_plugin']);
        \Artisan::call('exment:patchdata', ['action' => 'move_template']);
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
