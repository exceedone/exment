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

        if (!\Schema::hasTable('assistant_messages')) {
            $schema->create('assistant_messages', function (ExtendedBlueprint $table) {
                $table->id();
                $table->uuidMorphs('conversable');
                $table->text('message_text');
                $table->enum('role', ['user', 'assistant']);
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
        Schema::dropIfExists('assistant_messages');
    }
};
