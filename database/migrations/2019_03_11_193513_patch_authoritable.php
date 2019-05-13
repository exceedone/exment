<?php

use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;

class PatchAuthoritable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // patch system_system_authoritable's morph_type int to string
        $prms = [];
        foreach (RoleType::values() as $key => $value) {
            $prms[] = ['int' => $value->getValue(), 'string' => $value->lowerKey()];
        }

        foreach ($prms as $prm) {
            // foreach each authoritable table
            foreach([SystemTableName::SYSTEM_AUTHORITABLE, SystemTableName::VALUE_AUTHORITABLE] as $table){
                \DB::table($table)
                    ->where('morph_type', strval($prm['int']))
                    ->update([
                        'morph_type' => $prm['string']
                    ]);
            }
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
