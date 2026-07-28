<?php

use Exceedone\Exment\Services\Line\LineInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        LineInstaller::ensureLinkMenu();
    }

    public function down(): void
    {
        LineInstaller::removeLinkMenu();
    }
};
