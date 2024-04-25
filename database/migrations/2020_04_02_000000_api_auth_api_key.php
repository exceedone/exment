<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;

class ApiAuthApiKey extends Migration
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
                if (!Schema::hasColumn('oauth_clients', 'api_key_client')) {
                    $table->boolean('api_key_client')->default(false)->after('password_client');
                }
            });
        }
        if (!Schema::hasTable('oauth_api_keys')) {
            Schema::create('oauth_api_keys', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('client_id');
                $table->string('key', 100)->index();
            });
        }

        \Artisan::call('exment:patchdata', ['action' => 'back_slash_replace']);
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
                if (Schema::hasColumn('oauth_clients', 'api_key_client')) {
                    $table->dropColumn('api_key_client');
                }
            });
        }
        if (Schema::hasTable('oauth_api_keys')) {
            Schema::dropIfExists('oauth_api_keys');
        }
    }
}
