<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The history of the changes of the role groups (ISS02840): who, when, which group, what was
     * added or removed (a permission, a member). Ids only, besides the name of the group. Read by
     * the IT audit report; kept as long as the log management says (LogCatalog).
     *
     * @return void
     */
    public function up()
    {
        if (Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->hasTable('permission_change_logs')) {
            return;
        }
        Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->create('permission_change_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('role_group_id')->unsigned()->nullable();
            // the name of the group then (the group may be deleted later)
            $table->string('role_group_name', 256)->nullable();
            // added, removed
            $table->string('change', 16);
            // group, permission, member
            $table->string('kind', 16);
            // a permission: RoleType (0 system, 1 table, 3 plugin)
            $table->string('role_type', 8)->nullable();
            // a member: user, organization
            $table->string('target_type', 32)->nullable();
            // the table or the plugin of a permission, the user or the organization of a member
            $table->integer('target_id')->unsigned()->nullable();
            $table->string('permission', 64)->nullable();
            // the user who changed it (base user id; null: a job, the console)
            $table->integer('changed_user_id')->unsigned()->nullable();
            // page, api, plugin, user_deleted, ...
            $table->string('source', 32)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['role_group_id', 'created_at']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->dropIfExists('permission_change_logs');
    }
};
