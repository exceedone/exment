<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The trash (ISS00334): the deleted rows of all the tables on one page. A new site gets the
     * menu from the system template, under "Settings: Data & Screens", after "Template"; an
     * installed site gets it here, in the same place (or under "Admin Setting", or next to
     * "System Setting"). The menu is hidden until the feature "trash" is turned on (Features),
     * and shown to the users who may see the deleted rows of a table at least (Auth\Permission).
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
        if ($this->hasMenu($menuTable, 'trash')) {
            return;
        }

        $parentId = $this->parentId($menuTable);
        $order = intval(DB::table($menuTable)->where('parent_id', $parentId)->max('order')) + 1;
        DB::table($menuTable)->insert([
            'parent_id' => $parentId,
            'order' => $order,
            'title' => \Exceedone\Exment\Model\Menu::systemTitle('trash'),
            'icon' => 'fa-trash',
            'uri' => 'trash',
            'menu_type' => 'system',
            'menu_name' => 'trash',
            'menu_target' => 'trash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // as on a new site (nothing when "Template" is under another parent)
        \Exceedone\Exment\Model\Menu::placeSystemMenuAfter('trash', 'template');
    }

    /**
     * "Settings: Data & Screens", then "Admin Setting", then the parent of "System Setting".
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
     * The site has the menu: a system menu of it, or a menu the site made to its page.
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
