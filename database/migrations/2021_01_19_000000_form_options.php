<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class FormOptions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('files', function (Blueprint $table) {
            if (!Schema::hasColumn('files', 'file_type')) {
                $table->integer('file_type')->after('uuid')->nullable()->index();
            }
            if (!Schema::hasColumn('files', 'custom_form_column_id')) {
                $table->integer('custom_form_column_id')->after('custom_column_id')->nullable()->index();
            }
            if (!Schema::hasColumn('files', 'options')) {
                $table->json('options')->after('custom_form_column_id')->nullable();
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
        //
    }
}
