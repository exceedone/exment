<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * File server (ISS00293, phases 1 and 2): workspaces, their folders and
     * files, the permissions of each folder, the versions of each file and
     * the uploads in parts.
     *
     * The bytes of the files are kept on the "admin" disk, so the place they
     * are kept in (local, S3, Azure, FTP...) is the one the site already uses.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('file_workspaces')) {
            Schema::create('file_workspaces', function (Blueprint $table) {
                $table->increments('id');
                $table->string('suuid', 20)->index();
                $table->string('name', 256);
                $table->text('description')->nullable();
                // null: no limit
                $table->unsignedBigInteger('quota_bytes')->nullable();
                // the days a file stays in the trash before it is removed for good (0: until someone removes it)
                $table->unsignedInteger('trash_days')->default(30);
                // the versions kept for each file (the oldest are removed past it)
                $table->unsignedInteger('version_limit')->default(50);
                $table->timestamps();
                $table->softDeletes();
                $table->unsignedInteger('created_user_id')->nullable();
                $table->unsignedInteger('updated_user_id')->nullable();
                $table->unsignedInteger('deleted_user_id')->nullable();
            });
        }

        if (!Schema::hasTable('file_nodes')) {
            Schema::create('file_nodes', function (Blueprint $table) {
                $table->increments('id');
                $table->string('suuid', 20)->index();
                $table->unsignedInteger('workspace_id')->index();
                // null: at the top of the workspace
                $table->unsignedInteger('parent_id')->nullable()->index();
                $table->string('node_type', 10); // folder, file
                $table->string('name', 255);
                // the folder or file has its own permissions; otherwise those of its parent
                $table->boolean('has_unique_permissions')->default(false);
                // file only
                $table->unsignedInteger('current_version_id')->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->string('mime_type', 255)->nullable();
                $table->unsignedInteger('checked_out_user_id')->nullable();
                $table->dateTime('checked_out_at')->nullable();
                // trash: the node removed by a user is the "trash root"; the nodes under it point to it
                $table->dateTime('trashed_at')->nullable()->index();
                $table->unsignedInteger('trashed_user_id')->nullable();
                $table->unsignedInteger('trash_root_id')->nullable()->index();
                $table->timestamps();
                $table->unsignedInteger('created_user_id')->nullable();
                $table->unsignedInteger('updated_user_id')->nullable();

                $table->index(['workspace_id', 'parent_id', 'trashed_at']);
            });
        }

        if (!Schema::hasTable('file_node_versions')) {
            Schema::create('file_node_versions', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('node_id')->index();
                $table->unsignedInteger('version_no');
                // path on the admin disk
                $table->string('path', 512);
                $table->unsignedBigInteger('size')->default(0);
                $table->string('mime_type', 255)->nullable();
                $table->string('hash', 64)->nullable();
                $table->string('comment', 1000)->nullable();
                // the converted files for the preview: thumbnail, pdf
                $table->string('thumbnail_path', 512)->nullable();
                $table->string('preview_path', 512)->nullable();
                // null: not made yet; done; none (it cannot be made); failed
                $table->string('rendition_status', 10)->nullable();
                $table->timestamps();
                $table->unsignedInteger('created_user_id')->nullable();
                $table->unsignedInteger('updated_user_id')->nullable();

                $table->unique(['node_id', 'version_no']);
            });
        }

        if (!Schema::hasTable('file_permissions')) {
            Schema::create('file_permissions', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('workspace_id')->index();
                // null: the members of the workspace (the permissions of its top)
                $table->unsignedInteger('node_id')->nullable()->index();
                $table->string('target_type', 20); // user, organization
                $table->unsignedInteger('target_id');
                $table->unsignedTinyInteger('level'); // 1 view, 2 upload, 3 edit, 4 manage
                $table->timestamps();
                $table->unsignedInteger('created_user_id')->nullable();
                $table->unsignedInteger('updated_user_id')->nullable();

                $table->index(['target_type', 'target_id']);
            });
        }

        if (!Schema::hasTable('file_upload_sessions')) {
            Schema::create('file_upload_sessions', function (Blueprint $table) {
                $table->string('id', 40)->primary();
                $table->unsignedInteger('workspace_id');
                $table->unsignedInteger('parent_id')->nullable();
                $table->unsignedInteger('user_id');
                $table->string('name', 255);
                $table->string('relative_path', 1024)->nullable();
                $table->unsignedBigInteger('total_size');
                $table->unsignedBigInteger('received_size')->default(0);
                $table->string('comment', 1000)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('file_favorites')) {
            Schema::create('file_favorites', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('node_id')->index();
                $table->timestamps();

                $table->unique(['user_id', 'node_id']);
            });
        }

        $this->addMenu();
    }

    /**
     * Add "File Server" to the top of the menu, under "Home", when the site
     * has no such menu yet. A new site gets it from the system template.
     */
    protected function addMenu(): void
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($menuTable)) {
            return;
        }
        if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'file_server')->exists()) {
            return;
        }
        // a site that is not installed yet gets the menu from the template
        if (!DB::table($menuTable)->exists()) {
            return;
        }

        $home = DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'home')->first();
        $order = isset($home) ? intval($home->order) + 1 : 1;
        $parentId = isset($home) ? intval($home->parent_id) : 0;

        // move the menus after it down by one
        DB::table($menuTable)->where('parent_id', $parentId)->where('order', '>=', $order)->increment('order');
        DB::table($menuTable)->insert([
            'parent_id' => $parentId,
            'order' => $order,
            'title' => exmtrans('menu.system_definitions.file_server'),
            'icon' => 'fa-folder-open',
            'uri' => 'file_server',
            'menu_type' => 'system',
            'menu_name' => 'file_server',
            'menu_target' => 'file_server',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('file_favorites');
        Schema::dropIfExists('file_upload_sessions');
        Schema::dropIfExists('file_permissions');
        Schema::dropIfExists('file_node_versions');
        Schema::dropIfExists('file_nodes');
        Schema::dropIfExists('file_workspaces');
        // the menu is not removed (someone may have moved or renamed it)
    }
};
