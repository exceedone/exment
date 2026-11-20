<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * File server, phase 3 (ISS00377): links that give a folder or file to
     * people outside (with an end date, a password, the download allowed or
     * not, a count of opens), and links that take files from them.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('file_share_links')) {
            return;
        }
        Schema::create('file_share_links', function (Blueprint $table) {
            $table->increments('id');
            $table->string('token', 64)->unique();
            $table->unsignedInteger('workspace_id')->index();
            $table->unsignedInteger('node_id')->index();
            // share: see (and download) a folder or file; request: upload files into a folder
            $table->string('kind', 10);
            $table->string('password_hash', 255)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->boolean('allow_download')->default(true);
            // null: no limit
            $table->unsignedInteger('max_access')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->string('message', 1000)->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedInteger('revoked_user_id')->nullable();
            $table->dateTime('last_accessed_at')->nullable();
            $table->timestamps();
            $table->unsignedInteger('created_user_id')->nullable();
            $table->unsignedInteger('updated_user_id')->nullable();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('file_share_links');
    }
};
