<?php

use Illuminate\Database\Migrations\Migration;
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

        $schema->blueprintResolver(function ($connection, $table, $callback) {
            return new ExtendedBlueprint($connection, $table, $callback);
        });

        if (!\Schema::hasTable('cell_style_presets')) {
            $schema->create('cell_style_presets', function (ExtendedBlueprint $table) {
                $table->increments('id');
                // The suuid is what a column or a view stores, never the id:
                // it survives an export and it cannot be guessed by counting.
                $table->string('suuid', 20)->index();
                $table->string('preset_name', 40);
                // Empty means "offer this preset on every column type".
                $table->json('column_types')->nullable();
                $table->json('options')->nullable();
                $table->integer('order')->unsigned()->default(0);
                $table->timestamps();
                $table->timeusers();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cell_style_presets');
    }
};
