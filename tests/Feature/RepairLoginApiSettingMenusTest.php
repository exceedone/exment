<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\DB;

/**
 * 2026_11_22_000000_repair_login_api_setting_menus_of_japanese_install:
 * a site newly installed with v7.1.0 in Japanese got a table menu "mail_send_log" where
 * "Login Setting" should be, and "Login Setting" opening the API setting. The migration puts
 * the two rows right, and changes nothing on a site that does not have them.
 */
class RepairLoginApiSettingMenusTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected const MIGRATION = '2026_11_22_000000_repair_login_api_setting_menus_of_japanese_install';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testMenusOfAJapaneseInstallOfV710AreRepaired(): void
    {
        $admin = $this->adminMenu();
        [$loginId, $apiId] = $this->makeTheMenusOfAJapaneseInstallOfV710($admin->id);
        $others = $this->otherMenus([$loginId, $apiId]);

        $this->runMigration();

        $this->assertSame([
            'parent_id' => intval($admin->id),
            'order' => 12,
            'menu_type' => 'system',
            'menu_name' => 'login_setting',
            'menu_target' => 'login_setting',
            'uri' => 'login_setting',
            'title' => exmtrans('menu.system_definitions.login_setting'),
            'icon' => 'fa-sign-in',
        ], $this->row($loginId));
        $this->assertSame([
            'parent_id' => intval($admin->id),
            'order' => 13,
            'menu_type' => 'system',
            'menu_name' => 'api_setting',
            'menu_target' => 'api_setting',
            'uri' => 'api_setting',
            'title' => exmtrans('menu.system_definitions.api_setting'),
            'icon' => 'fa-code-fork',
        ], $this->row($apiId));

        $this->assertSame(1, Menu::where('menu_name', 'login_setting')->count());
        $this->assertSame(1, Menu::where('menu_name', 'api_setting')->count());
        $this->assertFalse(Menu::where('menu_name', 'mail_send_log')->exists());
        $this->assertSame($others, $this->otherMenus([$loginId, $apiId]), 'no other menu is changed');
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $this->makeTheMenusOfAJapaneseInstallOfV710($this->adminMenu()->id);
        $this->runMigration();
        $before = $this->otherMenus([]);

        $this->runMigration();

        $this->assertSame($before, $this->otherMenus([]));
    }

    /**
     * A site updated from v7.0.x, an English site, a site installed with a later version.
     */
    public function testACorrectSiteIsNotChanged(): void
    {
        $admin = $this->adminMenu();
        foreach (['login_setting', 'api_setting'] as $name) {
            $this->assertSame(1, Menu::where('menu_type', 'system')->where('menu_name', $name)->where('menu_target', $name)->where('parent_id', $admin->id)->count(), "the test site has $name");
        }
        $before = $this->otherMenus([]);

        $this->runMigration();

        $this->assertSame($before, $this->otherMenus([]));
    }

    public function testASiteWithoutTheTwoMenusIsNotChanged(): void
    {
        // someone removed the two menus: they are not added again
        Menu::where('menu_type', 'system')->whereIn('menu_name', ['login_setting', 'api_setting'])->delete();
        $before = $this->otherMenus([]);

        $this->runMigration();

        $this->assertSame($before, $this->otherMenus([]));
    }

    public function testAMenuOfTheTableMailSendLogIsNotChanged(): void
    {
        // a menu that someone made for the table "mail_send_log" has the table as its target
        $admin = $this->adminMenu();
        $id = DB::table($this->menuTable())->insertGetId([
            'parent_id' => intval($admin->id),
            'order' => 50,
            'title' => 'メール送信履歴',
            'icon' => 'fa-envelope-o',
            'uri' => 'mail_send_log',
            'menu_type' => 'table',
            'menu_name' => 'mail_send_log',
            'menu_target' => '7',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = $this->otherMenus([]);

        $this->runMigration();

        $this->assertSame($before, $this->otherMenus([]));
        $this->assertSame('table', $this->row($id)['menu_type']);
    }

    public function testATitleThatSomeoneChangedIsKept(): void
    {
        [$loginId, $apiId] = $this->makeTheMenusOfAJapaneseInstallOfV710($this->adminMenu()->id);
        DB::table($this->menuTable())->where('id', $loginId)->update(['title' => 'ログインの設定（社内）']);

        $this->runMigration();

        $this->assertSame('ログインの設定（社内）', $this->row($loginId)['title']);
        $this->assertSame('login_setting', $this->row($loginId)['menu_name']);
        $this->assertSame('api_setting', $this->row($apiId)['menu_name']);
    }

    public function testApiSettingIsAddedWhenTheWrongRowOfItWasDeleted(): void
    {
        $admin = $this->adminMenu();
        [$loginId, $apiId] = $this->makeTheMenusOfAJapaneseInstallOfV710($admin->id);
        DB::table($this->menuTable())->where('id', $apiId)->delete();

        $this->runMigration();

        $this->assertSame('login_setting', $this->row($loginId)['menu_name']);
        $this->assertSame(1, Menu::where('menu_type', 'system')->where('menu_name', 'api_setting')->where('menu_target', 'api_setting')->where('parent_id', $admin->id)->count());
    }

    /**
     * What v7.1.0 made under "Admin Setting" on a new Japanese site, in place of the two menus.
     *
     * @return array{0:int, 1:int} the id of the row that should be "Login Setting", and of "API Setting"
     */
    protected function makeTheMenusOfAJapaneseInstallOfV710(int $adminId): array
    {
        Menu::where('menu_type', 'system')->whereIn('menu_name', ['login_setting', 'api_setting'])->delete();
        $base = ['parent_id' => $adminId, 'created_at' => now(), 'updated_at' => now()];

        $loginId = DB::table($this->menuTable())->insertGetId($base + [
            'order' => 12,
            'title' => 'メール送信履歴',
            'icon' => 'fa-envelope-o',
            'uri' => 'mail_send_log',
            'menu_type' => 'table',
            'menu_name' => 'mail_send_log',
            'menu_target' => null,
        ]);
        $apiId = DB::table($this->menuTable())->insertGetId($base + [
            'order' => 13,
            'title' => 'ログイン設定',
            'icon' => 'fa-sign-in',
            'uri' => 'login_setting',
            'menu_type' => 'system',
            'menu_name' => 'login_setting',
            'menu_target' => 'api_setting',
        ]);

        return [$loginId, $apiId];
    }

    protected function menuTable(): string
    {
        return config('admin.database.menu_table', 'admin_menu');
    }

    /**
     * @return \stdClass
     */
    protected function adminMenu()
    {
        $admin = DB::table($this->menuTable())->where('menu_type', 'parent_node')->where('menu_name', 'admin')->first();
        $this->assertNotNull($admin);
        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(int $id): array
    {
        $row = DB::table($this->menuTable())->where('id', $id)->first();
        $this->assertNotNull($row);
        return [
            'parent_id' => intval($row->parent_id),
            'order' => intval($row->order),
            'menu_type' => $row->menu_type,
            'menu_name' => $row->menu_name,
            'menu_target' => $row->menu_target,
            'uri' => $row->uri,
            'title' => $row->title,
            'icon' => $row->icon,
        ];
    }

    /**
     * Every menu but the given ones, without the time of the update.
     *
     * @param array<int, int> $exceptIds
     * @return array<int, array<string, mixed>>
     */
    protected function otherMenus(array $exceptIds): array
    {
        return DB::table($this->menuTable())->whereNotIn('id', $exceptIds)->orderBy('id')
            ->get(['id', 'parent_id', 'order', 'menu_type', 'menu_name', 'menu_target', 'uri', 'title', 'icon'])
            ->map(function ($row) {
                return (array) $row;
            })->all();
    }

    protected function runMigration(): void
    {
        $migration = require __DIR__ . '/../../database/migrations/' . static::MIGRATION . '.php';
        $migration->up();
    }
}
