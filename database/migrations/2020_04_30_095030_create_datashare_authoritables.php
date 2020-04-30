<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Database\ExtendedBlueprint;

class CreateDatashareAuthoritables extends Migration
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

        if(!$schema->hasTable(SystemTableName::DATA_SHARE_AUTHORITABLE)){
            $schema->create(SystemTableName::DATA_SHARE_AUTHORITABLE, function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->nullableMorphs('parent');
                $table->string('authoritable_type')->index();
                $table->string('authoritable_user_org_type')->index();
                $table->integer('authoritable_target_id')->nullable()->index();
                $table->timestamps();
                $table->timeusers();
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
        Schema::dropIfExists(SystemTableName::DATA_SHARE_AUTHORITABLE);
    }
}
