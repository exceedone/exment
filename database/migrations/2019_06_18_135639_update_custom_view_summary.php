<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateCustomViewSummary extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('custom_view_summaries', 'options')) {
            Schema::table('custom_view_summaries', function (Blueprint $table) {
                $table->json('options')->after('view_column_name')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('custom_view_summaries', function ($table) {
            if (Schema::hasColumn('custom_view_summaries', 'options')) {
                $table->dropColumn('options');
            }
        });
    }
}
