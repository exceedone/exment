<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class WorkflowPatch extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('workflows')) {
            Schema::table('workflows', function ($table) {
                if (Schema::hasColumn('workflows', 'deleted_at')) {
                    $table->dropColumn('deleted_at');
                }
            });
        }
    }
}
