<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums;
use Illuminate\Support\Facades\DB;

class CreateSummaryEtc extends Migration
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

        $schema->create('custom_view_summaries', function (ExtendedBlueprint $table) {
            $table->increments('id');
            $table->integer('custom_view_id')->unsigned();
            $table->integer('view_column_type')->default(0);
            $table->integer('view_column_target_id')->nullable();
            $table->integer('view_summary_condition')->unsigned()->default(0);
            $table->string('view_column_name', 40)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->timeusers();

            $table->foreign('custom_view_id')->references('id')->on('custom_views');
        });

        $schema->table('custom_view_columns', function($table) {
            $table->string('view_column_name', 40)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('custom_view_summaries');

        Schema::table('custom_view_columns', function($table) {
            $table->dropColumn('view_column_name');
        });
    }
}
