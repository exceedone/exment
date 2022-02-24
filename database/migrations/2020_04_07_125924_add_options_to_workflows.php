<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOptionsToWorkflows extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('workflows', 'options')) {
            Schema::table('workflows', function (Blueprint $table) {
                $table->json('options')->after('setting_completed_flg')->nullable();
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
        Schema::table('workflows', function ($table) {
            if (Schema::hasColumn('workflows', 'options')) {
                $table->dropColumn('options');
            }
        });
    }
}
