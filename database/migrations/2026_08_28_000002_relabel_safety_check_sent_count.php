<?php

use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SafetyCheckInstaller::ensureAll();
        SafetyCheckInstaller::ensureSentCountLabel();
    }

    public function down(): void
    {
    }
};
