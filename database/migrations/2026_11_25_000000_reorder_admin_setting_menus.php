<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The menus of the admin settings, in the order of a new site of 7.2.0
     * (system_template/config.json): the settings of the site, the tables and
     * screens, the users, the features and the plugins, the maintenance.
     */
    public const ORDER = [
        ['system', 'system'],
        ['system', 'login_setting'],
        ['system', 'api_setting'],
        ['system', 'custom_table'],
        ['system', 'studio'],
        ['system', 'menu'],
        ['system', 'workflow'],
        ['table', 'mail_template'],
        ['system', 'loginuser'],
        ['system', 'role_group'],
        ['system', 'feature'],
        ['system', 'plugin'],
        ['system', 'template'],
        ['system', 'backup'],
        ['system', 'tuning'],
    ];

    /**
     * The order the older templates had (7.0 to 7.1), without the menus that
     * the migrations of the updates added at the end.
     */
    public const BEFORE = ['system', 'custom_table', 'menu', 'template', 'backup', 'loginuser', 'role_group', 'workflow', 'plugin', 'mail_template'];

    /** Added at the end of "Admin Setting" by the migrations of the updates (or by the template of 7.1). */
    public const ADDED = ['login_setting', 'api_setting', 'studio', 'feature', 'tuning'];

    /** The order of a new site of 7.1.2 (its template), without the menus 7.2 added. */
    public const NEW_712 = ['system', 'custom_table', 'menu', 'template', 'backup', 'loginuser', 'role_group', 'workflow', 'plugin', 'studio', 'mail_template', 'login_setting', 'api_setting'];

    /**
     * Put the menus of the admin settings in the order of a new site, only
     * when the site still has them in an order an Exment made (a new site of
     * 7.0 or 7.1, an update): an order an administrator made stays. Only the
     * order changes (not the titles, the icons or the parents); the menus not
     * known here (made by the site, of plugins, of tables, the others of the
     * system) come after the known ones, in their order.
     *
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
        $children = DB::table($table)->where('parent_id', $admin->id)->orderBy('order')->orderBy('id')->get()->all();

        $index = [];
        foreach (static::ORDER as $i => [$type, $name]) {
            $index[$type . ':' . $name] = $i;
        }
        $known = [];
        $others = [];
        // the position of each child, and the last one of the old templates
        $names = [];
        $lastBefore = -1;
        $firstOther = null;
        foreach ($children as $i => $row) {
            $key = $row->menu_type . ':' . $row->menu_name;
            if (array_key_exists($key, $index) && !isset($known[$key])) {
                $known[$key] = $row;
                $names[] = strval($row->menu_name);
                if (in_array($row->menu_name, static::BEFORE, true)) {
                    $lastBefore = $i;
                }
            } else {
                $others[] = $row;
                $firstOther = $firstOther ?? $i;
            }
        }
        if (count($known) === 0) {
            return;
        }
        // a menu the site put among the menus of the system (a plugin, a URL,
        // a table, the logs...): an administrator made that order
        if (isset($firstOther) && $firstOther < $lastBefore) {
            return;
        }
        // the known ones must be in an order an Exment made: a new site of
        // 7.1.2, or a site updated from 7.0 (the added menus at the end), or
        // the order of 7.2 already. Otherwise an administrator moved them
        if (!$this->madeByExment($names)) {
            return;
        }

        uasort($known, function ($a, $b) use ($index) {
            return $index[$a->menu_type . ':' . $a->menu_name] <=> $index[$b->menu_type . ':' . $b->menu_name];
        });
        $order = 1;
        foreach (array_merge(array_values($known), $others) as $row) {
            if (intval($row->order) !== $order) {
                DB::table($table)->where('id', $row->id)->update(['order' => $order]);
            }
            $order++;
        }
    }

    /**
     * Whether the names (the known menus, in their order) are an order an
     * Exment made: one of the orders below (with the menus the site has),
     * then only menus the updates added at the end.
     *
     * @param string[] $names
     */
    protected function madeByExment(array $names): bool
    {
        $new = array_map(function ($pair) {
            return $pair[1];
        }, static::ORDER);
        foreach ([static::NEW_712, static::BEFORE, $new] as $start) {
            $prefix = array_values(array_filter($start, function ($name) use ($names) {
                return in_array($name, $names, true);
            }));
            if (array_slice($names, 0, count($prefix)) !== $prefix) {
                continue;
            }
            $rest = array_slice($names, count($prefix));
            if (count(array_diff($rest, static::ADDED)) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return void
     */
    public function down()
    {
        // the order stays
    }
};
