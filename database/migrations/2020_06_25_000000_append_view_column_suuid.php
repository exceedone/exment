<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;

class AppendViewColumnSuuid extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('custom_view_columns')) {
            Schema::table('custom_view_columns', function (Blueprint $table) {
                if (!Schema::hasColumn('custom_view_columns', 'suuid')) {
                    $table->string('suuid', 20)->index()->after('id');
                }
            });
        }
        if (Schema::hasTable('custom_view_summaries')) {
            Schema::table('custom_view_summaries', function (Blueprint $table) {
                if (!Schema::hasColumn('custom_view_summaries', 'suuid')) {
                    $table->string('suuid', 20)->index()->after('id');
                }
            });
        }
        
        \Artisan::call('exment:patchdata', ['action' => 'view_column_suuid']);
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

        if (Schema::hasTable('custom_view_columns')) {
            $schema->table('custom_view_columns', function (ExtendedBlueprint $table) {
                if (Schema::hasColumn('custom_view_columns', 'suuid')) {
                    $table->dropIndex(['suuid']);
                    $table->dropColumn('suuid');
                }
            });
        }
        if (Schema::hasTable('custom_view_summaries')) {
            $schema->table('custom_view_summaries', function (ExtendedBlueprint $table) {
                if (Schema::hasColumn('custom_view_summaries', 'suuid')) {
                    $table->dropIndex(['suuid']);
                    $table->dropColumn('suuid');
                }
            });
        }
    }
}
