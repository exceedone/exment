<?php

use Illuminate\Database\Migrations\Migration;

class RemoveDeletedColumn extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \Artisan::call('exment:patchdata', ['action' => 'remove_deleted_column']);
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
