<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Exceedone\Exment\Database\ExtendedBlueprint;
use Illuminate\Database\Migrations\Migration;

class ExternalCustomOperation extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('custom_operations')) {
            Schema::table('custom_operations', function (Blueprint $table) {
                if (!Schema::hasColumn('custom_operations', 'operation_type')) {
                    $table->string('operation_type')->after('custom_table_id')->nullable();
                }
            });
        }
        if (Schema::hasTable('custom_operation_columns')) {
            Schema::table('custom_operation_columns', function (Blueprint $table) {
                if (!Schema::hasColumn('custom_operation_columns', 'options')) {
                    $table->json('options')->after('update_value_text')->nullable();
                }
            });
        }
        \Artisan::call('exment:patchdata', ['action' => 'init_custom_operation_type']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if (Schema::hasTable('custom_operations')) {
            $schema->table('custom_operations', function (ExtendedBlueprint $table) {
                if (Schema::hasColumn('custom_operations', 'operation_type')) {
                    $table->dropColumn('operation_type');
                }
            });
        }
        if (Schema::hasTable('custom_operation_columns')) {
            $schema->table('custom_operation_columns', function (ExtendedBlueprint $table) {
                if (Schema::hasColumn('custom_operation_columns', 'options')) {
                    $table->dropColumn('options');
                }
            });
        }
    }
}
