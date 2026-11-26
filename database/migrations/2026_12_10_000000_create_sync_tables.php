<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The data sync (ISS00113): the connections to the other services, the mappings of their
     * units to the tables, the links of their items to the records, the runs and their errors.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('sync_connections')) {
            Schema::create('sync_connections', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 100);
                $table->string('connector', 40);
                $table->json('settings')->nullable();
                // the secrets, each encrypted (SyncSecrets)
                $table->json('secrets')->nullable();
                $table->boolean('enabled')->default(false);
                $table->timestamps();
                $table->integer('created_user_id')->unsigned()->nullable();
                $table->integer('updated_user_id')->unsigned()->nullable();
            });
        }
        if (!Schema::hasTable('sync_mappings')) {
            Schema::create('sync_mappings', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('sync_connection_id')->unsigned()->index();
                $table->string('name', 100);
                $table->string('remote_object', 255);
                $table->string('remote_label', 255)->nullable();
                $table->integer('custom_table_id')->unsigned();
                // import (7.3), export / both later
                $table->string('direction', 16)->default('import');
                $table->integer('interval_minutes')->unsigned()->default(30);
                // remote, local, newer
                $table->string('conflict_rule', 16)->default('remote');
                // mark, delete
                $table->string('delete_rule', 16)->default('mark');
                $table->json('field_map')->nullable();
                $table->json('options')->nullable();
                $table->boolean('enabled')->default(false);
                $table->text('cursor')->nullable();
                $table->timestamp('next_run_at')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->integer('failures')->unsigned()->default(0);
                $table->timestamp('deletes_checked_at')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('sync_links')) {
            Schema::create('sync_links', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('sync_mapping_id')->unsigned();
                $table->string('remote_id', 191);
                $table->bigInteger('custom_value_id')->unsigned()->nullable();
                $table->text('remote_url')->nullable();
                $table->timestamp('remote_updated_at')->nullable();
                $table->timestamp('local_updated_at')->nullable();
                $table->string('hash', 64)->nullable();
                $table->timestamp('remote_deleted_at')->nullable();
                $table->timestamps();

                $table->unique(['sync_mapping_id', 'remote_id']);
                $table->index(['sync_mapping_id', 'custom_value_id']);
            });
        }
        if (!Schema::hasTable('sync_runs')) {
            Schema::create('sync_runs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('sync_mapping_id')->unsigned()->index();
                $table->boolean('dry_run')->default(false);
                // schedule, manual
                $table->string('trigger', 16);
                // running, succeeded, partial, failed
                $table->string('status', 16);
                // created, updated, unchanged, skipped, marked, deleted, errors
                $table->json('counts')->nullable();
                $table->text('message')->nullable();
                $table->text('cursor')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->integer('created_user_id')->unsigned()->nullable();
            });
        }
        if (!Schema::hasTable('sync_run_errors')) {
            Schema::create('sync_run_errors', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('sync_run_id')->unsigned()->index();
                $table->string('remote_id', 191)->nullable();
                $table->text('message');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sync_run_errors');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('sync_links');
        Schema::dropIfExists('sync_mappings');
        Schema::dropIfExists('sync_connections');
    }
};
