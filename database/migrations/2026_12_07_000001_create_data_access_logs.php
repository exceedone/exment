<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reads and the writes of the data (ISS02905): who read, listed, made, changed, deleted or
     * restored which record of which table, when, and from where (the pages, the API, MCP). Small
     * rows: no address, no value. And their counts of each day, kept longer, which the IT audit
     * report reads.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->hasTable('data_access_logs')) {
            Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->create('data_access_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('base_user_id')->unsigned()->nullable();
                $table->integer('custom_table_id')->unsigned();
                // null: a list of the table
                $table->bigInteger('record_id')->unsigned()->nullable();
                // the view of a list
                $table->integer('custom_view_id')->unsigned()->nullable();
                // read, list, export, create, update, delete, restore, force_delete
                $table->string('kind', 16);
                // page, api, mcp, system
                $table->string('via', 8);
                $table->string('ip', 64)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index('created_at');
                $table->index(['custom_table_id', 'record_id', 'created_at']);
                $table->index(['base_user_id', 'created_at']);
            });
        }
        if (!Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->hasTable('data_access_daily')) {
            Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->create('data_access_daily', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->date('date');
                $table->integer('base_user_id')->unsigned()->nullable();
                $table->integer('custom_table_id')->unsigned();
                $table->string('kind', 16);
                $table->string('via', 8);
                $table->integer('count')->unsigned();

                $table->index(['date', 'custom_table_id']);
                $table->index(['date', 'base_user_id']);
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->dropIfExists('data_access_daily');
        Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->dropIfExists('data_access_logs');
    }
};
