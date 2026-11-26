<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The record of the logins (ISS02839): who, when, from where, how, and what came of it (a
     * success, a failure and why, a lockout, a logout). Read by the log management and by the IT
     * audit report (ISS02840); kept as long as the log management says (LogCatalog).
     *
     * @return void
     */
    public function up()
    {
        if (Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->hasTable('login_logs')) {
            return;
        }
        Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->create('login_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('login_user_id')->unsigned()->nullable();
            $table->integer('base_user_id')->unsigned()->nullable();
            // what was typed (an unknown user has no record)
            $table->string('login_name', 256)->nullable();
            // LoginType: pure, oauth, saml, ldap
            $table->string('provider', 32)->nullable();
            // the name of the login setting (an SSO or LDAP provider)
            $table->string('provider_name', 64)->nullable();
            // success, failure, lockout, logout
            $table->string('result', 16);
            // a failure: wrong_password, unknown_user, 2fa_failed
            $table->string('failure_reason', 32)->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['base_user_id', 'created_at']);
            $table->index(['result', 'created_at']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->dropIfExists('login_logs');
    }
};
