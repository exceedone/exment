<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SystemTableName;

class SupportForV330 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $schema = \DB::connection()->getSchemaBuilder();
        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if (!Schema::hasTable('login_settings')) {
            $schema->create('login_settings', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->string('login_view_name');
                $table->string('login_type');
                $table->boolean('active_flg')->default(false);
                $table->json('options')->nullable();

                $table->timestamps();
                $table->timeusers();
            });
        }

        if (Schema::hasTable('login_users')) {
            Schema::table('login_users', function (Blueprint $table) {
                if (!Schema::hasColumn('login_users', 'login_type')) {
                    $table->string('login_type')->index()->default(LoginType::PURE)->after('base_user_id');
                }
                if (!Schema::hasColumn('login_users', 'password_reset_flg')) {
                    $table->boolean('password_reset_flg')->default(false)->after('login_provider');
                }
                if (!Schema::hasColumn('login_users', 'remember_token')) {
                    $table->string('remember_token', 100)->nullable()->after('password');
                    ;
                }
            });
        }

        if (!$schema->hasTable(SystemTableName::DATA_SHARE_AUTHORITABLE)) {
            $schema->create(SystemTableName::DATA_SHARE_AUTHORITABLE, function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->nullableMorphs('parent');
                $table->string('authoritable_type')->index();
                $table->string('authoritable_user_org_type')->index();
                $table->integer('authoritable_target_id')->nullable()->index();
                $table->timestamps();
                $table->timeusers();
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
        $schema = DB::connection()->getSchemaBuilder();
        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if (Schema::hasTable('login_users')) {
            $schema->table('login_users', function (ExtendedBlueprint $table) {
                if (Schema::hasColumn('login_users', 'login_type')) {
                    $table->dropIndex(['login_type']);
                    $table->dropColumn('login_type');
                }
            });
        }
        if (Schema::hasTable('login_settings')) {
            Schema::dropIfExists('login_settings');
        }

        Schema::dropIfExists(SystemTableName::DATA_SHARE_AUTHORITABLE);
    }
}
