<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The token of a file share link is kept as its sha256, and the token itself only
     * encrypted with the app key (to show the url again). The links made before keep
     * working: their token is moved the same way.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('file_share_links')) {
            return;
        }
        if (!Schema::hasColumn('file_share_links', 'token_encrypted')) {
            Schema::table('file_share_links', function (Blueprint $table) {
                $table->text('token_encrypted')->nullable()->after('token');
            });
        }
        // a row not moved yet has no encrypted copy (a moved one always has)
        DB::table('file_share_links')->whereNull('token_encrypted')->orderBy('id')->chunkById(200, function ($links) {
            foreach ($links as $link) {
                $token = strval($link->token);
                DB::table('file_share_links')->where('id', $link->id)->update([
                    'token' => hash('sha256', $token),
                    'token_encrypted' => Crypt::encryptString($token),
                ]);
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        // the tokens are not put back in plain text
    }
};
