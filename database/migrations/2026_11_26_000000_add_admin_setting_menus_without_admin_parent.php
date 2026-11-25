<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The menus of the admin settings the updates of 7.2 add under "Admin
     * Setting" (the parent node "admin"), with their icons.
     */
    public const MENUS = [
        'login_setting' => 'fa-sign-in',
        'api_setting' => 'fa-code-fork',
        'studio' => 'fa-th-large',
        'feature' => 'fa-toggle-on',
        'tuning' => 'fa-tachometer',
    ];

    /**
     * A site without the parent node "admin" (an administrator removed or
     * renamed it) did not get the menus of 7.2: they are added next to the
     * menu "System Setting" (under its parent), or at the top of the menu.
     * A site with the parent node got them from the migrations before.
     *
     * @return void
     */
    public function up()
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($menuTable)) {
            return;
        }
        // a site that is not installed yet gets the menus from the template
        if (!DB::table($menuTable)->exists()) {
            return;
        }
        if (DB::table($menuTable)->where('menu_name', 'admin')->where('menu_type', 'parent_node')->exists()) {
            return;
        }

        $this->repairLoginApiSetting($menuTable);

        $system = DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'system')->first();
        $parentId = isset($system) ? intval($system->parent_id) : 0;
        foreach (static::MENUS as $name => $icon) {
            if ($this->hasMenu($menuTable, $name)) {
                continue;
            }
            $order = intval(DB::table($menuTable)->where('parent_id', $parentId)->max('order')) + 1;
            DB::table($menuTable)->insert([
                'parent_id' => $parentId,
                'order' => $order,
                'title' => \Exceedone\Exment\Model\Menu::systemTitle($name),
                'icon' => $icon,
                'uri' => $name,
                'menu_type' => 'system',
                'menu_name' => $name,
                'menu_target' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
     * The two rows a new Japanese site of 7.1.0 got wrong (see
     * 2026_11_22_000000_repair_login_api_setting_menus_of_japanese_install,
     * which repairs them under "Admin Setting" only): repaired wherever they are.
     */
    protected function repairLoginApiSetting(string $menuTable): void
    {
        $wrongApi = DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', 'login_setting')->where('menu_target', 'api_setting')->first();
        $wrongLogin = DB::table($menuTable)->where('menu_type', 'table')->where('menu_name', 'mail_send_log')->whereNull('menu_target')->first();
        $rows = [['api_setting', 'fa-code-fork', 'ログイン設定', $wrongApi], ['login_setting', 'fa-sign-in', 'メール送信履歴', $wrongLogin]];
        foreach ($rows as [$name, $icon, $wrongTitle, $row]) {
            if (!isset($row)) {
                continue;
            }
            if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', $name)->where('id', '<>', $row->id)->exists()) {
                continue;
            }
            $values = ['menu_type' => 'system', 'menu_name' => $name, 'menu_target' => $name, 'uri' => $name, 'icon' => $icon, 'updated_at' => now()];
            if ($row->title === $wrongTitle) {
                $values['title'] = \Exceedone\Exment\Model\Menu::systemTitle($name);
            }
            DB::table($menuTable)->where('id', $row->id)->update($values);
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // the menus are not removed (someone may have moved or renamed them)
    }
};
