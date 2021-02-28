<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;

class PublicForm extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->blueprintResolver(function($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

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

        $schema->table('notifies', function (ExtendedBlueprint $table) {
            if (!Schema::hasColumn('notifies', 'target_id')) {
                $table->integer('target_id')->unsigned()->index()->after('suuid')->nullable();
            }
        });

        \Artisan::call('exment:patchdata', ['action' => 'publicform_mail_template']);
        \Artisan::call('exment:patchdata', ['action' => 'append_column_mail_from_view_name']);
        \Artisan::call('exment:patchdata', ['action' => 'notify_target_id']);

        // Remove resouce laravel-admin show
        $path = base_path('resources/views/vendor/admin/show/panel.blade.php');
        if(\File::exists($path)){
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
