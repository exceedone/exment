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

        Schema::table('custom_forms', function (Blueprint $table) {
            if (!Schema::hasColumn('custom_forms', 'options')) {
                $table->json('options')->after('default_flg')->nullable();
            }
        });
        Schema::table('custom_form_columns', function (Blueprint $table) {
            if (!Schema::hasColumn('custom_form_columns', 'suuid')) {
                $table->string('suuid', 20)->index()->after('id')->nullable();
            }
            if (!Schema::hasColumn('custom_form_columns', 'row_no')) {
                $table->integer('row_no')->after('form_column_target_id')->default(1);
            }
            if (!Schema::hasColumn('custom_form_columns', 'width')) {
                $table->integer('width')->after('column_no')->nullable();
            }
        });

        \Artisan::call('exment:patchdata', ['action' => 'publicform_mail_template']);
        \Artisan::call('exment:patchdata', ['action' => 'form_column_row_no']);
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
