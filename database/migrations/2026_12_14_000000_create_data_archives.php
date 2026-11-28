<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The data archive (ISS02970): an archive file of old rows (data_archives) and the record of
     * every step (data_archive_events, on the connection of the logs when one is set).
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('data_archives')) {
            Schema::create('data_archives', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('uuid', 36)->unique();
                // system (the logs and the tables of Exment); table_rows and table come later
                $table->string('kind', 16)->default('system');
                $table->string('source', 64)->index();
                $table->unsignedInteger('custom_table_id')->nullable();
                // the rows: older than "before" by "date_column"
                $table->string('date_column', 64)->nullable();
                $table->dateTime('before')->nullable();
                $table->dateTime('range_from')->nullable();
                $table->dateTime('range_to')->nullable();
                $table->unsignedBigInteger('row_count')->default(0);
                $table->unsignedBigInteger('deleted_count')->default(0);
                $table->unsignedBigInteger('restored_count')->default(0);
                $table->unsignedBigInteger('bytes')->default(0);
                // local, backup (the disk of the backups: the server or S3)
                $table->string('disk', 16);
                $table->string('path', 255)->nullable();
                $table->string('sha256', 64)->nullable();
                // the sha256 of the ids archived, in their order
                $table->string('ids_hash', 64)->nullable();
                $table->string('exment_version', 32)->nullable();
                // writing, verified, completed, restored, failed
                $table->string('status', 16)->index();
                $table->boolean('warning')->default(false);
                $table->text('message')->nullable();
                $table->boolean('legal_hold')->default(false);
                $table->dateTime('deleted_at')->nullable()->index();
                $table->dateTime('purge_at')->nullable()->index();
                $table->dateTime('purge_notified_at')->nullable();
                $table->unsignedInteger('created_user_id')->nullable();
                $table->timestamps();
            });
        }
        $schema = Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name());
        if (!$schema->hasTable('data_archive_events')) {
            $schema->create('data_archive_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('data_archive_id')->nullable()->index();
                $table->string('source', 64)->nullable();
                // preview, archive, verify, delete_rows, restore, trash, untrash, hold, unhold, purge_notice, purge, failed
                $table->string('action', 16);
                $table->unsignedBigInteger('rows')->nullable();
                $table->string('detail', 500)->nullable();
                $table->unsignedInteger('user_id')->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->dropIfExists('data_archive_events');
        Schema::dropIfExists('data_archives');
    }
};
