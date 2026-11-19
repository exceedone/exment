<?php

use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tokens of "forgot password" (auth.passwords.exment_admins, set by Middleware\Initialize).
 *
 * Exment reads them from the table named as in Laravel 10+ (password_reset_tokens), but no
 * migration of Exment created it: it was left to the migrations of the host application. An app
 * made from a Laravel 9 or older skeleton, or from exment-boilerplate, only has the old
 * "password_resets", so sending a reset mail failed with "Table 'password_reset_tokens' doesn't
 * exist". It is created here when missing, with the columns of the Laravel skeleton.
 *
 * Nothing to undo: when the application's own migration made the table, it is not Exment's to drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable(SystemTableName::PASSWORD_RESET)) {
            Schema::create(SystemTableName::PASSWORD_RESET, function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
    }
};
