<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The id of the site (ISS00419-421): a UUID made once, not the domain
     * (a site may change its domain). The market and the licenses of the
     * plugins know the site by it. A SaaS gives its own (the uuid of the
     * tenant) with EXMENT_SITE_ID.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('systems') || DB::table('systems')->where('system_name', 'site_id')->exists()) {
            return;
        }
        DB::table('systems')->insert([
            'system_name' => 'site_id',
            'system_value' => strtolower(strval(Str::uuid())),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return void
     */
    public function down()
    {
        // the id stays (the market may know the site by it)
    }
};
