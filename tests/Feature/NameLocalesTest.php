<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\MenuTitle;
use Exceedone\Exment\Services\NameLocales;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;
use Exceedone\Exment\Services\UserLocale;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\Request;

/**
 * The names of tables, columns, views and menus in the language of the user (7.3, ISS02859).
 * They are kept in the options (name_locale_<locale>); the stored name stays the name, and is
 * what the settings pages, the API, the templates and the exports use.
 */
class NameLocalesTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const EN_TABLE = 'Edit table (en)';
    protected const EN_COLUMN = 'Text (en)';
    protected const EN_VIEW = 'All rows (en)';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        NameLocales::reset();
    }

    protected function tearDown(): void
    {
        NameLocales::reset();
        \App::setLocale(UserLocale::siteDefault());
        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        if ($user) {
            $user->setSettingValue(UserLocale::SETTING_KEY, null);
        }
        UserLocale::clearCache();
        System::clearCache();
        parent::tearDown();
    }

    protected function table(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
    }

    /**
     * The table, its column "text" and a view, given English names.
     *
     * @return array<int, mixed> the table, the column, the view
     */
    protected function translated(): array
    {
        $table = $this->table();
        $table->setOption(NameLocales::key('en'), static::EN_TABLE);
        $table->save();
        $column = CustomColumn::getEloquent('text', $table);
        $column->setOption(NameLocales::key('en'), static::EN_COLUMN);
        $column->save();
        $view = CustomView::where('custom_table_id', $table->id)->orderBy('id')->firstOrFail();
        $view->name_locales = ['en' => static::EN_VIEW];
        $view->save();
        System::clearCache();
        return [CustomTable::getEloquent($table->id), CustomColumn::getEloquent($column->id), CustomView::query()->where('id', $view->id)->firstOrFail()];
    }

    protected function beEnglish(): LoginUser
    {
        $user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user->setSettingValue(UserLocale::SETTING_KEY, 'en');
        UserLocale::clearCache();
        System::clearRequestSession();
        NameLocales::reset();
        $this->be($user);
        return $user;
    }

    protected function onPage(string $path): void
    {
        $request = Request::create(admin_url($path));
        app()->instance('request', $request);
    }

    public function testTheNameInTheLanguageOfThePage(): void
    {
        $this->assertNotSame('en', UserLocale::siteDefault(), 'the test site is not English');
        [$table, $column, $view] = $this->translated();
        $storedTable = strval($table->getRawOriginal('table_view_name'));
        $storedColumn = strval($column->getRawOriginal('column_view_name'));
        $storedView = strval($view->getRawOriginal('view_view_name'));
        $this->onPage('data/' . $table->table_name);

        \App::setLocale('en');
        $this->assertSame(static::EN_TABLE, $table->table_view_name);
        $this->assertSame(static::EN_COLUMN, $column->column_view_name);
        $this->assertSame(static::EN_VIEW, $view->view_view_name);
        // a language without a name: the stored one
        \App::setLocale('de');
        $this->assertSame($storedTable, $table->table_view_name);
        \App::setLocale(UserLocale::siteDefault());
        $this->assertSame($storedTable, $table->table_view_name);
        $this->assertSame($storedColumn, $column->column_view_name);
        $this->assertSame($storedView, $view->view_view_name);

        // in code that asks for the stored names
        \App::setLocale('en');
        $this->assertSame($storedTable, NameLocales::raw(function () use ($table) {
            return $table->table_view_name;
        }));
        $this->assertSame(['en' => static::EN_TABLE], $table->name_locales);
        $this->assertSame(['en' => static::EN_COLUMN], $column->toArray()['name_locales']);
    }

    /**
     * Where a name is edited, copied, exported or read by a program, the stored names.
     */
    public function testThePagesWhereTheNamesAreAsStored(): void
    {
        $table = $this->table();
        $off = ['table', "table/{$table->id}/edit", "column/{$table->table_name}/1/edit", "view/{$table->table_name}/1/edit",
            "copy/{$table->table_name}", 'auth/menu', 'auth/menu/1/edit', 'template', 'studio', 'api/table/' . $table->table_name,
            'api/mcp', "data/{$table->table_name}/import", "data/{$table->table_name}/export"];
        foreach ($off as $path) {
            NameLocales::reset();
            $this->onPage($path);
            $this->assertFalse(NameLocales::active(), "$path: as stored");
        }
        $on = ['', "data/{$table->table_name}", "data/{$table->table_name}/1", 'dashboard', 'webapi/notifyPage', 'search'];
        foreach ($on as $path) {
            NameLocales::reset();
            $this->onPage($path);
            $this->assertTrue(NameLocales::active(), "$path: in the language");
        }
        NameLocales::reset();
        $request = Request::create(admin_url("data/{$table->table_name}") . '?_export_=all');
        app()->instance('request', $request);
        $this->assertFalse(NameLocales::active(), 'a grid exported as a file');
    }

    public function testAUserOfEnglishSeesTheEnglishNames(): void
    {
        [$table] = $this->translated();
        $this->beEnglish();
        $this->get(admin_url('data/' . $table->table_name))->assertOk()->assertSee(static::EN_TABLE)->assertSee(static::EN_COLUMN);
    }

    /**
     * Saving the settings as a user of another language never writes a translation into the
     * stored name: the forms show the stored names, and the names of the languages as their own.
     */
    public function testSavingTheSettingsAsAUserOfAnotherLanguageKeepsTheNames(): void
    {
        [$table, $column, $view] = $this->translated();
        $storedTable = strval($table->getRawOriginal('table_view_name'));
        $storedColumn = strval($column->getRawOriginal('column_view_name'));
        $storedView = strval($view->getRawOriginal('view_view_name'));
        $this->beEnglish();

        // the table: the form shows the stored name, and the English one in its own field
        $form = (string) $this->get(admin_url("table/{$table->id}/edit"))->assertOk()->getContent();
        $this->assertStringContainsString('value="' . e($storedTable) . '"', $form);
        $this->assertStringContainsString('name="options[' . NameLocales::key('en') . ']"', $form);
        $this->assertStringContainsString('value="' . e(static::EN_TABLE) . '"', $form);
        $this->patch(admin_url("table/{$table->id}"), [
            'table_view_name' => $storedTable,
            'options' => [NameLocales::key('de') => 'Tabelle (de)'],
        ])->assertRedirect(admin_url('table'))->assertSessionHasNoErrors();
        $saved = CustomTable::query()->where('id', $table->id)->firstOrFail();
        $this->assertSame($storedTable, $saved->getRawOriginal('table_view_name'));
        $this->assertSame('Tabelle (de)', $saved->getOption(NameLocales::key('de')));
        $this->assertSame(static::EN_TABLE, $saved->getOption(NameLocales::key('en')), 'the other languages stay');

        // the column
        $form = (string) $this->get(admin_url("column/{$table->table_name}/{$column->id}/edit"))->assertOk()->getContent();
        $this->assertStringContainsString('value="' . e($storedColumn) . '"', $form);
        $this->patch(admin_url("column/{$table->table_name}/{$column->id}"), [
            'column_view_name' => $storedColumn,
            'options' => [NameLocales::key('de') => 'Text (de)'],
        ])->assertRedirect(admin_url("column/{$table->table_name}"))->assertSessionHasNoErrors();
        $saved = CustomColumn::query()->where('id', $column->id)->firstOrFail();
        $this->assertSame($storedColumn, $saved->getRawOriginal('column_view_name'));
        $this->assertSame('Text (de)', $saved->getOption(NameLocales::key('de')));

        // the view
        $form = (string) $this->get(admin_url("view/{$table->table_name}/{$view->id}/edit"))->assertOk()->getContent();
        $this->assertStringContainsString('value="' . e($storedView) . '"', $form);
        $this->assertStringContainsString('name="name_locales[en]"', $form);
        $this->patch(admin_url("view/{$table->table_name}/{$view->id}"), [
            'view_view_name' => $storedView,
            'name_locales' => ['en' => static::EN_VIEW, 'de' => 'Alle (de)'],
        ]);
        $saved = CustomView::query()->where('id', $view->id)->firstOrFail();
        $this->assertSame($storedView, $saved->getRawOriginal('view_view_name'));
        $this->assertSame(['de' => 'Alle (de)', 'en' => static::EN_VIEW], $saved->name_locales);

        // a menu
        $menu = Menu::where('menu_type', MenuType::SYSTEM)->where('menu_name', 'home')->firstOrFail();
        $storedMenu = strval($menu->title);
        $form = (string) $this->get(admin_url("auth/menu/{$menu->id}/edit"))->assertOk()->getContent();
        $this->assertStringContainsString('value="' . e($storedMenu) . '"', $form);
        $this->patch(admin_url("auth/menu/{$menu->id}"), [
            'title' => $storedMenu,
            'menu_type' => MenuType::SYSTEM,
            'name_locales' => ['en' => 'Our home (en)'],
        ]);
        $saved = Menu::query()->where('id', $menu->id)->firstOrFail();
        $this->assertSame($storedMenu, $saved->getRawOriginal('title'));
        $this->assertSame(['en' => 'Our home (en)'], $saved->name_locales);
    }

    /**
     * The menu: its own name in the language first; a menu of a table called as its table
     * follows the table; a title changed by hand stays.
     */
    public function testTheMenu(): void
    {
        $this->onPage('');
        $table = $this->table();
        $stored = strval($table->getRawOriginal('table_view_name'));
        $tableOptions = json_encode([NameLocales::key('en') => static::EN_TABLE]);
        $rows = [
            ['id' => 1, 'menu_type' => MenuType::TABLE, 'menu_name' => $table->table_name, 'title' => $stored, 'table_view_name' => $stored, 'table_options' => $tableOptions, 'options' => []],
            ['id' => 2, 'menu_type' => MenuType::TABLE, 'menu_name' => $table->table_name, 'title' => 'Renamed by hand', 'table_view_name' => $stored, 'table_options' => $tableOptions, 'options' => []],
            ['id' => 3, 'menu_type' => MenuType::CUSTOM, 'menu_name' => 'mine', 'title' => '私のリンク', 'options' => [NameLocales::key('en') => 'My link']],
            ['id' => 4, 'menu_type' => MenuType::TABLE, 'menu_name' => $table->table_name, 'title' => $stored, 'table_view_name' => $stored, 'table_options' => $tableOptions, 'options' => [NameLocales::key('en') => 'Own (en)']],
        ];
        $out = MenuTitle::localizeRows($rows, 'en');
        $this->assertSame([static::EN_TABLE, 'Renamed by hand', 'My link', 'Own (en)'], array_column($out, 'title'));
        $this->assertSame([$stored, 'Renamed by hand', '私のリンク', $stored], array_column(MenuTitle::localizeRows($rows, UserLocale::siteDefault()), 'title'));
        // the menu settings: as stored
        $this->onPage('auth/menu');
        NameLocales::reset();
        $this->assertSame([$stored, 'Renamed by hand', '私のリンク', $stored], array_column(MenuTitle::localizeRows($rows, 'en'), 'title'));
    }

    /**
     * The API and MCP send the stored names, and the names of the languages beside them.
     */
    public function testTheApiSendsTheStoredNameAndTheNamesOfTheLanguages(): void
    {
        [$table] = $this->translated();
        $stored = strval($table->getRawOriginal('table_view_name'));
        \App::setLocale('en');
        $this->onPage('api/table/' . $table->table_name);
        NameLocales::reset();
        $array = $table->toArray();
        $this->assertSame($stored, $array['table_view_name']);
        $this->assertSame(['en' => static::EN_TABLE], $array['name_locales']);

        $tools = new \Exceedone\Exment\Services\Mcp\McpTools(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $method = new \ReflectionMethod($tools, 'getTable');
        $method->setAccessible(true);
        $answer = $method->invoke($tools, $table);
        $this->assertSame($stored, $answer['label']);
        $this->assertSame(['en' => static::EN_TABLE], $answer['name_locales']);
    }

    /**
     * A template with lang/<locale>/lang.json gives its tables, columns and views the names of
     * all its languages (and English, the language of config.json).
     */
    public function testATemplateGivesTheNamesOfAllItsLanguages(): void
    {
        \App::setLocale('ja');
        $json = (new TemplateImporter())->getMergeJson();
        $baseInfo = collect((array) $json['custom_tables'])->firstWhere('table_name', 'base_info');
        $this->assertNotNull($baseInfo);
        $this->assertSame('基本情報', $baseInfo['table_view_name']);
        $this->assertSame('Base Info', array_get($baseInfo, 'options.' . NameLocales::key('en')));
        $this->assertSame('Basisinformationen', array_get($baseInfo, 'options.' . NameLocales::key('de')));
        $this->assertArrayNotHasKey(NameLocales::key('ja'), (array) array_get($baseInfo, 'options'), 'the language of the import is the name itself');
        $company = collect((array) $baseInfo['custom_columns'])->firstWhere('column_name', 'company_name');
        $this->assertNotNull($company);
        $this->assertNotSame('', strval(array_get($company, 'options.' . NameLocales::key('en'))));
        // the menus are left to MenuTitle
        foreach ($json['admin_menu'] as $menu) {
            $this->assertArrayNotHasKey(NameLocales::key('en'), (array) array_get($menu, 'options'));
        }
    }
}
