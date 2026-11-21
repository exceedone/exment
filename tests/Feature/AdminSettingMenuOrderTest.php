<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\DB;

/**
 * The order of the menus of the admin settings (7.2.0): a site updated keeps
 * the order its administrator made, and takes the order of a new site when
 * it still has an order an Exment made.
 */
class AdminSettingMenuOrderTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function migration(): mixed
    {
        return require base_path('vendor/exceedone/exment/database/migrations/2026_11_25_000000_reorder_admin_setting_menus.php');
    }

    protected function adminId(): int
    {
        return intval(DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', 'admin')->value('id'));
    }

    /**
     * Put the children of "Admin Setting" in this order (the rows that are
     * there), with an unknown one (made by the site) in the middle.
     *
     * @param string[] $names
     */
    protected function arrange(array $names): void
    {
        $admin = $this->adminId();
        $rows = DB::table('admin_menu')->where('parent_id', $admin)->get();
        $order = 1;
        foreach ($names as $name) {
            $row = $rows->firstWhere('menu_name', $name);
            if (isset($row)) {
                DB::table('admin_menu')->where('id', $row->id)->update(['order' => $order++]);
            }
        }
        // the others (not named): at the end
        foreach ($rows as $row) {
            if (!in_array($row->menu_name, $names, true)) {
                DB::table('admin_menu')->where('id', $row->id)->update(['order' => $order++]);
            }
        }
    }

    /**
     * @return string[]
     */
    protected function names(): array
    {
        return DB::table('admin_menu')->where('parent_id', $this->adminId())->orderBy('order')->orderBy('id')->pluck('menu_name')->map(function ($name) {
            return strval($name);
        })->all();
    }

    public function testAnUpdatedSiteTakesTheNewOrder(): void
    {
        // a site of 7.1.2 updated: the old template, then what the updates added
        DB::table('admin_menu')->insert([
            'parent_id' => $this->adminId(), 'order' => 99, 'title' => 'My page', 'icon' => 'fa-star',
            'uri' => 'data/information', 'menu_type' => 'custom', 'menu_name' => 'my_page', 'menu_target' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->arrange(['system', 'custom_table', 'menu', 'template', 'backup', 'loginuser', 'role_group', 'workflow', 'plugin', 'studio', 'mail_template', 'login_setting', 'api_setting', 'feature', 'tuning', 'my_page']);
        $this->migration()->up();
        $names = $this->names();
        $known = array_values(array_filter($names, function ($name) {
            return $name !== 'my_page';
        }));
        $expected = array_values(array_filter(
            ['system', 'login_setting', 'api_setting', 'custom_table', 'studio', 'menu', 'workflow', 'mail_template', 'loginuser', 'role_group', 'feature', 'plugin', 'template', 'backup', 'tuning'],
            function ($name) use ($names) {
                return in_array($name, $names, true);
            }
        ));
        $this->assertSame($expected, array_slice($known, 0, count($expected)));
        // the menu of the site comes after the known ones
        $this->assertGreaterThan(array_search('tuning', $names, true) ?: array_search('backup', $names, true), array_search('my_page', $names, true));

        // a second run changes nothing
        $this->migration()->up();
        $this->assertSame($names, $this->names());
    }

    public function testAMenuOfTheSiteAmongTheSystemOnesKeepsTheOrder(): void
    {
        DB::table('admin_menu')->insert([
            'parent_id' => $this->adminId(), 'order' => 99, 'title' => 'My page', 'icon' => 'fa-star',
            'uri' => 'data/information', 'menu_type' => 'custom', 'menu_name' => 'my_page', 'menu_target' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // the menu of the site between "menu" and "template"
        $this->arrange(['system', 'custom_table', 'menu', 'my_page', 'template', 'backup', 'loginuser', 'role_group', 'workflow', 'plugin', 'mail_template']);
        $before = $this->names();
        $this->migration()->up();
        $this->assertSame($before, $this->names());
    }

    public function testMovedMenusOf71KeepTheOrder(): void
    {
        // login_setting moved to the top by an administrator
        $this->arrange(['login_setting', 'system', 'custom_table', 'menu', 'template', 'backup', 'loginuser', 'role_group', 'workflow', 'plugin', 'mail_template', 'api_setting', 'studio']);
        $before = $this->names();
        $this->migration()->up();
        $this->assertSame($before, $this->names());
    }

    public function testANewSiteOf712TakesTheNewOrder(): void
    {
        $this->arrange(['system', 'custom_table', 'menu', 'template', 'backup', 'loginuser', 'role_group', 'workflow', 'plugin', 'studio', 'mail_template', 'login_setting', 'api_setting', 'feature', 'tuning']);
        $this->migration()->up();
        $names = $this->names();
        $this->assertSame(['system', 'login_setting', 'api_setting', 'custom_table', 'studio'], array_slice(array_values(array_filter($names, function ($name) {
            return in_array($name, ['system', 'login_setting', 'api_setting', 'custom_table', 'studio'], true);
        })), 0, 5));
    }

    public function testAnOrderAnAdministratorMadeStays(): void
    {
        $this->arrange(['menu', 'system', 'custom_table', 'template', 'backup', 'loginuser', 'role_group', 'workflow', 'plugin', 'mail_template']);
        $before = $this->names();
        $this->migration()->up();
        $this->assertSame($before, $this->names());
        // the titles and the parents are not touched
        $this->assertSame(1, DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', 'admin')->count());
    }

    public function testASiteWithoutTheAdminParentGetsTheMenus(): void
    {
        // "Admin Setting" removed by an administrator: its menus at the top
        $admin = $this->adminId();
        DB::table('admin_menu')->where('parent_id', $admin)->update(['parent_id' => 0]);
        DB::table('admin_menu')->where('id', $admin)->delete();
        DB::table('admin_menu')->where('menu_type', 'system')->whereIn('menu_name', ['feature', 'tuning', 'studio'])->delete();
        $system = DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'system')->first();
        $this->assertNotNull($system);

        $migration = require base_path('vendor/exceedone/exment/database/migrations/2026_11_26_000000_add_admin_setting_menus_without_admin_parent.php');
        $migration->up();
        foreach (['feature', 'tuning', 'studio', 'login_setting', 'api_setting'] as $name) {
            $rows = DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', $name)->get();
            $this->assertCount(1, $rows, $name);
            $this->assertSame(intval($system->parent_id), intval($rows->first()->parent_id), $name . ': next to the system setting');
        }
        // once only
        $migration->up();
        $this->assertSame(1, DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'feature')->count());
    }

    public function testASiteWithTheAdminParentIsNotChanged(): void
    {
        DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'tuning')->delete();
        $before = DB::table('admin_menu')->count();
        $migration = require base_path('vendor/exceedone/exment/database/migrations/2026_11_26_000000_add_admin_setting_menus_without_admin_parent.php');
        $migration->up();
        // removed by an administrator: not made again
        $this->assertSame($before, DB::table('admin_menu')->count());
    }

    public function testTheMenusOfFeaturesNotUsedAreEditedAndExported(): void
    {
        \Exceedone\Exment\Model\System::features(['studio' => ['enabled' => false, 'role_groups' => null, 'menu' => true]]);
        \Exceedone\Exment\Model\System::clearRequestSession();
        \Exceedone\Exment\Services\Features::reset();
        $this->be(\Exceedone\Exment\Model\LoginUser::findOrFail(\Exceedone\Exment\Tests\TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        try {
            $names = function (array $nodes): array {
                return collect($nodes)->where('menu_type', 'system')->pluck('menu_name')->map('strval')->all();
            };
            // not in the menu of the pages
            $this->assertNotContains('studio', $names((new \Exceedone\Exment\Model\Menu())->allNodes()));
            // in the editing of the menus (marked) and in a template
            $nodes = (new \Exceedone\Exment\Model\Menu())->withHidden()->allNodes();
            $this->assertContains('studio', $names($nodes));
            $this->assertTrue(boolval(collect($nodes)->firstWhere('menu_name', 'studio')['feature_hidden'] ?? false));
            $page = $this->get(admin_url('auth/menu'));
            $page->assertOk();
            $page->assertSee(exmtrans('menu.feature_hidden'));

            /** @var array<string, mixed> $config */
            $config = [];
            $method = new \ReflectionMethod(\Exceedone\Exment\Services\TemplateImportExport\TemplateExporter::class, 'setTemplateMenu');
            $method->setAccessible(true);
            $method->invokeArgs(null, [&$config, [], false]);
            /** @var array<int, array<string, mixed>> $exported */
            $exported = $config['admin_menu'] ?? [];
            $this->assertContains('studio', array_map('strval', array_column($exported, 'menu_name')));
        } finally {
            \Exceedone\Exment\Services\Features::reset();
        }
    }

    public function testAMenuOfTheSiteToThePageIsNotMadeAgain(): void
    {
        // no "Admin Setting"; the site made its own menu to Feature management (a custom menu to the URL)
        $admin = $this->adminId();
        DB::table('admin_menu')->where('parent_id', $admin)->update(['parent_id' => 0]);
        DB::table('admin_menu')->where('id', $admin)->delete();
        DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'feature')->delete();
        DB::table('admin_menu')->insert([
            'parent_id' => 0, 'order' => 99, 'title' => 'Features', 'icon' => 'fa-star',
            'uri' => 'feature', 'menu_type' => 'custom', 'menu_name' => 'my_features', 'menu_target' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // and the two wrong rows of a new Japanese site of 7.1.0, out of "Admin Setting"
        DB::table('admin_menu')->where('menu_type', 'system')->whereIn('menu_name', ['login_setting', 'api_setting'])->delete();
        DB::table('admin_menu')->insert([
            'parent_id' => 0, 'order' => 100, 'title' => 'ログイン設定', 'icon' => 'fa-sign-in',
            'uri' => 'login_setting', 'menu_type' => 'system', 'menu_name' => 'login_setting', 'menu_target' => 'api_setting',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('admin_menu')->insert([
            'parent_id' => 0, 'order' => 101, 'title' => 'メール送信履歴', 'icon' => 'fa-table',
            'uri' => null, 'menu_type' => 'table', 'menu_name' => 'mail_send_log', 'menu_target' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require base_path('vendor/exceedone/exment/database/migrations/2026_11_26_000000_add_admin_setting_menus_without_admin_parent.php');
        $migration->up();
        $this->assertFalse(DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'feature')->exists(), 'made again next to the menu of the site');
        foreach (['login_setting', 'api_setting'] as $name) {
            $rows = DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', $name)->get();
            $this->assertCount(1, $rows, $name);
            $this->assertSame($name, $rows->first()->menu_target);
        }
        $this->assertFalse(DB::table('admin_menu')->where('menu_type', 'table')->where('menu_name', 'mail_send_log')->whereNull('menu_target')->exists());
    }

    public function testAMenuWithoutANameInTheLanguageGetsAName(): void
    {
        // a menu without a name in any language: its own name, not the key
        $this->assertSame('no_such_menu', \Exceedone\Exment\Model\Menu::systemTitle('no_such_menu'));
        // a site in a language without the names
        $before = config('exment.locale');
        try {
            config(['exment.locale' => 'xx']);
            $title = \Exceedone\Exment\Model\Menu::systemTitle('feature');
            $this->assertStringStartsNotWith('exment::', $title);
            $this->assertNotSame('', $title);
        } finally {
            config(['exment.locale' => $before]);
        }
    }

    public function testTheMenusAreNamedInTheLanguageOfTheSite(): void
    {
        $locale = app()->getLocale();
        $before = config('exment.locale');
        try {
            // the site in Japanese, the update from the command line in English
            config(['exment.locale' => 'ja']);
            app()->setLocale('en');
            $ja = strval(trans('exment::exment.menu.system_definitions.feature', [], 'ja'));
            $en = strval(trans('exment::exment.menu.system_definitions.feature', [], 'en'));
            $this->assertNotSame($ja, $en);
            $this->assertSame($ja, \Exceedone\Exment\Model\Menu::systemTitle('feature'));

            // named in English by the update: in Japanese; a name of the site stays
            DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'feature')->update(['title' => $en]);
            DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'tuning')->update(['title' => 'Speed']);
            $migration = require base_path('vendor/exceedone/exment/database/migrations/2026_11_26_000001_menu_titles_in_the_site_language.php');
            $migration->up();
            $this->assertSame($ja, DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'feature')->value('title'));
            $this->assertSame('Speed', DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'tuning')->value('title'));
        } finally {
            config(['exment.locale' => $before]);
            app()->setLocale($locale);
        }
    }
}
