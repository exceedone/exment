<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;
use Exceedone\Exment\Enums\LoginType;

class LoginSettingAndType extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $schema = \DB::connection()->getSchemaBuilder();
        $schema->blueprintResolver(function($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if(!Schema::hasTable('login_settings')){
            $schema->create('login_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->string('login_view_name');
                $table->string('login_type');
                $table->boolean('active_flg')->default(false);
                $table->json('options')->nullable();
                
                $table->timestamps();
                $table->timeusers();
            });
        }
        
        if(Schema::hasTable('login_users')){
            Schema::table('login_users', function (Blueprint $table) {
                if(!Schema::hasColumn('login_users', 'login_type')){
                    $table->string('login_type')->index()->default(LoginType::PURE)->after('base_user_id');
                }
            });
        }
        
        \Artisan::call('exment:patchdata', ['action' => 'login_type_sso']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if(Schema::hasTable('login_users')){
            Schema::table('login_users', function (Blueprint $table) {
                if(Schema::hasColumn('login_users', 'login_type')){
                    $table->dropColumn('login_type');
                }
            });
        }
        if (Schema::hasTable('login_settings')) {
            Schema::dropIfExists('login_settings');
        }
    }
}
