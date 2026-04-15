<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('plugins', 'local')) {
            Schema::table('plugins', function (Blueprint $table) {
                $table->boolean('local')->default(true)->after('active_flg');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plugins', 'local')) {
            Schema::table('plugins', function (Blueprint $table) {
                $table->dropColumn('local');
            });
        }
    }
};
