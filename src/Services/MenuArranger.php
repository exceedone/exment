<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\DB;

/**
 * "Arrange in the standard order" of the menu screen (ISS02819): puts the
 * menus of Exment and of the official plugins where a new site has them (the
 * groups of the feature map, the three settings), also on a site whose menu
 * an administrator arranged, or that has other menus among them (Exment SaaS).
 *
 * - Only the menus known here move (the system menus, the tables of the system
 *   template, the official plugins). A menu made by the site, a table menu and
 *   any other menu stays where it is, in its order, and so do the menus under
 *   a group that moves.
 * - Nothing is removed. The groups are made when missing (Menu::groupParentId()).
 * - The titles of "HOME", "Manage Master" and "Admin Setting" change only while
 *   they are still the ones Exment gave them.
 * - The menu as it was before is kept (System::menu_arrange_backup), and
 *   restore() puts it back.
 */
class MenuArranger
{
    /** [group (null: the top), menus in this order: [menu_type, menu_name]] */
    public const LAYOUT = [
        [null, [['system', 'home']]],
        ['groupware', [['system', 'file_server'], ['custom', 'exment_groupware_mail']]],
        ['master', [['table', 'base_info'], ['table', 'organization'], ['table', 'user'], ['table', 'information']]],
        ['admin_data', [['system', 'custom_table'], ['system', 'studio'], ['system', 'menu'], ['system', 'workflow'], ['table', 'mail_template'], ['system', 'notify'], ['system', 'cell_style_preset'], ['system', 'template']]],
        ['admin_user', [['system', 'loginuser'], ['system', 'role_group'], ['system', 'login_setting'], ['system', 'api_setting'], ['system', 'operation_log']]],
        ['admin', [['system', 'system'], ['system', 'feature'], ['system', 'plugin'], ['system', 'backup'], ['system', 'tuning'], ['system', 'security_check'], ['system', 'maintenance']]],
    ];

    /** The settings, after the groups of the feature map (master is 90). */
    public const SETTINGS = ['admin_data' => ['fa-th-large', 91], 'admin_user' => ['fa-users', 92], 'admin' => ['fa-cogs', 93]];

    /** The menus of the official plugins: [plugin_name => group]. */
    public const PLUGIN_GROUPS = ['ExmentPM' => 'project', 'ExmentITSupport' => 'it_support'];

    /** Titles that change while they are still the ones Exment gave: menu_name => [titles of Exment, lang key of the new one] */
    public const TITLES = [
        'home' => [['HOME', 'Home'], 'menu.arrange.title_home'],
        'master' => [['マスター管理', 'Manage Master', 'Master'], 'menu.arrange.title_master'],
        'admin' => [['管理者設定', 'Admin Setting'], 'menu.system_definitions.admin_system'],
    ];

    protected string $table;

    public function __construct()
    {
        $this->table = config('admin.database.menu_table', 'admin_menu');
    }

    /**
     * What arrange() would do, for the screen: rows of [title, from, to].
     *
     * @return array<int, array{title: string, from: string, to: string}>
     */
    public function plan(): array
    {
        $rows = DB::table($this->table)->get()->keyBy('id');
        $title = function ($id) use ($rows) {
            if (intval($id) === 0) {
                return exmtrans('menu.arrange.top');
            }
            $row = $rows->get($id);
            return isset($row) ? strval($row->title) : '';
        };
        $changes = [];
        foreach ($this->moves() as $move) {
            $row = $rows->get($move['id']);
            if (!isset($row)) {
                continue;
            }
            $toParent = isset($move['group']) ? $this->groupTitle($move['group'], $rows) : exmtrans('menu.arrange.top');
            $from = $title($row->parent_id);
            if ($from !== $toParent) {
                $changes[] = ['title' => strval($row->title), 'from' => $from, 'to' => $toParent];
            }
        }
        foreach ($this->titleChanges() as $id => $new) {
            $changes[] = ['title' => strval($rows->get($id)->title), 'from' => strval($rows->get($id)->title), 'to' => $new];
        }
        return $changes;
    }

    /**
     * Arrange the menu. The menu before is kept for restore().
     */
    public function arrange(): int
    {
        $before = DB::table($this->table)->get(['id', 'parent_id', 'order', 'title'])->map(function ($row) {
            return ['id' => intval($row->id), 'parent_id' => intval($row->parent_id), 'order' => intval($row->order), 'title' => strval($row->title)];
        })->all();
        $existing = DB::table($this->table)->pluck('id')->map(function ($id) {
            return intval($id);
        })->all();

        $count = 0;
        DB::transaction(function () use (&$count) {
            $titles = $this->titleChanges();
            foreach ($this->moves() as $move) {
                $parent = isset($move['group']) ? $this->groupId($move['group']) : 0;
                DB::table($this->table)->where('id', $move['id'])->update(['parent_id' => $parent, 'order' => $move['order'], 'updated_at' => now()]);
                $count++;
            }
            foreach ($titles as $id => $title) {
                DB::table($this->table)->where('id', $id)->update(['title' => $title, 'updated_at' => now()]);
                $count++;
            }
            $this->orderTop();
        });

        $made = DB::table($this->table)->whereNotIn('id', $existing)->pluck('id')->map(function ($id) {
            return intval($id);
        })->all();
        System::menu_arrange_backup(['at' => now()->toDateTimeString(), 'rows' => $before, 'made' => $made]);
        System::clearCache();
        return $count;
    }

    public function canRestore(): bool
    {
        return count(array_get($this->backup(), 'rows', [])) > 0;
    }

    /**
     * Put the menu back as it was before arrange(): the parents, orders and
     * titles of the menus that are still there; the groups arrange() made go
     * when nothing is left in them.
     */
    public function restore(): bool
    {
        $backup = $this->backup();
        $rows = array_get($backup, 'rows', []);
        if (count($rows) === 0) {
            return false;
        }
        DB::transaction(function () use ($rows, $backup) {
            foreach ($rows as $row) {
                DB::table($this->table)->where('id', $row['id'])->update(['parent_id' => $row['parent_id'], 'order' => $row['order'], 'title' => $row['title']]);
            }
            foreach (array_get($backup, 'made', []) as $id) {
                if (!DB::table($this->table)->where('parent_id', $id)->exists()) {
                    DB::table($this->table)->where('id', $id)->delete();
                }
            }
        });
        System::menu_arrange_backup([]);
        System::clearCache();
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function backup(): array
    {
        // straight from the table: the settings are cached for the request, and
        // the screen reads them right after arrange() or restore() wrote them
        $value = DB::table((new System())->getTable())->where('system_name', 'menu_arrange_backup')->value('system_value');
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value) ? $value : [];
    }

    /**
     * The known menus of the site and where they go: [id, group, order].
     *
     * @return array<int, array{id: int, group: ?string, order: int}>
     */
    protected function moves(): array
    {
        $moves = [];
        foreach (static::LAYOUT as [$group, $items]) {
            $order = 1;
            foreach ($items as [$type, $name]) {
                $row = DB::table($this->table)->where('menu_type', $type)->where('menu_name', $name)->orderBy('id')->first();
                if (!isset($row)) {
                    continue;
                }
                $moves[] = ['id' => intval($row->id), 'group' => $group, 'order' => $group === null ? 1 : $order++];
            }
        }
        // the official plugins: their menu at the top or in a group of its own goes into the group of the feature map
        foreach (static::PLUGIN_GROUPS as $pluginName => $group) {
            $pluginId = DB::table('plugins')->where('plugin_name', $pluginName)->value('id');
            if (!isset($pluginId)) {
                continue;
            }
            $row = DB::table($this->table)->where('menu_type', MenuType::PLUGIN)->where('menu_target', strval($pluginId))->orderBy('id')->first();
            if (isset($row)) {
                $moves[] = ['id' => intval($row->id), 'group' => $group, 'order' => 1];
            }
        }
        return $moves;
    }

    /**
     * @return array<int, string> id => new title
     */
    protected function titleChanges(): array
    {
        $site = Menu::siteLocale();
        $changes = [];
        foreach (static::TITLES as $name => [$defaults, $key]) {
            $type = $name === 'home' ? 'system' : 'parent_node';
            $row = DB::table($this->table)->where('menu_type', $type)->where('menu_name', $name)->first();
            if (!isset($row)) {
                continue;
            }
            $known = array_merge($defaults, [strval(trans('exment::exment.menu.system_definitions.' . $name, [], $site))]);
            $new = strval(trans('exment::exment.' . $key, [], $site));
            if (in_array(strval($row->title), $known, true) && $row->title !== $new) {
                $changes[intval($row->id)] = $new;
            }
        }
        return $changes;
    }

    /**
     * The id of a group: a group of the feature map, or one of the settings (made when missing).
     */
    protected function groupId(string $group): int
    {
        if (array_key_exists($group, Define::MENU_GROUP_DEFINITION)) {
            return intval(Menu::groupParentId($group));
        }
        $id = DB::table($this->table)->where('menu_type', MenuType::PARENT_NODE)->where('menu_name', $group)->value('id');
        if (isset($id)) {
            return intval($id);
        }
        [$icon, $order] = static::SETTINGS[$group];
        return intval(DB::table($this->table)->insertGetId([
            'parent_id' => 0, 'order' => $order, 'title' => Menu::systemTitle($group), 'icon' => $icon, 'uri' => null,
            'menu_type' => MenuType::PARENT_NODE, 'menu_name' => $group, 'menu_target' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    /**
     * @param \Illuminate\Support\Collection<int|string, \stdClass> $rows
     */
    protected function groupTitle(string $group, $rows): string
    {
        $row = $rows->first(function ($row) use ($group) {
            return $row->menu_type === MenuType::PARENT_NODE && $row->menu_name === $group;
        });
        return isset($row) ? strval($row->title) : Menu::systemTitle($group === 'admin' ? 'admin_system' : $group);
    }

    /**
     * The top level in the order of the feature map: the home, the groups,
     * then the settings. Other menus at the top keep their place among them.
     */
    protected function orderTop(): void
    {
        $orders = ['home' => 1];
        foreach (Define::MENU_GROUP_DEFINITION as $name => $define) {
            $orders[$name] = $define['order'];
        }
        foreach (static::SETTINGS as $name => [$icon, $order]) {
            $orders[$name] = $order;
        }
        foreach ($orders as $name => $order) {
            DB::table($this->table)->where('parent_id', 0)->where('menu_name', $name)->update(['order' => $order]);
        }
        // what stays in "Admin Setting" after the known ones, in its order
        $admin = DB::table($this->table)->where('menu_type', MenuType::PARENT_NODE)->where('menu_name', 'admin')->value('id');
        if (isset($admin)) {
            $known = array_map(function ($pair) {
                return $pair[1];
            }, static::LAYOUT[5][1]);
            $others = DB::table($this->table)->where('parent_id', $admin)->whereNotIn('menu_name', $known)->orderBy('order')->orderBy('id')->get();
            $order = count($known) + 1;
            foreach ($others as $row) {
                DB::table($this->table)->where('id', $row->id)->update(['order' => $order++]);
            }
        }
    }
}
