<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
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
            \DB::statement('UPDATE '. SystemTableName::SYSTEM_AUTHORITABLE . ' SET morph_type = ? WHERE morph_type = ?;', [$prm['int'], $prm['string']]);
            \DB::statement('UPDATE '. SystemTableName::VALUE_AUTHORITABLE . ' SET morph_type = ? WHERE morph_type = ?;', [$prm['int'], $prm['string']]);
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
