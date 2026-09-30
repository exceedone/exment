<?php

use Exceedone\Exment\Database\ExtendedBlueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Extraction cache keyed by Exment's File UUID. The source SHA-256 allows a
 * file row to be reused without reparsing unchanged bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->blueprintResolver(function ($connection, $table, $callback) {
            return new ExtendedBlueprint($connection, $table, $callback);
        });

        if (!\Schema::hasTable('meili_attachment_texts')) {
            $schema->create('meili_attachment_texts', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->uuid('file_uuid')->unique();
                $table->char('file_hash', 64)->nullable()->index();
                $table->string('extractor_version', 20);
                $table->string('status', 40)->index();
                $table->string('parser', 100)->nullable();
                $table->string('extension', 20)->nullable();
                $table->string('mime', 191)->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->longText('text')->nullable();
                $table->unsignedInteger('text_length')->default(0);
                $table->boolean('truncated')->default(0);
                $table->string('error_code', 100)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('extracted_at')->nullable();
                $table->timestamps();
                $table->timeusers();
            });
        }
    }

    public function down(): void
    {
        \Schema::dropIfExists('meili_attachment_texts');
    }
};
