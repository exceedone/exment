<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The runs of the scheduled jobs (the page "Scheduled jobs", ISS02832). Only the last
     * runs of each job are kept (config exment.scheduled_jobs.keep_runs).
     */
    public function up(): void
    {
        if (!Schema::hasTable('scheduled_job_runs')) {
            Schema::create('scheduled_job_runs', function (Blueprint $table) {
                $table->bigIncrements('id');
                // the short name of the command: "exment:schedule", "exment:batch 3"
                $table->string('job_key', 191);
                $table->string('command', 1000)->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                // running, success, failed or skipped
                $table->string('result', 16);
                $table->integer('exit_code')->nullable();
                // the last 4 KB of what it wrote
                $table->text('output_tail')->nullable();
                // "system" (the scheduler) or the id of the user who ran it
                $table->string('triggered_by', 32)->default('system');
                $table->index(['job_key', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_job_runs');
    }
};
