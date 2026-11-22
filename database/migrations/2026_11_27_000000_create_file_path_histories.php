<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * File server (ISS02815): the paths the folders and files had before
     * they were renamed or moved, so that the old links still open them.
     * node_id null: an old name of the workspace itself.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('file_path_histories')) {
            return;
        }
        Schema::create('file_path_histories', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('workspace_id')->index();
            $table->unsignedInteger('node_id')->nullable()->index();
            // sha256 of the path in one form (NFC, lower case): the path itself may be long
            $table->char('path_key', 64)->index();
            $table->text('path');
            $table->dateTime('created_at')->nullable();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('file_path_histories');
    }
};
