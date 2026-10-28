<?php

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SafetyCheckInstaller::ensureAll();

        System::where('system_name', 'safety_check_last_feed_time')->delete();
        System::clearCache();
    }

    public function down(): void
    {
    }
};
