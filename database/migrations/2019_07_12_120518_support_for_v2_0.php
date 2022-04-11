<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Exceedone\Exment\Database\ExtendedBlueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Notify;

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

        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if (!\Schema::hasTable(SystemTableName::ROLE_GROUP)) {
            $schema->create(SystemTableName::ROLE_GROUP, function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->string('role_group_name', 256)->index()->unique();
                $table->string('role_group_view_name', 256);
                $table->string('description', 1000)->nullable();
                $table->timestamps();
                $table->timeusers();
            });
        }
        
        if (!\Schema::hasTable(SystemTableName::ROLE_GROUP_PERMISSION)) {
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

        if (!\Schema::hasTable(SystemTableName::ROLE_GROUP_USER_ORGANIZATION)) {
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

        if (!\Schema::hasTable(SystemTableName::CUSTOM_VALUE_AUTHORITABLE)) {
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

        if (!Schema::hasColumn('notifies', 'custom_view_id')) {
            Schema::table('notifies', function (Blueprint $table) {
                $table->integer('custom_view_id')->after('custom_table_id')->unsigned()->nullable();
            });
        }

        if (!\Schema::hasTable(SystemTableName::NOTIFY_NAVBAR)) {
            $schema->create(SystemTableName::NOTIFY_NAVBAR, function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->integer('notify_id')->unsigned()->index();
                $table->nullableMorphs('parent');
                $table->integer('target_user_id')->unsigned()->index();
                $table->integer('trigger_user_id')->unsigned()->nullable();
                $table->string('notify_subject', 200)->nullable();
                $table->string('notify_body', 2000)->nullable();
                $table->boolean('read_flg')->default(false)->index();
                $table->timestamps();
                $table->timeusers();
            });
        }

        if (!Schema::hasColumn('notifies', 'notify_actions') && Schema::hasColumn('notifies', 'notify_action')) {
            Schema::table('notifies', function (Blueprint $table) {
                $table->string('notify_actions', 50)->after('notify_action')->nullable();
            });
                
            // update notify notify_action to notify_actions
            foreach (Notify::all() as $notify) {
                $notify->notify_actions = $notify->notify_action;
                $notify->save();
            }
            
            Schema::table('notifies', function (Blueprint $table) {
                $table->dropColumn('notify_action');
            });
        }

        // patch role group and mail template
        \Artisan::call('exment:patchdata', ['action' => 'role_group']);
        \Artisan::call('exment:patchdata', ['action' => 'notify_saved']);
        \Artisan::call('exment:patchdata', ['action' => 'alldata_view']);

        // remove unused role
        Schema::dropIfExists('roles');
        Schema::dropIfExists('system_authoritable');
        Schema::dropIfExists('value_authoritable');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists(SystemTableName::NOTIFY_NAVBAR);
        
        if (!\Schema::hasTable('notifies')) {
            Schema::table('notifies', function ($table) {
                $table->dropColumn('custom_view_id');
            });
        }

        Schema::dropIfExists(SystemTableName::CUSTOM_VALUE_AUTHORITABLE);
        Schema::dropIfExists(SystemTableName::ROLE_GROUP_USER_ORGANIZATION);
        Schema::dropIfExists(SystemTableName::ROLE_GROUP_PERMISSION);
        Schema::dropIfExists(SystemTableName::ROLE_GROUP);
    }
}
