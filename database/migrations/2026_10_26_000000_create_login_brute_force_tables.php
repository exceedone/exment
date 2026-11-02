<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Database\ExtendedBlueprint;

/**
 * Protection against brute-force attacks on the login:
 * failed login attempts, IP addresses blocked by too many failures, and the last used one-time password of Google authenticator.
 */
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

        // failed login attempts. Counted to block an IP address, to show CAPTCHA and to warn of an account tried from many IP addresses.
        // Not shown as login history (a wrong password is not a login), and deleted after a few days.
        if (!Schema::hasTable('login_failures')) {
            $schema->create('login_failures', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->string('ip_address', 45)->nullable();
                // IPv4: the address. IPv6: the network of the first 64 bits ("2001:db8:1:2::/64"). See LoginIpBlockService::getIpKey
                $table->string('ip_key', 45)->nullable();
                // the user of the entered ID, if exists
                $table->integer('login_user_id')->unsigned()->nullable();
                $table->integer('base_user_id')->unsigned()->nullable();
                // the entered ID as it is
                $table->string('username')->nullable();
                // "password": wrong ID or password, "captcha": wrong CAPTCHA, "2factor": wrong one-time password or recovery code
                $table->string('reason', 32);
                $table->string('login_type', 32)->nullable();
                $table->string('login_provider')->nullable();
                // the administrators were warned of the account tried from many IP addresses by this attempt
                $table->boolean('multi_ip_warned')->default(false);
                $table->timestamps();

                $table->index(['ip_key', 'created_at']);
                $table->index(['base_user_id', 'created_at']);
                $table->index(['login_user_id', 'created_at']);
                $table->index(['username', 'created_at']);
                $table->index('created_at');
            });
        }

        // IP addresses blocked by too many failed logins. 1 record per IP address (or IPv6 network),
        // kept after the block ends to make the next block of the same IP address longer.
        if (!Schema::hasTable('login_ip_blocks')) {
            $schema->create('login_ip_blocks', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->string('ip_key', 45)->unique();
                // the last IP address that failed
                $table->string('ip_address', 45)->nullable();
                $table->string('country_code', 2)->nullable();
                $table->string('country')->nullable();
                // 1: first block, 2: blocked again, 3: blocked 3 times or more. 0: released by an administrator
                $table->integer('block_level')->default(1);
                $table->integer('failed_count')->default(0);
                // the last failure counted for this block. The next block counts only the failures after it.
                $table->integer('last_failure_id')->unsigned()->nullable();
                // login attempts refused while blocked
                $table->integer('blocked_attempts')->default(0);
                $table->string('last_username')->nullable();
                $table->dateTime('blocked_at')->nullable();
                $table->dateTime('blocked_until')->nullable();
                $table->dateTime('released_at')->nullable();
                $table->timestamps();
                $table->timeusers();

                $table->index('blocked_until');
            });
        }

        // login user: time step of the last one-time password of Google authenticator, so that the same code cannot be used twice.
        if (Schema::hasTable('login_users') && !Schema::hasColumn('login_users', 'auth2fa_last_timestep')) {
            Schema::table('login_users', function (Blueprint $table) {
                $table->bigInteger('auth2fa_last_timestep')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('login_users') && Schema::hasColumn('login_users', 'auth2fa_last_timestep')) {
            Schema::table('login_users', function (Blueprint $table) {
                $table->dropColumn('auth2fa_last_timestep');
            });
        }

        Schema::dropIfExists('login_ip_blocks');
        Schema::dropIfExists('login_failures');
    }
};
