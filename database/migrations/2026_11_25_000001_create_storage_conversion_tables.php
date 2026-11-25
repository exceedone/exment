<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hybrid storage Stage B (block 3): persistent state of the internal conversion orchestration.
 *
 *  - custom_table_storage_runs         : one backfill or verification run of a table at one
 *                                        storage generation: work boundary, checkpoint, status,
 *                                        result and counts (L5 counts per column and reason).
 *                                        Counts only - never an original cell value.
 *  - custom_table_storage_pending_rows : rows a run could not settle yet (concurrent update,
 *                                        verification mismatch), recorded in the same transaction
 *                                        that moves the checkpoint past them; ids and column
 *                                        names only.
 *  - custom_table_storage_transitions  : audit of every storage state transition, with the
 *                                        operator's drain attestation where one is required.
 *
 * Idempotent: each table is created only when missing; down() drops only these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('custom_table_storage_runs')) {
            Schema::create('custom_table_storage_runs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('custom_table_id')->index();
                $table->string('kind', 16);
                $table->unsignedInteger('generation');
                $table->string('status', 16)->default('running');
                $table->string('result', 16)->nullable();
                $table->unsignedBigInteger('boundary_id')->nullable();
                $table->unsignedBigInteger('checkpoint_id')->default(0);
                $table->json('counts')->nullable();
                $table->string('error', 1000)->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->timestamps();
                $table->index(['custom_table_id', 'kind', 'status']);
            });
        }

        if (!Schema::hasTable('custom_table_storage_pending_rows')) {
            Schema::create('custom_table_storage_pending_rows', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('run_id');
                $table->unsignedInteger('custom_table_id');
                $table->unsignedBigInteger('row_id');
                $table->string('reason', 32);
                $table->json('columns')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamps();
                $table->unique(['run_id', 'row_id']);
            });
        }

        if (!Schema::hasTable('custom_table_storage_transitions')) {
            Schema::create('custom_table_storage_transitions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('custom_table_id')->index();
                $table->string('from_state', 32);
                $table->string('to_state', 32);
                $table->unsignedInteger('from_generation');
                $table->unsignedInteger('to_generation');
                $table->json('attestation')->nullable();
                $table->unsignedInteger('actor_user_id')->nullable();
                $table->dateTime('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['custom_table_storage_transitions', 'custom_table_storage_pending_rows', 'custom_table_storage_runs'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
