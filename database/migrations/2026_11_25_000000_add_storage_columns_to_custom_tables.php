<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hybrid storage Stage B (block 1): per-table storage metadata on custom_tables.
 *
 *  - storage_mode       : read source of the single-valued columns: 'json' | 'physical'
 *  - storage_state      : conversion phase (02 §15.7): 'json', 'provisioned', 'dualwrite',
 *                         'backfilling', 'verifying', 'awaiting_confirmation', 'physical_read',
 *                         'cleaning', 'cleaned', 'failed'
 *  - storage_generation : incremented on every change of read source or write target (§11.4)
 *
 * Every existing and every new table is 'json' / 'json' / 0: storage behaviour does not change.
 * Columns, not keys in custom_tables.options (O-3): the table form rewrites options, and a
 * state transition must be a compare-and-set on its own columns.
 * Idempotent: each column is added only when missing; down() drops only these three columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('custom_tables')) {
            return;
        }

        if (!Schema::hasColumn('custom_tables', 'storage_mode')) {
            Schema::table('custom_tables', function (Blueprint $table) {
                $table->string('storage_mode', 16)->default('json');
            });
        }
        if (!Schema::hasColumn('custom_tables', 'storage_state')) {
            Schema::table('custom_tables', function (Blueprint $table) {
                $table->string('storage_state', 32)->default('json');
            });
        }
        if (!Schema::hasColumn('custom_tables', 'storage_generation')) {
            Schema::table('custom_tables', function (Blueprint $table) {
                $table->unsignedInteger('storage_generation')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('custom_tables')) {
            return;
        }

        foreach (['storage_generation', 'storage_state', 'storage_mode'] as $column) {
            if (Schema::hasColumn('custom_tables', $column)) {
                Schema::table('custom_tables', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
