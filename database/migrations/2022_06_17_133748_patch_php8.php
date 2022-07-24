<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Database\ExtendedBlueprint;

class PatchPhp8 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('oauth_clients')) {
            Schema::table('oauth_clients', function (Blueprint $table) {
                if (!Schema::hasColumn('oauth_clients', 'provider')) {
                    $table->string('provider')->nullable()->after('secret');
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

        if (Schema::hasTable('oauth_clients')) {
            $schema->table('oauth_clients', function (ExtendedBlueprint $table) {
                if (Schema::hasColumn('oauth_clients', 'provider')) {
                    $table->dropColumn('provider');
                }
            });
        }
    }
}
