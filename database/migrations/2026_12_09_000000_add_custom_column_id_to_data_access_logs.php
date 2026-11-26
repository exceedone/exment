<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The column of a reveal of an encrypted value (ISS00148): who saw which column of which
     * record. Null for the other kinds.
     *
     * @return void
     */
    public function up()
    {
        $schema = Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name());
        if ($schema->hasTable('data_access_logs') && !$schema->hasColumn('data_access_logs', 'custom_column_id')) {
            $schema->table('data_access_logs', function (Blueprint $table) {
                $table->integer('custom_column_id')->unsigned()->nullable()->after('custom_view_id');
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        $schema = Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name());
        if ($schema->hasTable('data_access_logs') && $schema->hasColumn('data_access_logs', 'custom_column_id')) {
            $schema->table('data_access_logs', function (Blueprint $table) {
                $table->dropColumn('custom_column_id');
            });
        }
    }
};
