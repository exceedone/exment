<?php

use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SafetyCheckInstaller::ensureAll();
    }

    public function down(): void
    {
        SafetyCheckInstaller::removeAll();
    }
};
