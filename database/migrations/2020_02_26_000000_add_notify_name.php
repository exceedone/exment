<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddNotifyName extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('notifies')) {
            Schema::table('notifies', function (Blueprint $table) {
                if (!Schema::hasColumn('notifies', 'notify_name')) {
                    $table->string('notify_name', 256)->nullable()->after('suuid');
                }
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
        Schema::table('notifies', function ($table) {
            if (Schema::hasColumn('notifies', 'notify_name')) {
                $table->dropColumn('notify_name');
            }
        });
    }
}
