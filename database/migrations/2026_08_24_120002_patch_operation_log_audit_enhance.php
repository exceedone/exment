<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class PatchOperationLogAuditEnhance extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $tableName = config('admin.database.operation_log_table', 'admin_operation_log');

        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (!Schema::hasColumn($tableName, 'event_type')) {
                $table->string('event_type', 40)->nullable()->index();
            }
            if (!Schema::hasColumn($tableName, 'resource_type')) {
                $table->string('resource_type', 100)->nullable()->index();
            }
            if (!Schema::hasColumn($tableName, 'resource_id')) {
                $table->unsignedBigInteger('resource_id')->nullable()->index();
            }
            if (!Schema::hasColumn($tableName, 'before_json')) {
                $table->json('before_json')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'after_json')) {
                $table->json('after_json')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'diff_json')) {
                $table->json('diff_json')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'request_id')) {
                $table->string('request_id', 40)->nullable()->index();
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
        $tableName = config('admin.database.operation_log_table', 'admin_operation_log');

        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            foreach ([
                'event_type',
                'resource_type',
                'resource_id',
                'before_json',
                'after_json',
                'diff_json',
                'request_id',
            ] as $column) {
                if (Schema::hasColumn($tableName, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
