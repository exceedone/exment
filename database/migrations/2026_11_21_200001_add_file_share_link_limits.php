<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * File server (ISS00410): what a request link took in, for its limits
     * (exment.file_server_request_max_files, _max_bytes).
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('file_share_links') || Schema::hasColumn('file_share_links', 'received_count')) {
            return;
        }
        Schema::table('file_share_links', function (Blueprint $table) {
            $table->unsignedInteger('received_count')->default(0);
            $table->unsignedBigInteger('received_bytes')->default(0);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('file_share_links') && Schema::hasColumn('file_share_links', 'received_count')) {
            Schema::table('file_share_links', function (Blueprint $table) {
                $table->dropColumn(['received_count', 'received_bytes']);
            });
        }
    }
};
