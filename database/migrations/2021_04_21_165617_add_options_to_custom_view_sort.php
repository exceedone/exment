<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOptionsToCustomViewSort extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('custom_view_sorts', function (Blueprint $table) {
            if (!Schema::hasColumn('custom_view_sorts', 'options')) {
                $table->json('options')->after('priority')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('custom_view_sorts', function (Blueprint $table) {
            if(Schema::hasColumn('custom_view_sorts', 'options')){
                $table->dropColumn('options');
            }
        });
    }
}
