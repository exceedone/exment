<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the `alias` column for the filter config: several columns with the same
 * meaning (e.g. status, contract_status) sharing one alias -> merge into ONE
 * filter group in the sidebar.
 *
 * Normalized at index time: the facet token uses the alias as prefix (e.g. "state=done")
 * instead of the column name -> every downstream layer (group merge, OR filter,
 * counting, chips, saved search) works correctly on its own; only a reindex is
 * needed when the alias changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meili_filter_settings') && !Schema::hasColumn('meili_filter_settings', 'alias')) {
            Schema::table('meili_filter_settings', function (Blueprint $table) {
                $table->string('alias', 40)->nullable()->after('column_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('meili_filter_settings', 'alias')) {
            Schema::table('meili_filter_settings', function (Blueprint $table) {
                $table->dropColumn('alias');
            });
        }
    }
};
