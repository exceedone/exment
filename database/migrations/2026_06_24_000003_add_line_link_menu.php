<?php

use Exceedone\Exment\Services\Line\LineInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // The "LINE" page moved to the user menu (top right) in v7.1.0, so a new site
        // no longer gets the side menu item. Sites that have it lose it in
        // 2026_11_21_000000_move_line_link_menu_to_user_menu.
    }

    public function down(): void
    {
        LineInstaller::removeLinkMenu();
    }
};
