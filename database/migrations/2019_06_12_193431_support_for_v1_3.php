<?php

use Illuminate\Database\Migrations\Migration;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;

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
        $use_label_flg_columns = CustomColumn::whereNotIn('options->use_label_flg', [0, "0"])->orderby('options->use_label_flg')->get();
        foreach($use_label_flg_columns as $use_label_flg_column){
            $custom_table = $use_label_flg_column->custom_table;

            $custom_table->table_labels()->save(
                new CustomColumnMulti([
                    'multisetting_type' => 2,
                    'table_label_column_id' => $use_label_flg_column->id,
                    'priority' => $use_label_flg_column->getOption('use_label_flg'),
                ])
            );

            $use_label_flg_column->forgetOption('use_label_flg');
            $use_label_flg_column->save();
        }
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
