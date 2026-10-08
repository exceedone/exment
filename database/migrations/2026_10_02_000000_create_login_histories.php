<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Database\ExtendedBlueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function ($connection, $table, $callback) {
            return new ExtendedBlueprint($connection, $table, $callback);
        });

        if (!\Schema::hasTable('login_histories')) {
            $schema->create('login_histories', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->integer('login_user_id')->unsigned()->nullable();
                $table->integer('base_user_id')->unsigned()->nullable();
                // snapshot of the user at login time. Keep showing who logged in even after the user was renamed or deleted.
                $table->string('user_code')->nullable();
                $table->string('user_name')->nullable();
                $table->string('login_type', 32)->nullable();
                $table->string('login_provider')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('country_code', 2)->nullable();
                $table->string('country')->nullable();
                $table->string('region')->nullable();
                $table->string('city')->nullable();
                $table->text('user_agent')->nullable();
                $table->boolean('is_new_ip')->default(false);
                $table->boolean('via_remember')->default(false);
                // null: 2factor is not used, false: not verified yet, true: verified
                $table->boolean('auth_2factor_verified')->nullable();
                $table->timestamps();
                $table->timeusers();

                $table->index(['base_user_id', 'created_at']);
                $table->index('created_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_histories');
    }
};
