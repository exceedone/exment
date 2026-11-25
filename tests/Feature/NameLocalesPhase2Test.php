<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\NameLocales;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;
use Exceedone\Exment\Services\UserLocale;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\Request;

/**
 * Phase 2 of the names per language (7.3, ISS02859): forms, form blocks, dashboards and their
 * boxes. The same rules as tables, columns and views (NameLocalesTest).
 */
class NameLocalesPhase2Test extends FeatureTestBase
{
    use DatabaseTransactions;

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

    protected function onPage(string $path): void
    {
        app()->instance('request', Request::create(admin_url($path)));
    }

    protected function beEnglish(): void
    {
        $user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user->setSettingValue(UserLocale::SETTING_KEY, 'en');
        UserLocale::clearCache();
        System::clearRequestSession();
        NameLocales::reset();
        $this->be($user);
    }

    public function testTheNamesOfFormsBlocksDashboardsAndBoxes(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $form = CustomForm::where('custom_table_id', $table->id)->orderBy('id')->firstOrFail();
        $form->name_locales = ['en' => 'Form (en)'];
        $form->save();
        $block = CustomFormBlock::where('custom_form_id', $form->id)->orderBy('id')->firstOrFail();
        $block->name_locales = ['en' => 'Block (en)'];
        $block->save();
        $dashboard = Dashboard::query()->orderBy('id')->firstOrFail();
        $dashboard->name_locales = ['en' => 'Dashboard (en)'];
        $dashboard->save();
        $box = DashboardBox::where('dashboard_id', $dashboard->id)->orderBy('id')->firstOrFail();
        $box->name_locales = ['en' => 'Box (en)'];
        $box->save();

        $this->onPage('');
        \App::setLocale('en');
        $this->assertSame('Form (en)', CustomForm::query()->where('id', $form->id)->firstOrFail()->form_view_name);
        $this->assertSame('Block (en)', CustomFormBlock::query()->where('id', $block->id)->firstOrFail()->form_block_view_name);
        $this->assertSame('Dashboard (en)', Dashboard::query()->where('id', $dashboard->id)->firstOrFail()->dashboard_view_name);
        $this->assertSame('Box (en)', DashboardBox::query()->where('id', $box->id)->firstOrFail()->dashboard_box_view_name);
        \App::setLocale(UserLocale::siteDefault());
        $this->assertSame($dashboard->getRawOriginal('dashboard_view_name'), Dashboard::query()->where('id', $dashboard->id)->firstOrFail()->dashboard_view_name);
    }

    public function testThePagesThatEditThemShowTheStoredNames(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $off = ["form/{$table->table_name}", "form/{$table->table_name}/1/edit", 'dashboard/create', 'dashboard/1/edit', 'dashboardbox/create', 'dashboardbox/1/edit'];
        foreach ($off as $path) {
            NameLocales::reset();
            $this->onPage($path);
            $this->assertFalse(NameLocales::active(), "$path: as stored");
        }
        // the dashboard itself and the boxes it draws: in the language
        foreach (['', 'dashboard/1', 'dashboardbox/html/abc'] as $path) {
            NameLocales::reset();
            $this->onPage($path);
            $this->assertTrue(NameLocales::active(), "$path: in the language");
        }
    }

    /**
     * Saving a dashboard as a user of another language keeps the stored name.
     */
    public function testSavingADashboardAsAUserOfAnotherLanguageKeepsTheName(): void
    {
        $dashboard = Dashboard::query()->orderBy('id')->firstOrFail();
        $dashboard->name_locales = ['en' => 'Dashboard (en)'];
        $dashboard->save();
        $before = Dashboard::query()->where('id', $dashboard->id)->firstOrFail()->name_locales;
        $stored = strval($dashboard->getRawOriginal('dashboard_view_name'));
        $this->beEnglish();

        $page = (string) $this->get(admin_url("dashboard/{$dashboard->id}/edit"))->assertOk()->getContent();
        $this->assertStringContainsString('value="' . e($stored) . '"', $page);
        $this->assertStringContainsString('name="name_locales[en]"', $page);
        $this->patch(admin_url("dashboard/{$dashboard->id}"), [
            'dashboard_view_name' => $stored,
            'name_locales' => ['en' => 'Dashboard (en)', 'de' => 'Dashboard (de)'],
        ]);
        $saved = Dashboard::query()->where('id', $dashboard->id)->firstOrFail();
        $this->assertSame($stored, $saved->getRawOriginal('dashboard_view_name'));
        // the two languages sent; the names the system template gave in the others stay
        $locales = $saved->name_locales;
        $this->assertSame('Dashboard (de)', $locales['de'] ?? null);
        $this->assertSame('Dashboard (en)', $locales['en'] ?? null);
        foreach (array_diff_key($before, ['de' => 1, 'en' => 1]) as $locale => $name) {
            $this->assertSame($name, $locales[$locale] ?? null, "$locale stays");
        }
    }

    /**
     * The form designer: the form name and the block names, stored ones in the fields, the other
     * languages beside them.
     */
    public function testTheFormDesignerShowsTheStoredNamesAndTheLanguages(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $form = CustomForm::where('custom_table_id', $table->id)->orderBy('id')->firstOrFail();
        $form->name_locales = ['en' => 'Form (en)'];
        $form->save();
        $stored = strval($form->getRawOriginal('form_view_name'));
        $this->beEnglish();

        $page = (string) $this->get(admin_url("form/{$table->table_name}/{$form->id}/edit"))->assertOk()->getContent();
        $this->assertStringContainsString('value="' . e($stored) . '"', $page);
        $this->assertStringContainsString('name="name_locales[en]"', $page);
        $this->assertStringContainsString('value="Form (en)"', $page);
        $this->assertStringContainsString('[name_locales][en]', $page, 'the fields of the blocks');
    }

    /**
     * The system template gives the dashboard and its boxes the names of its languages.
     */
    public function testATemplateGivesDashboardsAndBoxesTheirNames(): void
    {
        \App::setLocale('ja');
        $json = (new TemplateImporter())->getMergeJson();
        $dashboard = $json['dashboards'][0] ?? null;
        $this->assertIsArray($dashboard);
        $this->assertSame('既定のダッシュボード', $dashboard['dashboard_view_name']);
        $this->assertSame('Default dashboard', array_get($dashboard, 'options.' . NameLocales::key('en')));
        $box = $dashboard['dashboard_boxes'][0] ?? null;
        $this->assertIsArray($box);
        $this->assertSame('Infomation list', array_get($box, 'options.' . NameLocales::key('en')));
    }
}
