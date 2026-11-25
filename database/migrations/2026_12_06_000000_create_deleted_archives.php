<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The archive of the deleted data that was deleted for good (ISS00147): the row, its child
     * rows, comments, revisions and the files, written by exment:trash-purge just before the
     * row is deleted for good, so that it can be looked at or restored later.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('deleted_archives')) {
            return;
        }
        Schema::create('deleted_archives', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('custom_table_id')->unsigned()->nullable();
            $table->string('custom_table_name', 256);
            // the id the row had
            $table->bigInteger('value_id')->unsigned();
            // the value and the system columns of the row
            $table->json('value')->nullable();
            // the rows of the 1:n relations, with their own children
            $table->json('children')->nullable();
            $table->json('comments')->nullable();
            $table->json('revisions')->nullable();
            // the rows of the files, and where the copy of each one is
            $table->json('files')->nullable();
            // when the row was deleted (to the trash)
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            // null: the scheduler
            $table->integer('archived_by')->unsigned()->nullable();
            // when the archive itself may be removed (null: kept)
            $table->date('purge_after')->nullable();
            $table->timestamps();

            $table->index(['custom_table_id', 'value_id']);
            $table->index('archived_at');
            $table->index('purge_after');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('deleted_archives');
    }
};
