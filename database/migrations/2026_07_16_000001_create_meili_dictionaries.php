<?php

use Exceedone\Exment\Database\ExtendedBlueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Admin-managed relevance dictionary for Meilisearch: synonyms and stop words.
 *
 * These are index SETTINGS (not data), so changes apply in seconds via
 * exment:meili-settings WITHOUT reindexing documents. Especially useful for Japanese
 * (kana<->kanji, EN<->JA pairs, particles as stop words).
 *
 *  - type 'synonym' : `word` + `synonyms` (comma/newline list) become a mutual
 *                     synonym group (every term is a synonym of the others).
 *  - type 'stopword': `word` (comma/newline list) is added to the stop words.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function ($connection, $table, $callback) {
            return new ExtendedBlueprint($connection, $table, $callback);
        });

        if (!\Schema::hasTable('meili_dictionaries')) {
            $schema->create('meili_dictionaries', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->string('type', 20)->default('synonym'); // synonym | stopword
                $table->string('word', 255);
                $table->text('synonyms')->nullable();
                $table->integer('order')->default(0);
                $table->boolean('enabled')->default(1);
                $table->timestamps();
                $table->timeusers();
            });
        }
    }

    public function down(): void
    {
        \Schema::dropIfExists('meili_dictionaries');
    }
};
