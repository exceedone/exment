<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * File server, phase 5 (ISS00379): the audit log, the follows and the
     * comments, the rules of a workspace (retention, legal hold, watermark,
     * who may download) and the approval of the files.
     *
     * @return void
     */
    public function up()
    {
        // who did what: viewed, downloaded, shared, removed...
        if (!Schema::hasTable('file_audit_logs')) {
            Schema::create('file_audit_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('workspace_id')->nullable()->index();
                $table->unsignedInteger('node_id')->nullable()->index();
                // the name at that time (the node may be removed later)
                $table->string('node_name', 255)->nullable();
                // null: the system, or the people outside with a link
                $table->unsignedInteger('user_id')->nullable()->index();
                $table->unsignedInteger('link_id')->nullable();
                $table->string('action', 30)->index();
                $table->text('detail')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->dateTime('created_at')->index();
            });
        }

        // a user follows a folder or file (or a workspace: node_id null) and is told of the changes
        if (!Schema::hasTable('file_follows')) {
            Schema::create('file_follows', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('workspace_id')->index();
                $table->unsignedInteger('node_id')->nullable()->index();
                $table->unsignedInteger('user_id')->index();
                $table->timestamps();
                $table->unique(['workspace_id', 'node_id', 'user_id']);
            });
        }

        if (!Schema::hasTable('file_comments')) {
            Schema::create('file_comments', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('workspace_id')->index();
                $table->unsignedInteger('node_id')->index();
                $table->text('body');
                // the users called with @ (JSON: ids)
                $table->text('mentions')->nullable();
                $table->timestamps();
                $table->unsignedInteger('created_user_id')->nullable();
                $table->unsignedInteger('updated_user_id')->nullable();
            });
        }

        if (Schema::hasTable('file_workspaces') && !Schema::hasColumn('file_workspaces', 'retention_days')) {
            Schema::table('file_workspaces', function (Blueprint $table) {
                // a file cannot be removed for these days after it was made (null: no rule)
                $table->unsignedInteger('retention_days')->nullable();
                // after the days: keep (nothing), delete (to the trash by the daily purge)
                $table->string('retention_action', 10)->default('keep');
                // nothing of the workspace can be removed (a lawsuit, an audit)
                $table->boolean('legal_hold')->default(false);
                // the name of the user and the time over the previews
                $table->boolean('watermark')->default(false);
                // the level needed to download (1: all who may see; 3: those who may edit)
                $table->unsignedTinyInteger('download_level')->default(1);
            });
        }

        if (Schema::hasTable('file_nodes') && !Schema::hasColumn('file_nodes', 'legal_hold')) {
            Schema::table('file_nodes', function (Blueprint $table) {
                $table->boolean('legal_hold')->default(false);
                // a folder: the files put in it (at any depth) need an approval
                $table->boolean('requires_approval')->default(false);
                // a file: null (no approval), draft, approved, rejected
                $table->string('approval_status', 10)->nullable()->index();
                $table->unsignedInteger('approval_user_id')->nullable();
                $table->dateTime('approval_at')->nullable();
                $table->string('approval_comment', 1000)->nullable();
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('file_nodes') && Schema::hasColumn('file_nodes', 'legal_hold')) {
            Schema::table('file_nodes', function (Blueprint $table) {
                $table->dropColumn(['legal_hold', 'requires_approval', 'approval_status', 'approval_user_id', 'approval_at', 'approval_comment']);
            });
        }
        if (Schema::hasTable('file_workspaces') && Schema::hasColumn('file_workspaces', 'retention_days')) {
            Schema::table('file_workspaces', function (Blueprint $table) {
                $table->dropColumn(['retention_days', 'retention_action', 'legal_hold', 'watermark', 'download_level']);
            });
        }
        Schema::dropIfExists('file_comments');
        Schema::dropIfExists('file_follows');
        Schema::dropIfExists('file_audit_logs');
    }
};
