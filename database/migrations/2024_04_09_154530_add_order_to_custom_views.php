<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('custom_views', 'order')) {
            Schema::table('custom_views', function (Blueprint $table) {
                $table->integer('order')->after('default_flg')->default(0);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_views', function (Blueprint $table) {
            if (Schema::hasColumn('custom_views', 'order')) {
                $table->dropColumn('order');
            }
        });
    }
};
