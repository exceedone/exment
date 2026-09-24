<?php

use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;

return new class extends Migration
{
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if (!\Schema::hasTable('meili_saved_searches')) {
            $schema->create('meili_saved_searches', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->string('name', 100);
                // owner (CustomValue user id, not login_users)
                $table->integer('owner_user_id')->unsigned()->index();
                // keyword + filter (generic JSON params: tables/date/users/facets/range)
                // text, not string: a saved keyword can exceed 255 chars and
                // must not blow up in SQL strict mode.
                $table->text('query')->nullable();
                $table->json('filters')->nullable();
                // share scope: personal | all | role_group | organization
                $table->string('share_type', 20)->default('personal');
                // shared role_group/organization ids (JSON array, used with the matching share_type)
                $table->json('share_targets')->nullable();
                $table->integer('order')->default(0);
                $table->timestamps();
                $table->timeusers();
            });
        }
    }

    public function down(): void
    {
        \Schema::dropIfExists('meili_saved_searches');
    }
};
