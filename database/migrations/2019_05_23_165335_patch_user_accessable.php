<?php

use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\SystemTableName;

class PatchUserAccessable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // update user and autority accessable
        foreach ([SystemTableName::USER, SystemTableName::ORGANIZATION] as $tableName) {
            $table = CustomTable::getEloquent($tableName);
            if (!isset($table)) {
                continue;
            }

            $table->setOption('all_user_accessable_flg', "1");
            $table->save();
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
