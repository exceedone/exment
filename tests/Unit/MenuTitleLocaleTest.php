<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Services\MenuTitle;

/**
 * The left menu in the language of the user (ISS02858).
 *
 * admin_menu.title holds the text of the language the site was installed in. The menus Exment made
 * (the pages of the system, the groups and the tables the install makes) are shown in the language of
 * the user while their title is still a text Exment wrote; a changed title, and the menus of the other
 * tables and of the plugins, stay.
 */
class MenuTitleLocaleTest extends UnitTestBase
{
    /** the languages Exment has */
    protected const LOCALES = ['de', 'en', 'es', 'ja', 'ko', 'vi', 'zh_CN'];

    /** the tables the install makes a menu for */
    protected const SYSTEM_TABLES = ['base_info', 'organization', 'user', 'information', 'mail_template', 'system_logs'];

    protected function setUp(): void
    {
        parent::setUp();
        MenuTitle::reset();
    }

    protected function tearDown(): void
    {
        MenuTitle::reset();
        parent::tearDown();
    }

    /**
     * The titles of the menus of the templates of the install (system_template and the one of the
     * performance log): "type|name" => locale => title.
     *
     * @return array<string, array<string, string>>
     */
    protected function templateTitles(): array
    {
        $titles = [];
        foreach (['system_template', 'templates/system_logs'] as $template) {
            $base = exment_package_path($template);
            foreach (static::LOCALES as $locale) {
                $file = $locale === 'en' ? "$base/config.json" : "$base/lang/$locale/lang.json";
                $json = json_decode((string) file_get_contents($file), true);
                $this->assertIsArray($json, "$file is not valid json");
                foreach (array_get($json, 'admin_menu', []) as $menu) {
                    if (in_array(array_get($menu, 'menu_type'), [MenuType::SYSTEM, MenuType::PARENT_NODE, MenuType::TABLE], true)) {
                        $titles[$menu['menu_type'] . '|' . $menu['menu_name']][$locale] = $menu['title'];
                    }
                }
            }
        }
        return $titles;
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(string $type, string $name, string $title): array
    {
        return ['id' => 1, 'menu_type' => $type, 'menu_name' => $name, 'title' => $title];
    }

    public function testExmentHasTheSevenLanguages(): void
    {
        $this->assertSame(static::LOCALES, MenuTitle::locales());
    }

    /**
     * Every menu of the system and every group of the install template, installed in any of the
     * languages, is read in any of the languages.
     */
    public function testEveryMenuOfTheInstallTemplateInEveryLanguage(): void
    {
        $titles = $this->templateTitles();
        $this->assertArrayHasKey('system|home', $titles);
        $this->assertArrayHasKey('parent_node|admin', $titles);
        $checked = 0;
        foreach ($titles as $key => $byLocale) {
            [$type, $name] = explode('|', $key);
            $this->assertSame(static::LOCALES, array_values(array_intersect(static::LOCALES, array_keys($byLocale))), "$key has a title in every language");
            foreach ($byLocale as $installed => $stored) {
                foreach ($byLocale as $reader => $expected) {
                    $this->assertSame(
                        $expected,
                        MenuTitle::localize($this->row($type, $name, $stored), $reader),
                        "$key installed in $installed, read in $reader"
                    );
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(7 * 7 * 20, $checked);
    }

    /**
     * The tables the install makes (基本情報, 組織, ユーザー, お知らせ, 通知テンプレート, the performance log):
     * their menus are read in the language of the user.
     */
    public function testTheMenusOfTheSystemTablesInEveryLanguage(): void
    {
        $titles = $this->templateTitles();
        foreach (static::SYSTEM_TABLES as $name) {
            $key = MenuType::TABLE . '|' . $name;
            $this->assertArrayHasKey($key, $titles);
            $this->assertCount(count(static::LOCALES), $titles[$key], "$key has a title in every language");
            foreach ($titles[$key] as $installed => $stored) {
                foreach ($titles[$key] as $reader => $expected) {
                    $this->assertSame($expected, MenuTitle::localize($this->row(MenuType::TABLE, $name, $stored), $reader), "$key installed in $installed, read in $reader");
                }
            }
        }
        $this->assertSame('Base Info', MenuTitle::localize($this->row(MenuType::TABLE, 'base_info', '基本情報'), 'en'));
        $this->assertSame('E-Mail-Vorlage', MenuTitle::localize($this->row(MenuType::TABLE, 'mail_template', '通知テンプレート'), 'de'));
    }

    public function testAChangedTitleOfASystemTableAndTheOtherTablesStay(): void
    {
        foreach (static::LOCALES as $locale) {
            // a title somebody changed
            $this->assertSame('社員', MenuTitle::localize($this->row(MenuType::TABLE, 'user', '社員'), $locale));
            // a table somebody made, even with the text of a system table
            $this->assertSame('ユーザー', MenuTitle::localize($this->row(MenuType::TABLE, 'my_users', 'ユーザー'), $locale));
            // the text of another system table
            $this->assertSame('組織', MenuTitle::localize($this->row(MenuType::TABLE, 'user', '組織'), $locale));
        }
    }

    public function testAJapaneseSiteReadInEnglishAndBack(): void
    {
        $titles = $this->templateTitles();
        $ja = $titles['system|custom_table']['ja'];
        $en = $titles['system|custom_table']['en'];
        $this->assertNotSame($ja, $en);
        $this->assertSame($en, MenuTitle::localize($this->row(MenuType::SYSTEM, 'custom_table', $ja), 'en'));
        $this->assertSame($ja, MenuTitle::localize($this->row(MenuType::SYSTEM, 'custom_table', $en), 'ja'));
        // the language the title was written in: exactly the stored text
        $this->assertSame($ja, MenuTitle::localize($this->row(MenuType::SYSTEM, 'custom_table', $ja), 'ja'));
    }

    /**
     * The install template and the language files do not always say the same ("Dashboard" / "HOME"):
     * the title is answered from the source it was written by.
     */
    public function testATitleIsAnsweredFromTheSourceItCameFrom(): void
    {
        $titles = $this->templateTitles();
        $langJa = trans('exment::exment.menu.system_definitions.home', [], 'ja');
        $langEn = trans('exment::exment.menu.system_definitions.home', [], 'en');
        $this->assertIsString($langJa);
        // written by the install template
        $this->assertSame($titles['system|home']['en'], MenuTitle::localize($this->row(MenuType::SYSTEM, 'home', $titles['system|home']['ja']), 'en'));
        // written by an update (Menu::systemTitle): the language files
        if ($langJa !== $titles['system|home']['ja']) {
            $this->assertSame($langEn, MenuTitle::localize($this->row(MenuType::SYSTEM, 'home', $langJa), 'en'));
        }
    }

    /**
     * The menus an update adds get their title from the language files: every one of them, in every language.
     */
    public function testEveryMenuOfTheLanguageFilesInEveryLanguage(): void
    {
        $template = $this->templateTitles();
        $names = array_keys((array) trans('exment::exment.menu.system_definitions', [], 'en'));
        $this->assertContains('security_check', $names);
        foreach ($names as $name) {
            $byLocale = [];
            foreach (static::LOCALES as $locale) {
                $title = trans('exment::exment.menu.system_definitions.' . $name, [], $locale);
                $this->assertIsString($title, "$name in $locale");
                $this->assertStringStartsNotWith('exment::', $title, "$name has a title in $locale");
                $byLocale[$locale] = $title;
            }
            foreach ($byLocale as $installed => $stored) {
                // a text the install template has too is answered by the template (the same menu)
                if (in_array($stored, array_get($template, 'system|' . $name, []), true)) {
                    continue;
                }
                foreach ($byLocale as $reader => $expected) {
                    $this->assertSame($expected, MenuTitle::localize($this->row(MenuType::SYSTEM, $name, $stored), $reader), "$name written in $installed, read in $reader");
                }
            }
        }
    }

    public function testATitleSomebodyChangedStays(): void
    {
        foreach (static::LOCALES as $locale) {
            $this->assertSame('わが社のホーム', MenuTitle::localize($this->row(MenuType::SYSTEM, 'home', 'わが社のホーム'), $locale));
            $this->assertSame('Our settings', MenuTitle::localize($this->row(MenuType::PARENT_NODE, 'admin', 'Our settings'), $locale));
        }
    }

    public function testTheMenusOfTablesPluginsAndCustomOnesStay(): void
    {
        $titles = $this->templateTitles();
        $ja = $titles['system|home']['ja'];
        foreach ([MenuType::TABLE, MenuType::PLUGIN, MenuType::CUSTOM] as $type) {
            // (a table named "home" is no table of the install)
            // even with the name and the text of a menu of the system
            $this->assertSame($ja, MenuTitle::localize($this->row($type, 'home', $ja), 'en'), $type);
        }
        // a menu of the system Exment does not know, and a row without a name
        $this->assertSame($ja, MenuTitle::localize($this->row(MenuType::SYSTEM, 'no_such_menu', $ja), 'en'));
        $this->assertSame($ja, MenuTitle::localize(['menu_type' => MenuType::SYSTEM, 'menu_name' => null, 'title' => $ja], 'en'));
        $this->assertNull(MenuTitle::localize(['menu_type' => MenuType::SYSTEM, 'menu_name' => 'home', 'title' => null], 'en'));
    }

    public function testALanguageExmentDoesNotHaveKeepsTheStoredTitle(): void
    {
        $ja = $this->templateTitles()['system|home']['ja'];
        $this->assertSame($ja, MenuTitle::localize($this->row(MenuType::SYSTEM, 'home', $ja), 'fr'));
    }

    /**
     * The menu of the pages follows the language of the page; the rows for the export of a template
     * and for the import keep the stored texts.
     */
    public function testTheMenuOfTheSiteFollowsTheLanguageOfThePage(): void
    {
        $this->be(\Exceedone\Exment\Model\LoginUser::find(1));
        $titles = $this->templateTitles();
        $stored = [];
        foreach (\DB::table((new Menu())->getTable())->whereIn('menu_type', [MenuType::SYSTEM, MenuType::PARENT_NODE, MenuType::TABLE])->get() as $menu) {
            $stored[intval($menu->id)] = (array) $menu;
        }
        $this->assertNotEmpty($stored);

        $before = \App::getLocale();
        try {
            $seen = 0;
            $tables = 0;
            foreach (['en', 'ja', 'vi'] as $locale) {
                \App::setLocale($locale);
                foreach ((new Menu())->allNodes() as $node) {
                    $row = array_get($stored, intval($node['id']));
                    if (!isset($row)) {
                        continue;
                    }
                    $key = $row['menu_type'] . '|' . $row['menu_name'];
                    if (in_array($row['title'], array_get($titles, $key, []), true)) {
                        $this->assertSame($titles[$key][$locale], $node['title'], "$key in $locale");
                        $seen++;
                        $tables += $row['menu_type'] === MenuType::TABLE ? 1 : 0;
                    }
                }
                // the export of a template / the import: as stored
                foreach ((new Menu())->withHidden()->allNodes() as $node) {
                    if (isset($stored[intval($node['id'])])) {
                        $this->assertSame($stored[intval($node['id'])]['title'], $node['title'], 'stored title in ' . $locale);
                    }
                }
            }
            $this->assertGreaterThan(0, $seen, 'the site has menus with a title of the install template');
            $this->assertGreaterThan(0, $tables, 'the site has menus of the system tables with a title of the install template');
        } finally {
            \App::setLocale($before);
        }
    }

    public function testATitleChangedInTheDatabaseIsShownAsItIsInEveryLanguage(): void
    {
        $this->be(\Exceedone\Exment\Model\LoginUser::find(1));
        $table = (new Menu())->getTable();
        $menu = \DB::table($table)->where('menu_type', MenuType::SYSTEM)->where('menu_name', 'home')->first();
        $this->assertNotNull($menu);
        $before = \App::getLocale();
        \DB::beginTransaction();
        try {
            \DB::table($table)->where('id', $menu->id)->update(['title' => 'Changed by the administrator']);
            \Exceedone\Exment\Services\MenuVisibility::forgetRows();
            \Exceedone\Exment\Model\System::clearRequestSession();
            foreach (['en', 'ja', 'de'] as $locale) {
                \App::setLocale($locale);
                $node = collect((new Menu())->allNodes())->firstWhere('id', $menu->id);
                $this->assertNotNull($node);
                $this->assertSame('Changed by the administrator', $node['title'], $locale);
            }
        } finally {
            \DB::rollBack();
            \App::setLocale($before);
            \Exceedone\Exment\Services\MenuVisibility::forgetRows();
            \Exceedone\Exment\Model\System::clearRequestSession();
        }
    }
}
