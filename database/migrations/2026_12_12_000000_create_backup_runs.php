<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The runs of the backup and the restore (ISS02943): who ran which, its stage and progress, its
     * heartbeat (a run that stopped is found), its result, and the place of its detailed log.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('backup_runs')) {
            return;
        }
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            // the key of the run in the URLs and the log file (the id changes when a restore puts the rows back)
            $table->string('uuid', 36)->unique();
            // backup, restore
            $table->string('kind', 16);
            // screen, command, schedule
            $table->string('triggered_by', 16);
            // queued, running, succeeded, warning, failed, stalled
            $table->string('status', 16)->index();
            $table->string('stage', 32)->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            // the units of the current stage (bytes or items), for the time left
            $table->unsignedBigInteger('stage_done')->default(0);
            $table->unsignedBigInteger('stage_total')->default(0);
            // the stages: their start, end, counts, sizes, warnings and errors
            $table->json('stages')->nullable();
            $table->json('targets')->nullable();
            // the name of the backup file (without .zip)
            $table->string('file_name', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('warnings')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->text('message')->nullable();
            // the log under storage (never in public)
            $table->string('log_path', 255)->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('created_user_id')->unsigned()->nullable();
            $table->timestamps();
            $table->index(['kind', 'created_at']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('backup_runs');
    }
};
