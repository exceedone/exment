<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('line_account_links', 'line_link_code_expires_at')) {
            Schema::table('line_account_links', function (Blueprint $table) {
                $table->timestamp('line_link_code_expires_at')->nullable()->after('line_link_code');
            });
        }

        $ttl = (int) config('exment.line.link_code_ttl_minutes', 10);
        \DB::table('line_account_links')
            ->whereNotNull('line_link_code')
            ->whereNull('line_link_code_expires_at')
            ->update(['line_link_code_expires_at' => now()->addMinutes($ttl)]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('line_account_links', 'line_link_code_expires_at')) {
            Schema::table('line_account_links', function (Blueprint $table) {
                $table->dropColumn('line_link_code_expires_at');
            });
        }
    }
};
