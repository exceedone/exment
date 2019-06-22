<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class PluginCustomOption extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->json('custom_options')->nullable()->after('options');
        });
        Schema::table('custom_relations', function (Blueprint $table) {
            $table->json('options')->nullable()->after('relation_type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
