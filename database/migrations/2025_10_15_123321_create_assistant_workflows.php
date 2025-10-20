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

        if (!\Schema::hasTable('assistant_workflows')) {
            $schema->create('assistant_workflows', function (ExtendedBlueprint $table) {
                $table->uuid('id')->primary();
                $table->string('status')->default('init');
                $table->string('workflow_table_name_draft')->nullable();
                $table->json('workflow_draft_json')->nullable();
                $table->json('workflow_statuses_draft_json')->nullable();
                $table->json('workflow_actions_draft_json')->nullable();

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
        Schema::dropIfExists('assistant_workflows');
    }
};
