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

        if (!\Schema::hasTable('assistant_tables')) {
            $schema->create('assistant_tables', function (ExtendedBlueprint $table) {
                $table->uuid('id')->primary();
                $table->string('status')->default('init');
                $table->json('table_draft_json')->nullable();
                $table->json('column_draft_json')->nullable();
                $table->timeusers();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_tables');
    }
};
