<?php

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * P2pQuakeFeed's time parsing changed semantics (parse as JST, then convert to
     * the app timezone). A safety_check_last_feed_time cursor stored by the old
     * code is a JST wall-clock string mislabeled as app-tz — i.e. hours in the
     * future — so after this deploy the watcher would skip every bulletin until
     * real time passes it. Reset the cursor once; the stale-bulletin guard
     * (safety_check_max_bulletin_age_minutes) prevents re-blasting old quakes
     * on the next poll.
     */
    public function up(): void
    {
        // Idempotent: picks up columns added since the 2026_08_11 install migration
        // ran (e.g. safety_check_event.quake_time, which the cooldown scoping needs).
        SafetyCheckInstaller::ensureAll();

        // Bulk query delete: no model events (System's saved event would
        // Cache::flush() the whole store), so clear the cache explicitly.
        System::where('system_name', 'safety_check_last_feed_time')->delete();
        System::clearCache();
    }

    public function down(): void
    {
        // Nothing to restore: the old cursor value was wrong by construction.
    }
};
