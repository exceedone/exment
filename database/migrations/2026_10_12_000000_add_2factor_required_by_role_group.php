<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Database\ExtendedBlueprint;

/**
 * 2factor authentication required by role group, with recovery codes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // role group: whether 2factor authentication is required for the users of the role group.
        if (Schema::hasTable('role_groups') && !Schema::hasColumn('role_groups', 'require_2factor')) {
            Schema::table('role_groups', function (Blueprint $table) {
                $table->boolean('require_2factor')->default(false)->after('role_group_order');
            });
        }

        // login user: when the user, who is required 2factor but has not set it up, logged in first.
        // The grace period for setting up 2factor is counted from this time.
        if (Schema::hasTable('login_users') && !Schema::hasColumn('login_users', 'auth2fa_required_at')) {
            Schema::table('login_users', function (Blueprint $table) {
                $table->dateTime('auth2fa_required_at')->nullable()->after('auth2fa_available');
            });
        }

        $schema = DB::connection()->getSchemaBuilder();
        $schema->blueprintResolver(function ($connection, $table, $callback) {
            return new ExtendedBlueprint($connection, $table, $callback);
        });

        // recovery codes (one-time codes used instead of the one-time password of 2factor). Only the hash is stored.
        if (!Schema::hasTable('auth2factor_recovery_codes')) {
            $schema->create('auth2factor_recovery_codes', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->integer('login_user_id')->unsigned();
                $table->string('code_hash', 64);
                $table->dateTime('used_at')->nullable();
                $table->timestamps();

                $table->index(['login_user_id', 'code_hash']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auth2factor_recovery_codes');

        if (Schema::hasTable('login_users') && Schema::hasColumn('login_users', 'auth2fa_required_at')) {
            Schema::table('login_users', function (Blueprint $table) {
                $table->dropColumn('auth2fa_required_at');
            });
        }

        if (Schema::hasTable('role_groups') && Schema::hasColumn('role_groups', 'require_2factor')) {
            Schema::table('role_groups', function (Blueprint $table) {
                $table->dropColumn('require_2factor');
            });
        }
    }
};
