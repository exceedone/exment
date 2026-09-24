<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Database\ExtendedBlueprint;

return new class extends Migration
{
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if (!\Schema::hasTable('meili_filter_settings')) {
            $schema->create('meili_filter_settings', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->integer('custom_table_id')->unsigned();
                $table->string('column_name', 40);
                $table->string('filter_type', 20)->default('equality'); // equality | range
                $table->string('view_label')->nullable();
                $table->integer('order')->default(0);
                $table->boolean('enabled')->default(1);
                $table->timestamps();
                $table->timeusers();

                $table->unique(['custom_table_id', 'column_name']);
                $table->foreign('custom_table_id')->references('id')->on('custom_tables')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        \Schema::dropIfExists('meili_filter_settings');
    }
};
