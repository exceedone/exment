<?php

use Exceedone\Exment\Database\ExtendedBlueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add the `mode` column (include/exclude) for the filter config:
 * override = auto + include - exclude; manual = include.
 *
 * Uses ExtendedBlueprint so down() also works on SQL Server: the column has a
 * default value, whose constraint must be dropped before dropColumn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!\Schema::hasColumn('meili_filter_settings', 'mode')) {
            $this->schema()->table('meili_filter_settings', function (ExtendedBlueprint $table) {
                $table->string('mode', 20)->default('include')->after('filter_type');
            });
        }
    }

    public function down(): void
    {
        if (\Schema::hasColumn('meili_filter_settings', 'mode')) {
            $this->schema()->table('meili_filter_settings', function (ExtendedBlueprint $table) {
                $table->dropColumn('mode');
            });
        }
    }

    protected function schema(): \Illuminate\Database\Schema\Builder
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function ($connection, $table, $callback) {
            return new ExtendedBlueprint($connection, $table, $callback);
        });

        return $schema;
    }
};
