<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\FilterSearchType;

class CreatePasswordHistories extends Migration
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

        $schema->create('password_histories', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('login_user_id')->unsigned()->index();
            $table->string('password', 1000);
            $table->timestamps();
            $table->timeusers();
        });

        // update system setting
        System::api_available(config('exment.api', false));
        System::filter_search_type(config('exment.filter_search_full', false) ? FilterSearchType::ALL : FilterSearchType::FORWARD);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // remove password_histories
        Schema::dropIfExists('password_histories');
    }
}
