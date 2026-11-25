<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * File server, phase 4 (ISS00378): the columns of a workspace (its
     * metadata), the values of each file, the links of a file to the data of
     * Exment (a customer, a project...) and the text of each file for the
     * search.
     *
     * @return void
     */
    public function up()
    {
        // the columns of a workspace ("columns" of a SharePoint library)
        if (!Schema::hasTable('file_metadata_fields')) {
            Schema::create('file_metadata_fields', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('workspace_id')->index();
                // the key of the value in file_nodes.metadata: a-z, 0-9 and _
                $table->string('name', 64);
                $table->string('label', 256);
                // text, textarea, number, date, select, multiselect, boolean
                $table->string('field_type', 20);
                // the choices of select and multiselect (one in each line)
                $table->text('options')->nullable();
                $table->boolean('required')->default(false);
                $table->string('default_value', 1000)->nullable();
                // shown as a column of the list of a folder
                $table->boolean('show_in_list')->default(true);
                $table->unsignedInteger('order')->default(0);
                $table->timestamps();
                $table->unsignedInteger('created_user_id')->nullable();
                $table->unsignedInteger('updated_user_id')->nullable();

                $table->unique(['workspace_id', 'name']);
            });
        }

        // the values of the columns (JSON: name => value)
        if (Schema::hasTable('file_nodes') && !Schema::hasColumn('file_nodes', 'metadata')) {
            Schema::table('file_nodes', function (Blueprint $table) {
                $table->text('metadata')->nullable();
            });
        }

        // the text in a file (PDF, Office, text; OCR for images), for the search
        if (!Schema::hasTable('file_node_texts')) {
            Schema::create('file_node_texts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('node_id')->unique();
                $table->unsignedInteger('version_id')->nullable();
                $table->longText('content')->nullable();
                // done; none (no text can be taken from it); failed
                $table->string('status', 10);
                $table->string('method', 20)->nullable(); // text, office, pdf, ocr
                $table->timestamps();
            });
        }

        // a file (or folder) and a data of Exment
        if (!Schema::hasTable('file_data_links')) {
            Schema::create('file_data_links', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('workspace_id')->index();
                $table->unsignedInteger('node_id')->index();
                $table->string('table_name', 256);
                $table->unsignedInteger('data_id');
                $table->timestamps();
                $table->unsignedInteger('created_user_id')->nullable();
                $table->unsignedInteger('updated_user_id')->nullable();

                $table->index(['table_name', 'data_id']);
                $table->unique(['node_id', 'table_name', 'data_id']);
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('file_data_links');
        Schema::dropIfExists('file_node_texts');
        if (Schema::hasTable('file_nodes') && Schema::hasColumn('file_nodes', 'metadata')) {
            Schema::table('file_nodes', function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }
        Schema::dropIfExists('file_metadata_fields');
    }
};
