<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Exceedone\Exment\Database\ExtendedBlueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Enums\SystemTableName;

class SupportForV20 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if(!\Schema::hasTable(SystemTableName::ROLE_GROUP)){
            $schema->create(SystemTableName::ROLE_GROUP, function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->string('role_group_name', 256)->index()->unique();
                $table->string('role_group_view_name', 256);
                $table->string('description', 1000)->nullable();
                $table->timestamps();
                $table->timeusers();
            });
        }
        
        if(!\Schema::hasTable(SystemTableName::ROLE_GROUP_PERMISSION)){
            $schema->create(SystemTableName::ROLE_GROUP_PERMISSION, function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->integer('role_group_id')->unsigned();
                $table->string('role_group_permission_type')->index();
                $table->integer('role_group_target_id')->nullable()->index();
                $table->json('permissions')->nullable();
                $table->timestamps();
                $table->timeusers();

                $table->foreign('role_group_id')->references('id')->on(SystemTableName::ROLE_GROUP);
            });
        }

        if(!\Schema::hasTable(SystemTableName::ROLE_GROUP_USER_ORGANIZATION)){
            $schema->create(SystemTableName::ROLE_GROUP_USER_ORGANIZATION, function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->integer('role_group_id')->unsigned();
                $table->string('role_group_user_org_type')->index();
                $table->integer('role_group_target_id')->nullable()->index();
                $table->timestamps();
                $table->timeusers();

                $table->foreign('role_group_id')->references('id')->on(SystemTableName::ROLE_GROUP);
            });
        }

        if(!\Schema::hasTable(SystemTableName::CUSTOM_VALUE_AUTHORITABLE)){
            $schema->create(SystemTableName::CUSTOM_VALUE_AUTHORITABLE, function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->nullableMorphs('parent');
                $table->string('authoritable_type')->index();
                $table->string('authoritable_user_org_type')->index();
                $table->integer('authoritable_target_id')->nullable()->index();
                $table->timestamps();
                $table->timeusers();
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
        //
        Schema::dropIfExists(SystemTableName::CUSTOM_VALUE_AUTHORITABLE);
        Schema::dropIfExists(SystemTableName::ROLE_GROUP_USER_ORGANIZATION);
        Schema::dropIfExists(SystemTableName::ROLE_GROUP_PERMISSION);
        Schema::dropIfExists(SystemTableName::ROLE_GROUP);
    }
}
