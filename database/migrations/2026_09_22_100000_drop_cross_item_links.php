<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Database\ExtendedBlueprint;

/**
 * Drop cross_item_links.
 *
 * The feature it backed was read-only: the detail screen printed the links and
 * offered no way to add, edit or remove one, so the only rows that ever appeared
 * came from a demo seeder. Removing the table with the code keeps the schema
 * honest. down() rebuilds it exactly as 2026_08_24_120001 did, so the migration
 * is reversible - but the rows are not restored, they were backed up separately.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->forgetOption('custom_tables', 'comment_link_relation');
        $this->forgetOption('custom_columns', 'cross_link_relation');

        Schema::dropIfExists('cross_item_links');
    }

    /**
     * Drop one key out of a table's "options" json column.
     *
     * Decoded in PHP rather than with JSON_REMOVE because the json functions differ
     * between MySQL and MariaDB, and this runs on both. Written back with a plain
     * update so no model event fires.
     */
    private function forgetOption(string $table, string $key): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'options')) {
            return;
        }

        $rows = DB::table($table)->select('id', 'options')->whereNotNull('options')->get();

        foreach ($rows as $row) {
            $options = json_decode($row->options, true);
            if (!is_array($options) || !array_key_exists($key, $options)) {
                continue;
            }

            unset($options[$key]);
            DB::table($table)->where('id', $row->id)->update(['options' => json_encode($options)]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
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
};
