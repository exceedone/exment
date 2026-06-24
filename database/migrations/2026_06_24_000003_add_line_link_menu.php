<?php

use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Model\Menu;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (Menu::where('menu_type', MenuType::CUSTOM)->where('menu_name', 'line_link')->exists()) {
            return;
        }
        $menu = new Menu();
        $menu->parent_id   = 0;
        $menu->order       = 99;
        $menu->menu_type   = MenuType::CUSTOM;
        $menu->menu_name   = 'line_link';
        $menu->menu_target = 'line/link';
        $menu->title       = 'LINE連携';
        $menu->icon        = 'fa-comments';
        $menu->uri         = 'line/link';
        $menu->save();
    }

    public function down(): void
    {
        Menu::where('menu_type', MenuType::CUSTOM)
            ->where('menu_name', 'line_link')
            ->delete();
    }
};
