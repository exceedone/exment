<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The older versions of an attachment of a record (a "document"): when an
     * attachment is replaced, the file it had is kept here. The list of
     * attachments reads only the documents; this table is read when someone
     * opens the history of one attachment.
     */
    public function up(): void
    {
        if (!Schema::hasTable('document_versions')) {
            Schema::create('document_versions', function (Blueprint $table) {
                $table->bigIncrements('id');
                // the id of the document (custom value of the table "document")
                $table->unsignedBigInteger('document_id');
                $table->unsignedInteger('version_no');
                // the file of that version (files.uuid)
                $table->string('file_uuid', 64);
                $table->string('document_name', 1024)->nullable();
                $table->timestamps();
                $table->unsignedBigInteger('created_user_id')->nullable();

                $table->unique(['document_id', 'version_no']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
