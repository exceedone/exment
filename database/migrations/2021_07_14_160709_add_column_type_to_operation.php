<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnTypeToOperation extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('custom_operation_columns', function (Blueprint $table) {
            if (!Schema::hasColumn('custom_operation_columns', 'operation_column_type')) {
                $table->integer('operation_column_type')->after('update_value_text')->default(0);
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('custom_operation_columns', function (Blueprint $table) {
            if (Schema::hasColumn('custom_operation_columns', 'operation_column_type')) {
                $table->dropColumn('operation_column_type');
            }
        });
    }
}
