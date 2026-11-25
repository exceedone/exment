<?php

use Exceedone\Exment\Model\System;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Comments are shown as Markdown from now on. The comments that are already there were
 * written as plain text, so the time of this update is kept: a comment older than it is
 * shown as the plain text it was written as (CommentMarkdown::commentHtml()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('systems')) {
            return;
        }

        if (!System::where('system_name', 'comment_markdown_start')->exists()) {
            System::comment_markdown_start(\Carbon\Carbon::now()->format('Y-m-d H:i:s'));
            System::clearCache();
        }
    }

    public function down(): void
    {
    }
};
