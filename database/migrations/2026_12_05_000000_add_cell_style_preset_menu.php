<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Display presets" (cell_style_preset): a new site gets the menu from the
     * system template, under "Settings: Data & Screens", after "Notify". A site
     * updated from 7.0.x did not get it (#2178).
     *
     * Added under "Settings: Data & Screens" (admin_data), or "Admin Setting"
     * (admin), or next to "System Setting", when the site does not have it yet
     * (also one an administrator added by hand, with a name of their own).
     * Only the new menu gets a place: no other menu moves, except the ones
     * after "Notify" under the same parent, which go down by one.
     *
     * @return void
     */
    public function up()
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($menuTable)) {
            return;
        }
        // a site that is not installed yet gets the menu from the template
        if (!DB::table($menuTable)->exists()) {
            return;
        }
        if ($this->hasMenu($menuTable, 'cell_style_preset')) {
            return;
        }

        $parentId = $this->parentId($menuTable);
        $order = intval(DB::table($menuTable)->where('parent_id', $parentId)->max('order')) + 1;
        DB::table($menuTable)->insert([
            'parent_id' => $parentId,
            'order' => $order,
            'title' => \Exceedone\Exment\Model\Menu::systemTitle('cell_style_preset'),
            'icon' => 'fa-paint-brush',
            'uri' => 'cell_style_preset',
            'menu_type' => 'system',
            'menu_name' => 'cell_style_preset',
            'menu_target' => 'cell_style_preset',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // as on a new site (nothing when "Notify" is under another parent)
        \Exceedone\Exment\Model\Menu::placeSystemMenuAfter('cell_style_preset', 'notify');
    }

    /**
     * "Settings: Data & Screens", then "Admin Setting", then the parent of
     * "System Setting" (the top when the site has none of them).
     */
    protected function parentId(string $menuTable): int
    {
        foreach (['admin_data', 'admin'] as $name) {
            $parent = DB::table($menuTable)->where('menu_type', 'parent_node')->where('menu_name', $name)->first();
            if (isset($parent)) {
                return intval($parent->id);
            }
        }
        $system = DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'system')->first();
        return isset($system) ? intval($system->parent_id) : 0;
    }

    /**
     * The site has the menu: a system menu of it (by its name, its target or
     * its URI), or a menu the site made to its page.
     */
    protected function hasMenu(string $menuTable, string $name): bool
    {
        return DB::table($menuTable)->where(function ($query) use ($name) {
            $query->where(function ($system) use ($name) {
                $system->where('menu_type', 'system')->where(function ($q) use ($name) {
                    $q->where('menu_name', $name)->orWhere('menu_target', $name)->orWhere('uri', $name);
                });
            })->orWhere('uri', $name)->orWhere('uri', '/' . $name);
        })->exists();
    }

    /**
     * @return void
     */
    public function down()
    {
        // the menu is not removed (someone may have moved or renamed it)
    }
};
