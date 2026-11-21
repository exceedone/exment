<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The API keys of the users (Services\UserApiKeys, the feature "api_keys"). A key is
     * shown once when it is made; only its hash is kept here.
     */
    public function up(): void
    {
        if (!Schema::hasTable('user_api_keys')) {
            Schema::create('user_api_keys', function (Blueprint $table) {
                $table->bigIncrements('id');
                // the id of the user (custom value of the table "user"), whose permissions the key has
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name', 256);
                // the first characters of the key, to tell the keys apart on the page
                $table->string('key_prefix', 32);
                // sha256 of the key (hex)
                $table->string('key_hash', 64)->unique();
                // the API scopes of the key (json array), and the tables it may use (json array, null = all)
                $table->text('scopes');
                $table->text('tables')->nullable();
                // the IP addresses or ranges it may come from (one per line, empty = any)
                $table->text('allowed_ips')->nullable();
                // the sha256 fingerprint of the client certificate it needs (hex, null = none)
                $table->string('client_cert_fingerprint', 64)->nullable();
                $table->dateTime('expires_at');
                $table->dateTime('last_used_at')->nullable();
                $table->string('last_used_ip', 64)->nullable();
                $table->unsignedBigInteger('use_count')->default(0);
                $table->dateTime('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_user_id')->nullable();
                $table->timestamps();
                $table->unsignedBigInteger('created_user_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_api_keys');
    }
};
