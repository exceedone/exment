<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The IT audit reports made as PDF (ISS02840): the period, who made it (or the job), the
     * SHA-256 of the file (to show later that a file was not changed) and, for the ones the job
     * makes each month or quarter, the file kept on the server.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('audit_reports')) {
            return;
        }
        Schema::create('audit_reports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('period_from');
            $table->date('period_to');
            // page, schedule
            $table->string('source', 16);
            $table->char('sha256', 64);
            $table->integer('size')->unsigned()->default(0);
            // the file kept (under storage/app/audit_reports), null: only its hash
            $table->string('file', 128)->nullable();
            // base user id; null: the job
            $table->integer('made_user_id')->unsigned()->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index('sha256');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('audit_reports');
    }
};
