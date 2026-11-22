<?php

use Exceedone\Exment\Model\Menu;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Put the admin settings of a site in the three groups of a new site
 * (ISS02819): "Settings: Data & Screens", "Settings: Users & Permissions" and
 * "Settings: System" (the old "Admin Setting", menu_name admin).
 *
 * Only a site whose admin settings are still as an Exment made them (the
 * order of 2026_11_25_000000_reorder_admin_setting_menus, with what the site
 * or its plugins added after them) changes: an order an administrator made
 * stays, and so does a site that already has the groups. Only parents and
 * orders change: no menu is added or removed, and the menus not known here
 * stay under "Admin Setting".
 */
return new class extends Migration
{
    /** The menus of the admin settings in the order of 2026_11_25 (and maintenance, added after it). */
    public const ORDER = ['system', 'login_setting', 'api_setting', 'custom_table', 'studio', 'menu', 'workflow', 'mail_template', 'loginuser', 'role_group', 'feature', 'plugin', 'template', 'backup', 'tuning', 'maintenance'];

    /** [menu_name of the group, its icon, the menus it takes (in this order)] */
    public const GROUPS = [
        ['admin_data', 'fa-th-large', ['custom_table', 'studio', 'menu', 'workflow', 'mail_template', 'template']],
        ['admin_user', 'fa-users', ['loginuser', 'role_group', 'login_setting', 'api_setting']],
    ];

    /** What stays in "Admin Setting", now "Settings: System", in this order (then the others). */
    public const SYSTEM = ['system', 'feature', 'plugin', 'backup', 'tuning', 'maintenance'];

    /**
     * @return void
     */
    public function up()
    {
        $table = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($table)) {
            return;
        }
        $admin = DB::table($table)->where('menu_type', 'parent_node')->where('menu_name', 'admin')->first();
        if (!isset($admin)) {
            return;
        }
        if (DB::table($table)->where('menu_type', 'parent_node')->whereIn('menu_name', ['admin_data', 'admin_user'])->exists()) {
            return;
        }
        $children = DB::table($table)->where('parent_id', $admin->id)->orderBy('order')->orderBy('id')->get()->all();
        if (!$this->madeByExment($children)) {
            return;
        }

        $site = Menu::siteLocale();
        $title = function (string $name) use ($site): string {
            return strval(trans('exment::exment.menu.system_definitions.' . $name, [], $site));
        };
        $byName = [];
        foreach ($children as $row) {
            if (in_array($row->menu_type, ['system', 'table'], true) && !isset($byName[$row->menu_name])) {
                $byName[$row->menu_name] = $row;
            }
        }

        DB::transaction(function () use ($table, $admin, $children, $byName, $title) {
            // the groups go right before "Admin Setting" at the top level
            $groupIds = [];
            foreach (static::GROUPS as [$name, $icon, $names]) {
                $groupIds[$name] = DB::table($table)->insertGetId([
                    'parent_id' => $admin->parent_id,
                    'order' => $admin->order,
                    'title' => $title($name),
                    'icon' => $icon,
                    'uri' => null,
                    'menu_type' => 'parent_node',
                    'menu_name' => $name,
                    'menu_target' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $order = 1;
                foreach ($names as $menuName) {
                    if (!isset($byName[$menuName])) {
                        continue;
                    }
                    DB::table($table)->where('id', $byName[$menuName]->id)->update(['parent_id' => $groupIds[$name], 'order' => $order++]);
                }
            }
            $roots = DB::table($table)->where('parent_id', $admin->parent_id)->orderBy('order')->orderBy('id')->get()->all();
            $sorted = [];
            foreach ($roots as $row) {
                if (in_array(intval($row->id), $groupIds, true)) {
                    continue;
                }
                if (intval($row->id) === intval($admin->id)) {
                    foreach ($groupIds as $id) {
                        $sorted[] = $id;
                    }
                }
                $sorted[] = intval($row->id);
            }
            foreach ($sorted as $i => $id) {
                DB::table($table)->where('id', $id)->update(['order' => $i + 1]);
            }

            // what stays: the system ones in their order, then the others in theirs
            $moved = array_merge(...array_map(function ($group) {
                return $group[2];
            }, static::GROUPS));
            $stay = [];
            $others = [];
            foreach ($children as $row) {
                if (in_array($row->menu_type, ['system', 'table'], true) && in_array($row->menu_name, $moved, true)) {
                    continue;
                }
                if ($row->menu_type === 'system' && in_array($row->menu_name, static::SYSTEM, true)) {
                    $stay[array_search($row->menu_name, static::SYSTEM, true)] = $row;
                } else {
                    $others[] = $row;
                }
            }
            ksort($stay);
            foreach (array_merge(array_values($stay), $others) as $i => $row) {
                DB::table($table)->where('id', $row->id)->update(['order' => $i + 1]);
            }

            // the title, only when it is still the one Exment gave it
            if (in_array(strval($admin->title), $this->defaultAdminTitles(), true)) {
                DB::table($table)->where('id', $admin->id)->update(['title' => $title('admin_system')]);
            }
        });
    }

    /**
     * The known children are in the order of 2026_11_25 (the ones the site has),
     * and nothing the site made sits between them.
     *
     * @param array<int, \stdClass> $children
     */
    protected function madeByExment(array $children): bool
    {
        $names = [];
        $lastKnown = -1;
        $firstOther = null;
        foreach ($children as $i => $row) {
            if (in_array($row->menu_type, ['system', 'table'], true) && in_array($row->menu_name, static::ORDER, true) && !in_array($row->menu_name, $names, true)) {
                $names[] = strval($row->menu_name);
                $lastKnown = $i;
            } else {
                $firstOther = $firstOther ?? $i;
            }
        }
        if (count($names) === 0) {
            return false;
        }
        if (isset($firstOther) && $firstOther < $lastKnown) {
            return false;
        }
        $expected = array_values(array_filter(static::ORDER, function ($name) use ($names) {
            return in_array($name, $names, true);
        }));
        return $names === $expected;
    }

    /**
     * @return string[]
     */
    protected function defaultAdminTitles(): array
    {
        $titles = ['Admin Setting', '管理者設定'];
        foreach (['en', 'ja'] as $locale) {
            $titles[] = strval(trans('exment::exment.menu.system_definitions.admin', [], $locale));
        }
        return array_values(array_unique($titles));
    }

    /**
     * @return void
     */
    public function down()
    {
        // the groups stay
    }
};
