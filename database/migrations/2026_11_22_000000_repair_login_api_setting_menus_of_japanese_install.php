<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Login Setting and API Setting of a site that was NEWLY installed with v7.1.0 in Japanese
     *
     * In v7.1.0 the menus of system_template/lang/ja/lang.json were laid over the menus of
     * system_template/config.json by their position, and lang.json had one more line
     * ("mail_send_log"). So a new Japanese site got, under "Admin Setting":
     *   - a menu "メール送信履歴" (menu_type "table", menu_name "mail_send_log", no target) where
     *     "Login Setting" should be,
     *   - a menu "ログイン設定" (menu_name "login_setting") whose target is "api_setting",
     *   - and no "API Setting".
     * Put these two rows right. Only these rows, and only under "Admin Setting": a site updated
     * from v7.0.x, an English site and a site installed with a later version have neither of
     * them, so nothing is changed there.
     *
     * @return void
     */
    public function up()
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($menuTable)) {
            return;
        }

        $admin = DB::table($menuTable)->where('menu_name', 'admin')->where('menu_type', 'parent_node')->first();
        if (!isset($admin)) {
            return;
        }

        // The row that should be "API Setting": named "login_setting", but its target is "api_setting".
        // First, so that the row repaired below is not taken for it.
        $wrongApi = DB::table($menuTable)
            ->where('parent_id', $admin->id)
            ->where('menu_type', 'system')
            ->where('menu_name', 'login_setting')
            ->where('menu_target', 'api_setting')
            ->first();

        // The row that should be "Login Setting": a "table" menu "mail_send_log" without a target.
        // (A menu of the table "mail_send_log" that someone made has the id of the table as its target.)
        $wrongLogin = DB::table($menuTable)
            ->where('parent_id', $admin->id)
            ->where('menu_type', 'table')
            ->where('menu_name', 'mail_send_log')
            ->whereNull('menu_target')
            ->first();

        if (!isset($wrongApi) && !isset($wrongLogin)) {
            return;
        }

        if (isset($wrongApi)) {
            $this->repair($menuTable, $wrongApi, 'api_setting', 'fa-code-fork', 'ログイン設定');
        }
        if (isset($wrongLogin)) {
            $this->repair($menuTable, $wrongLogin, 'login_setting', 'fa-sign-in', 'メール送信履歴');
        }

        // Still none (e.g. the wrong row was deleted by hand): add it as
        // 2026_11_21_000001_add_login_api_setting_menus_under_admin does.
        $icons = ['login_setting' => 'fa-sign-in', 'api_setting' => 'fa-code-fork'];
        foreach ($icons as $name => $icon) {
            if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', $name)->exists()) {
                continue;
            }
            $order = intval(DB::table($menuTable)->where('parent_id', $admin->id)->max('order')) + 1;
            DB::table($menuTable)->insert([
                'parent_id' => $admin->id,
                'order' => $order,
                'title' => exmtrans('menu.system_definitions.' . $name),
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
     * Make a wrong row the system menu $name. The place (parent, order) is kept.
     * The title is replaced only while it is still the wrong title of the install: a title that someone changed is kept.
     *
     * @param string $menuTable
     * @param \stdClass $menu
     * @param string $name "login_setting" or "api_setting"
     * @param string $icon
     * @param string $wrongTitle the title v7.1.0 gave to this row
     * @return void
     */
    protected function repair(string $menuTable, $menu, string $name, string $icon, string $wrongTitle): void
    {
        // the site already has this menu (someone added it): leave the row as it is, do not make a second one
        if (DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', $name)->where('id', '<>', $menu->id)->exists()) {
            return;
        }

        $values = [
            'menu_type' => 'system',
            'menu_name' => $name,
            'menu_target' => $name,
            'uri' => $name,
            'icon' => $icon,
            'updated_at' => now(),
        ];
        if ($menu->title === $wrongTitle) {
            $values['title'] = exmtrans('menu.system_definitions.' . $name);
        }
        DB::table($menuTable)->where('id', $menu->id)->update($values);
    }

    /**
     * @return void
     */
    public function down()
    {
        // the menus are not put back to the wrong ones
    }
};
