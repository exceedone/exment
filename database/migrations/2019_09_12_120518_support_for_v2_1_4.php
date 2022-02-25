<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Exceedone\Exment\Model\Plugin;

class SupportForV214 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \Artisan::call('exment:patchdata', ['action' => 'zip_password']);
        \Artisan::call('exment:patchdata', ['action' => 'alldata_view']);
        
        if (!Schema::hasColumn('plugins', 'plugin_types') && Schema::hasColumn('plugins', 'plugin_type')) {
            Schema::table('plugins', function (Blueprint $table) {
                $table->string('plugin_types')->after('plugin_type')->nullable();
            });
                
            foreach (Plugin::all() as $plugin) {
                $plugin->plugin_types = $plugin->plugin_type;
                $plugin->save();
            }
            
            Schema::table('plugins', function (Blueprint $table) {
                $table->dropColumn('plugin_type');
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
    }
}
