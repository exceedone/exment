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
            $table->integer('proxy_user_id')->unsigned()->index();
            $table->json('options')->nullable();
            $table->timestamps();
            $table->timeusers();

            $table->foreign('custom_form_id')->references('id')->on('custom_forms');
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

        Schema::dropIfExists('public_forms');
    }
}
