<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;

class PluginView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('custom_views')) {
            Schema::table('custom_views', function (Blueprint $table) {
                if (!Schema::hasColumn('custom_views', 'custom_options')) {
                    $table->json('custom_options')->after('options')->nullable();
                }
            });
        }
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

        if (Schema::hasTable('custom_views')) {
            $schema->table('custom_views', function (ExtendedBlueprint $table) {
                if (Schema::hasColumn('custom_views', 'custom_options')) {
                    $table->dropColumn('custom_options');
                }
            });
        }
    }
}
