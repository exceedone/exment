<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class SupportForV331 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if(Schema::hasTable('custom_view_columns')){
            Schema::table('custom_view_columns', function (Blueprint $table) {
                if (!Schema::hasColumn('custom_view_columns', 'suuid')) {
                    $table->string('suuid', 20)->index()->after('id');
                }
            });
        }
        if(Schema::hasTable('custom_view_summaries')){
            Schema::table('custom_view_summaries', function (Blueprint $table) {
                if (!Schema::hasColumn('custom_view_summaries', 'suuid')) {
                    $table->string('suuid', 20)->index()->after('id');
                }
            });
        }
        
        \Artisan::call('exment:patchdata', ['action' => 'view_column_suuid']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    }
}
