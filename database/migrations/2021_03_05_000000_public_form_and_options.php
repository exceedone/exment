<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;

class PublicFormAndOptions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

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

        if (!Schema::hasTable('public_forms')) {
            $schema->create('public_forms', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->uuid('uuid')->unique();
                $table->integer('custom_form_id')->unsigned();
                $table->string('public_form_view_name', 256);
                $table->boolean('active_flg')->default(false);
                $table->integer('proxy_user_id')->unsigned()->index();
                $table->json('options')->nullable();
                $table->timestamps();
                $table->timeusers();
    
                $table->foreign('custom_form_id')->references('id')->on('custom_forms');
            });
        }

        $schema->table('notifies', function (ExtendedBlueprint $table) {
            if (!Schema::hasColumn('notifies', 'target_id')) {
                $table->integer('target_id')->unsigned()->index()->after('suuid')->nullable();
            }
        });

        \Artisan::call('exment:patchdata', ['action' => 'notify_target_id']);
        \Artisan::call('exment:patchdata', ['action' => 'append_column_mail_from_view_name']);
        \Artisan::call('exment:patchdata', ['action' => 'publicform_mail_template']);
        \Artisan::call('exment:patchdata', ['action' => 'form_column_row_no']);
        \Artisan::call('exment:patchdata', ['action' => 'set_file_type']);

        // Remove resouce laravel-admin show
        $path = base_path('resources/views/vendor/admin/show/panel.blade.php');
        if (\File::exists($path)) {
            \File::delete($path);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
        Schema::dropIfExists('public_forms');
    }
}
