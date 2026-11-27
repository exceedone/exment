<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\FeaturePacks;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\Installer\InitializeForm;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Sales (CRM / SFA) in Feature management (ISS00321): a feature that installs a pack
 * (Services\FeaturePacks, templates/sales_pack). Off until it is turned on; "use" installs the
 * tables, the kanban of the deals, the dashboard, the menu, the notice and the role group at once;
 * installing again makes nothing twice and keeps what the site changed; /initialize asks for it.
 *
 * The install makes tables (DDL ends a transaction of MySQL), so this test does not run in a
 * transaction: it removes what it installed, and puts the setting of the features back.
 */
class SalesFeatureTest extends FeatureTestBase
{
    protected const TABLES = ['sales_customer', 'sales_customer_contact', 'sales_deal', 'sales_quote', 'sales_quote_detail', 'sales_contract', 'sales_activity'];
    protected const KANBAN = '5a1e5c0a7b3d4e2f9a01';
    protected const MONTHLY = '5a1e5c0a7b3d4e2f9a02';
    protected const ACTIVITIES = '5a1e5c0a7b3d4e2f9a04';
    protected const NOTIFY = 'e5967aaf67c9ae4b4398';

    /** @var mixed the setting of the features before the test */
    protected $features;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->removePack();
        $this->features = System::features();
        Features::reset();
        config(['exment.features_locked' => '']);
        $this->login();
    }

    protected function tearDown(): void
    {
        $this->removePack();
        System::features($this->features);
        System::clearCache();
        Features::reset();
        parent::tearDown();
    }

    protected function login(): LoginUser
    {
        System::clearRequestSession();
        $user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->be($user);
        return $user;
    }

    protected function removePack(): void
    {
        System::clearCache();
        foreach (array_reverse(static::TABLES) as $name) {
            CustomTable::query()->where('table_name', $name)->get()->each(function ($table) {
                $table->delete();
            });
        }
        Dashboard::query()->where('dashboard_name', 'sales_dashboard')->get()->each(function ($dashboard) {
            $dashboard->dashboard_boxes()->delete();
            $dashboard->delete();
        });
        RoleGroup::query()->where('role_group_name', 'sales_member_group')->get()->each(function ($group) {
            RoleGroupPermission::query()->where('role_group_id', $group->id)->delete();
            $group->delete();
        });
        Menu::query()->whereIn('menu_name', ['sales', 'sales_dashboard'])->delete();
        Notify::query()->where('suuid', static::NOTIFY)->delete();
        System::clearCache();
    }

    /**
     * "Use" on the page of Feature management.
     */
    protected function postUse(bool $enabled): void
    {
        $row = ['present' => '1', 'all' => '1', 'menu' => '1'] + ($enabled ? ['enabled' => '1'] : []);
        $this->post(admin_url('feature'), ['features' => [Features::SALES => $row], 'complete' => '1'])->assertRedirect(admin_url('feature'));
        System::clearCache();
        Features::reset();
    }

    /**
     * @return array<string, int> what the pack made, counted
     */
    protected function counts(): array
    {
        $tableIds = CustomTable::query()->whereIn('table_name', static::TABLES)->pluck('id');
        return [
            'tables' => $tableIds->count(),
            'columns' => CustomColumn::query()->whereIn('custom_table_id', $tableIds)->count(),
            'labels' => CustomColumnMulti::query()->whereIn('custom_table_id', $tableIds)->count(),
            'views' => CustomView::query()->withoutGlobalScopes()->whereIn('custom_table_id', $tableIds)->count(),
            'dashboards' => Dashboard::query()->where('dashboard_name', 'sales_dashboard')->count(),
            'boxes' => Dashboard::query()->where('dashboard_name', 'sales_dashboard')->get()->sum(function ($d) {
                return $d->dashboard_boxes()->count();
            }),
            'menus' => Menu::query()->whereIn('menu_name', array_merge(['sales', 'sales_dashboard'], static::TABLES))->count(),
            'notifies' => Notify::query()->where('suuid', static::NOTIFY)->count(),
            'role_groups' => RoleGroup::query()->where('role_group_name', 'sales_member_group')->count(),
            'permissions' => RoleGroupPermission::query()->whereIn('role_group_id', RoleGroup::query()->where('role_group_name', 'sales_member_group')->pluck('id'))->count(),
        ];
    }

    public function testItIsOffUntilItIsTurnedOn(): void
    {
        $this->assertTrue(Features::exists(Features::SALES));
        $this->assertSame('sales_pack', Features::pack(Features::SALES));
        $this->assertContains(Features::SALES, FeaturePacks::keys());
        $this->assertFalse(Features::enabled(Features::SALES));
        $this->assertSame(0, $this->counts()['tables']);

        $html = (string) $this->get(admin_url('feature'))->assertOk()->getContent();
        $this->assertStringContainsString('name="features[sales][enabled]"', $html);
        $this->assertStringContainsString(e(Features::label(Features::SALES)), $html);
        // the switch of the row is not checked
        $this->assertSame(1, preg_match('/<input[^>]*name="features\[sales\]\[enabled\]"[^>]*>/', $html, $input));
        $this->assertStringNotContainsString('checked', strval($input[0] ?? ''));
    }

    public function testTheWordsOfTheRowAreInEveryLanguageWithoutPack(): void
    {
        $current = \App::getLocale();
        try {
            foreach (['ja', 'en', 'de', 'es', 'ko', 'vi', 'zh_CN'] as $locale) {
                \App::setLocale($locale);
                foreach (['features.sales', 'features.sales_help', 'pack_note', 'who_pack', 'pack_installed', 'pack_failed', 'pack_install_header', 'pack_install_question', 'pack_install_help', 'menu_pack', 'menu_pack_link', 'pack_install_label', 'pack_incomplete'] as $key) {
                    $this->assertTrue(\Lang::has("exment::exment.feature.$key", $locale, false), "$locale: $key");
                    $text = strval(exmtrans("feature.$key"));
                    $this->assertDoesNotMatchRegularExpression('/パック|pack|Paket|paquete|팩|Gói|包/iu', $text, "$locale: $key");
                }
            }
        } finally {
            \App::setLocale($current);
        }
    }

    public function testUseInstallsEverything(): void
    {
        $this->postUse(true);

        $this->assertTrue(Features::enabled(Features::SALES));
        foreach (static::TABLES as $name) {
            $table = CustomTable::getEloquent($name);
            $this->assertNotNull($table, $name);
            $this->assertTrue(\Schema::hasTable(getDBTableName($table)), $name);
        }

        // the activities: kinds, linked to the customer and the deal, the date and time, the owner, the notes
        $activity = CustomTable::getEloquent('sales_activity');
        $columns = $activity->custom_columns()->pluck('column_type', 'column_name')->all();
        $this->assertSame(['activity_type' => 'select_valtext', 'subject' => 'text', 'activity_at' => 'datetime', 'customer' => 'select_table', 'deal' => 'select_table', 'owner' => 'user', 'note' => 'textarea'], $columns);
        $kinds = CustomColumn::getEloquent('activity_type', $activity)->getOption('select_item_valtext');
        foreach (['visit', 'call', 'email', 'next'] as $kind) {
            $this->assertStringContainsString($kind . ',', strval($kinds));
        }
        $this->assertSame(strval(CustomTable::getEloquent('sales_customer')->id), strval(CustomColumn::getEloquent('customer', $activity)->getOption('select_target_table')));
        $this->assertSame(strval(CustomTable::getEloquent('sales_deal')->id), strval(CustomColumn::getEloquent('deal', $activity)->getOption('select_target_table')));

        // the kanban of the deals by stage, with the sums of the expected amounts
        $kanban = CustomView::query()->withoutGlobalScopes()->where('suuid', static::KANBAN)->firstOrFail();
        $deal = CustomTable::getEloquent('sales_deal');
        $this->assertSame(ViewKindType::KANBAN, strval($kanban->view_kind_type));
        $this->assertSame(strval(CustomColumn::getEloquent('stage', $deal)->id), strval($kanban->getOption('kanban_group_column_id')));
        $this->assertSame(strval(CustomColumn::getEloquent('expected_amount', $deal)->id), strval($kanban->getOption('kanban_sum_column_id')));

        // the dashboard: the chart of the month, the deals by owner, the activities, the quotes and contracts near their end
        $dashboard = Dashboard::query()->where('dashboard_name', 'sales_dashboard')->firstOrFail();
        $boxes = $dashboard->dashboard_boxes()->get();
        $this->assertCount(5, $boxes);
        $chart = $boxes->firstWhere('dashboard_box_type', 'chart');
        $this->assertNotNull($chart);
        $this->assertNotEmpty($chart->getOption('chart_axisx'));
        $this->assertNotEmpty($chart->getOption('chart_axisy'));
        foreach ($boxes as $box) {
            $this->assertNotEmpty($box->getOption('target_view_id'), strval($box->dashboard_box_view_name));
        }

        // the menu, the notice, the role group
        $parent = Menu::query()->where('menu_name', 'sales')->where('menu_type', 'parent_node')->firstOrFail();
        $this->assertSame(6, Menu::query()->where('parent_id', $parent->id)->count());
        $this->assertTheDashboardIsTheFirstItemOfTheMenu();
        $notify = Notify::query()->where('suuid', static::NOTIFY)->firstOrFail();
        $this->assertSame(strval(CustomTable::getEloquent('sales_contract')->id), strval($notify->target_id));
        $group = RoleGroup::query()->where('role_group_name', 'sales_member_group')->firstOrFail();
        $this->assertSame(7, RoleGroupPermission::query()->where('role_group_id', $group->id)->count());
    }

    public function testInstallingAgainMakesNothingTwiceAndKeepsTheChanges(): void
    {
        $this->postUse(true);
        $first = $this->counts();
        $this->assertSame(7, $first['tables']);

        // what the site changes after the install
        $customer = CustomTable::getEloquent('sales_customer');
        $customer->table_view_name = 'お客さま';
        $customer->save();
        $kanban = CustomView::query()->withoutGlobalScopes()->where('suuid', static::KANBAN)->firstOrFail();
        $kanban->view_view_name = '私のかんばん';
        $kanban->save();
        $label = CustomColumnMulti::query()->where('custom_table_id', $customer->id)->firstOrFail();
        $label->priority = 9;
        $label->save();
        $notify = Notify::query()->where('suuid', static::NOTIFY)->firstOrFail();
        $settings = (array) $notify->trigger_settings;
        $settings['notify_day'] = '60';
        $notify->trigger_settings = $settings;
        $notify->save();
        $group = RoleGroup::query()->where('role_group_name', 'sales_member_group')->firstOrFail();
        $group->role_group_view_name = '営業部';
        $group->save();
        $menu = Menu::query()->where('menu_name', 'sales')->firstOrFail();
        $menu->title = '営業部のメニュー';
        $menu->save();

        // off and on again (installs again), and once more directly
        $this->postUse(false);
        $this->assertSame(7, $this->counts()['tables'], 'turning it off removes nothing');
        $this->postUse(true);
        FeaturePacks::install(Features::SALES);
        System::clearCache();

        $this->assertSame($first, $this->counts());
        $this->assertSame('お客さま', CustomTable::query()->where('table_name', 'sales_customer')->value('table_view_name'));
        $this->assertSame('私のかんばん', CustomView::query()->withoutGlobalScopes()->where('suuid', static::KANBAN)->value('view_view_name'));
        $this->assertSame(9, intval(CustomColumnMulti::query()->whereKey($label->id)->value('priority')));
        $this->assertSame('60', strval(array_get((array) Notify::query()->where('suuid', static::NOTIFY)->firstOrFail()->trigger_settings, 'notify_day')));
        $this->assertSame('営業部', RoleGroup::query()->whereKey($group->id)->value('role_group_view_name'));
        $this->assertSame('営業部のメニュー', Menu::query()->whereKey($menu->id)->value('title'));
    }

    public function testAMissingTableIsMadeAgainAndTheRoleGroupThereGetsItsPermission(): void
    {
        FeaturePacks::turnOn(Features::SALES);
        $group = RoleGroup::query()->where('role_group_name', 'sales_member_group')->firstOrFail();
        CustomTable::getEloquent('sales_activity')->delete();
        RoleGroupPermission::query()->where('role_group_id', $group->id)->whereNotIn('role_group_target_id', CustomTable::query()->pluck('id'))->delete();
        System::clearCache();
        $this->assertSame(6, RoleGroupPermission::query()->where('role_group_id', $group->id)->count());

        FeaturePacks::install(Features::SALES);
        System::clearCache();

        $activity = CustomTable::getEloquent('sales_activity');
        $this->assertNotNull($activity);
        $this->assertTrue(RoleGroupPermission::query()->where('role_group_id', $group->id)->where('role_group_target_id', $activity->id)->exists());
        $this->assertSame(1, RoleGroup::query()->where('role_group_name', 'sales_member_group')->count());
    }

    public function testTheInitializeScreenAsksAndItsCheckboxInstalls(): void
    {
        $options = (new InitializeForm())->featurePackOptions();
        $this->assertArrayHasKey(Features::SALES, $options);
        $this->assertSame(exmtrans('feature.pack_install_question', ['name' => Features::label(Features::SALES)]), $options[Features::SALES]);

        System::initialized(0);
        System::clearCache();
        try {
            $html = (string) $this->get(admin_url('initialize'))->assertOk()->getContent();
            $this->assertStringContainsString('name="feature_packs[]"', $html);
            $this->assertStringContainsString(e($options[Features::SALES]), $html);
            // the header of the section and the label of the checkbox are not the same words
            $this->assertNotSame(exmtrans('feature.pack_install_header'), exmtrans('feature.pack_install_label'));
            $this->assertStringContainsString(e(exmtrans('feature.pack_install_label')), $html);
        } finally {
            System::initialized(1);
            System::clearCache();
        }

        // the post of the form (after the administrator is saved) installs what is checked
        $this->assertSame([], FeaturePacks::installChosen(['', 'not_a_feature', Features::SALES]));
        System::clearCache();
        Features::reset();
        $this->assertTrue(Features::enabled(Features::SALES));
        $this->assertSame(7, $this->counts()['tables']);
        $this->assertSame(1, $this->counts()['dashboards']);
    }

    /**
     * The first item of the menu "営業・顧客" is the link to the sales dashboard, named in the 7 languages.
     */
    protected function assertTheDashboardIsTheFirstItemOfTheMenu(): Menu
    {
        $parent = Menu::query()->where('menu_name', 'sales')->where('menu_type', 'parent_node')->firstOrFail();
        $items = Menu::query()->where('menu_name', 'sales_dashboard')->get();
        $this->assertCount(1, $items);
        $item = $items->first();
        $this->assertSame('custom', strval($item->menu_type));
        $this->assertSame(intval($parent->id), intval($item->parent_id));
        // as the menu shows them (Menu::getAllNodesFromDatabase): order 0 last, then the order, then the id
        $first = Menu::query()->where('parent_id', $parent->id)->get()->sortBy(function ($menu) {
            return sprintf('%d-%010d-%010d', intval($menu->order) === 0 ? 1 : 0, intval($menu->order), intval($menu->id));
        })->first();
        $this->assertNotNull($first);
        $this->assertGreaterThan(0, intval($item->order), 'order 0 comes last');
        $this->assertSame(intval($item->id), intval($first->id), 'the first item of the menu');
        $dashboard = Dashboard::query()->where('dashboard_name', 'sales_dashboard')->firstOrFail();
        $this->assertSame('?dashboard=' . $dashboard->suuid, strval($item->uri));
        $options = is_array($item->options) ? $item->options : (array) json_decode(strval($item->options), true);
        foreach (['ja', 'en', 'de', 'es', 'ko', 'vi', 'zh_CN'] as $locale) {
            $this->assertNotEmpty(array_get($options, 'name_locale_' . $locale), $locale);
        }
        $this->assertSame('営業のダッシュボード', array_get($options, 'name_locale_ja'));
        $this->assertSame('Sales dashboard', array_get($options, 'name_locale_en'));
        return $item;
    }

    public function testTheMenuLinksToTheDashboardOnceAndAddsItToAMenuThatLacksIt(): void
    {
        FeaturePacks::turnOn(Features::SALES);
        $item = $this->assertTheDashboardIsTheFirstItemOfTheMenu();
        $parentId = intval($item->parent_id);

        // again: not twice, and as the site left it
        $item->title = '私のダッシュボード';
        $item->save();
        FeaturePacks::install(Features::SALES);
        System::clearCache();
        $this->assertSame('私のダッシュボード', $this->assertTheDashboardIsTheFirstItemOfTheMenu()->title);

        // a menu "営業・顧客" without the link (as made before it): the link is added to it, first
        Menu::query()->where('menu_name', 'sales_dashboard')->delete();
        // the orders of a menu made by the template page before (from 1)
        $old = Menu::query()->where('parent_id', $parentId)->orderBy('order')->orderBy('id')->pluck('menu_name')->all();
        foreach ($old as $index => $name) {
            Menu::query()->where('parent_id', $parentId)->where('menu_name', $name)->update(['order' => $index + 1]);
        }
        $children = Menu::query()->where('parent_id', $parentId)->count();
        FeaturePacks::install(Features::SALES);
        System::clearCache();
        $this->assertSame($parentId, intval($this->assertTheDashboardIsTheFirstItemOfTheMenu()->parent_id));
        $this->assertSame($children + 1, Menu::query()->where('parent_id', $parentId)->count());
        $this->assertSame(1, Menu::query()->where('menu_name', 'sales')->count());
        // the others keep their order among them
        $this->assertSame($old, Menu::query()->where('parent_id', $parentId)->where('menu_name', '<>', 'sales_dashboard')->orderBy('order')->orderBy('id')->pluck('menu_name')->all());

        // the link opens the sales dashboard
        $this->login();
        $menu = Menu::query()->where('menu_name', 'sales_dashboard')->firstOrFail();
        $html = (string) $this->get(admin_url(strval($menu->uri)))->assertOk()->getContent();
        $dashboard = Dashboard::query()->where('dashboard_name', 'sales_dashboard')->firstOrFail();
        foreach ($dashboard->dashboard_boxes()->get() as $box) {
            $this->assertStringContainsString(e(strval($box->dashboard_box_view_name)), $html);
        }
    }

    /**
     * An install that stopped on the way: a dashboard without a box, views without parts. The next
     * install makes the missing parts; a view the site changed (a column taken out) stays so.
     */
    public function testARetryMakesTheMissingPartsOfTheViewsAndTheDashboard(): void
    {
        FeaturePacks::turnOn(Features::SALES);
        $dashboard = Dashboard::query()->where('dashboard_name', 'sales_dashboard')->firstOrFail();
        $view = function (string $suuid): CustomView {
            return CustomView::query()->withoutGlobalScopes()->where('suuid', $suuid)->firstOrFail();
        };
        $kanbanColumns = $view(static::KANBAN)->custom_view_columns()->count();
        $monthlySummaries = $view(static::MONTHLY)->custom_view_summaries()->count();
        $monthlySorts = $view(static::MONTHLY)->custom_view_sorts()->count();
        $activityColumns = $view(static::ACTIVITIES)->custom_view_columns()->count();
        $this->assertGreaterThan(1, $kanbanColumns);
        $this->assertGreaterThan(0, $monthlySummaries);
        $this->assertGreaterThan(0, $monthlySorts);

        // stopped on the way
        $dashboard->dashboard_boxes()->where('row_no', 2)->where('column_no', 1)->delete();
        $view(static::KANBAN)->custom_view_columns()->delete();
        $view(static::MONTHLY)->custom_view_summaries()->delete();
        $view(static::MONTHLY)->custom_view_sorts()->delete();
        // changed by the site: one column taken out of a view
        $view(static::ACTIVITIES)->custom_view_columns()->orderByDesc('order')->firstOrFail()->delete();
        $this->assertSame(4, $dashboard->dashboard_boxes()->count());

        FeaturePacks::install(Features::SALES);
        System::clearCache();

        $this->assertSame(5, $dashboard->dashboard_boxes()->count());
        $box = $dashboard->dashboard_boxes()->where('row_no', 2)->where('column_no', 1)->firstOrFail();
        $this->assertSame(strval($view(static::ACTIVITIES)->id), strval($box->getOption('target_view_id')));
        $this->assertSame($kanbanColumns, $view(static::KANBAN)->custom_view_columns()->count());
        $this->assertSame($monthlySummaries, $view(static::MONTHLY)->custom_view_summaries()->count());
        $this->assertSame($monthlySorts, $view(static::MONTHLY)->custom_view_sorts()->count());
        $this->assertSame($activityColumns - 1, $view(static::ACTIVITIES)->custom_view_columns()->count(), 'the change of the site stays');

        // and again: nothing twice
        FeaturePacks::install(Features::SALES);
        System::clearCache();
        $this->assertSame(5, $dashboard->dashboard_boxes()->count());
        $this->assertSame($kanbanColumns, $view(static::KANBAN)->custom_view_columns()->count());
        $this->assertSame($monthlySummaries, $view(static::MONTHLY)->custom_view_summaries()->count());
    }

    /**
     * The row on with its pack not all there (an install stopped by a timeout, or the row turned on
     * without the install): saving Feature management installs what is missing.
     */
    public function testSavingInstallsWhenTheRowIsOnAndThePackIsNotAllThere(): void
    {
        // on, without the install
        Features::save([Features::SALES => ['enabled' => true, 'role_groups' => null, 'menu' => true]]);
        System::clearCache();
        Features::reset();
        $this->assertTrue(FeaturePacks::incomplete(Features::SALES));
        $html = (string) $this->get(admin_url('feature'))->assertOk()->getContent();
        $this->assertStringContainsString(e(exmtrans('feature.pack_incomplete')), $html);

        $this->postUse(true);
        $this->assertFalse(FeaturePacks::incomplete(Features::SALES));
        $this->assertSame(7, $this->counts()['tables']);
        $this->assertSame(1, $this->counts()['dashboards']);
        $html = (string) $this->get(admin_url('feature'))->assertOk()->getContent();
        $this->assertStringNotContainsString(e(exmtrans('feature.pack_incomplete')), $html);

        // a table missing (stopped on the way): made again by the next save
        CustomTable::getEloquent('sales_activity')->delete();
        System::clearCache();
        $this->assertTrue(FeaturePacks::incomplete(Features::SALES));
        $this->postUse(true);
        $this->assertNotNull(CustomTable::getEloquent('sales_activity'));
        $this->assertFalse(FeaturePacks::incomplete(Features::SALES));

        // all there: a save does not install again (a view the site removed stays removed)
        CustomView::query()->withoutGlobalScopes()->where('suuid', static::KANBAN)->get()->each(function ($view) {
            $view->deletingChildren();
            $view->delete();
        });
        $this->postUse(true);
        $this->assertFalse(CustomView::query()->withoutGlobalScopes()->where('suuid', static::KANBAN)->exists());
    }

    public function testTheKanbanAndTheDashboardRender(): void
    {
        FeaturePacks::turnOn(Features::SALES);
        $customer = CustomTable::getEloquent('sales_customer')->getValueModel();
        $customer->setValue(['customer_name' => 'ACME 商事', 'customer_type' => 'customer'])->save();
        foreach ([['提案の案件', 'proposal', 300000], ['見積の案件', 'quote', 200000], ['もう 1 つの提案', 'proposal', 100000]] as [$name, $stage, $amount]) {
            CustomTable::getEloquent('sales_deal')->getValueModel()->setValue([
                'deal_name' => $name, 'customer' => $customer->id, 'stage' => $stage,
                'expected_amount' => $amount, 'expected_date' => now()->format('Y-m-d'),
            ])->save();
        }
        $this->login();

        $kanban = (string) $this->get(admin_url('data/sales_deal') . '?view=' . static::KANBAN)->assertOk()->getContent();
        $this->assertStringContainsString('提案の案件', $kanban);
        $this->assertStringContainsString('"sum_column":"expected_amount"', $kanban);

        $dashboard = Dashboard::query()->where('dashboard_name', 'sales_dashboard')->firstOrFail();
        $home = (string) $this->get(admin_url('') . '?dashboard=' . $dashboard->suuid)->assertOk()->getContent();
        foreach ($dashboard->dashboard_boxes()->get() as $box) {
            $this->assertStringContainsString(e(strval($box->dashboard_box_view_name)), $home);
        }
    }
}
