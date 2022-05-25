<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Database\ExtendedBlueprint;

class PatchLaravel8 extends Migration
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
                // https://github.com/laravel/passport/blob/10.x/UPGRADE.md#support-for-multiple-guards
                if (!Schema::hasColumn('oauth_clients', 'provider')) {
                    $table->string('provider')->nullable()->after('secret');
                }
                // https://github.com/laravel/passport/blob/10.x/UPGRADE.md#public-clients
                if (!Schema::hasColumn('oauth_clients', 'secret')) {
                    $table->string('secret', 100)->nullable()->change();
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
                if (!Schema::hasColumn('oauth_clients', 'secret')) {
                    $table->string('secret', 100)->nullable(false)->change();
                }
            });
        }
    }
}
