<?php

use Illuminate\Database\Migrations\Migration;

use Exceedone\Exment\Model\System;

class SupportForV13 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // update system setting about outside api
        System::outside_api(!config('exment.disabled_outside_api', false));

        // move use_label_flg to custom_column_multi
        \Artisan::call('exment:patchdata', ['action' => 'use_label_flg']);
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
