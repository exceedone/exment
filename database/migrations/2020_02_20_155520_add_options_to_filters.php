<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOptionsToFilters extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('revisions')) {
            Schema::table('revisions', function (Blueprint $table) {
                if (!Schema::hasColumn('revisions', 'deleted_at')) {
                    $table->timestamp('deleted_at', 0)->nullable()->after('updated_at');
                }
                if (!Schema::hasColumn('revisions', 'delete_user_id')) {
                    $table->unsignedInteger('delete_user_id', 0)->nullable()->after('create_user_id');
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
        Schema::table('revisions', function ($table) {
            if (Schema::hasColumn('revisions', 'deleted_at')) {
                $table->dropColumn('deleted_at');
            }
            if (Schema::hasColumn('revisions', 'delete_user_id')) {
                $table->dropColumn('delete_user_id');
            }
        });
    }
}
