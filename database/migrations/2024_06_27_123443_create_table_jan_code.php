<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Database\ExtendedBlueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if (!\Schema::hasTable('jan_codes')) {
            $schema->create('jan_codes', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->integer('table_id')->unsigned();
                $table->integer('target_id')->unsigned();
                $table->string('jan_code', 40);
                $table->timestamps();
                $table->timeusers();
                $table->foreign('table_id')->references('id')->on('custom_tables');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jan_codes');
    }
};
