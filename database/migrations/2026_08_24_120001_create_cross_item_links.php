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

        if (!\Schema::hasTable('cross_item_links')) {
            $schema->create('cross_item_links', function (ExtendedBlueprint $table) {
                $table->bigIncrements('id');
                $table->string('from_type', 100);
                $table->unsignedBigInteger('from_id');
                $table->string('to_type', 100);
                $table->unsignedBigInteger('to_id');
                $table->string('relation_type', 50);
                $table->string('external_key', 200)->nullable();
                $table->json('meta_json')->nullable();
                $table->timestamps();
                $table->timeusers();

                $table->index(['from_type', 'from_id'], 'cross_item_links_from_idx');
                $table->index(['to_type', 'to_id'], 'cross_item_links_to_idx');
                $table->index('relation_type', 'cross_item_links_rel_idx');
                $table->unique(['from_type', 'from_id', 'to_type', 'to_id', 'relation_type'], 'cross_item_links_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cross_item_links');
    }
};
