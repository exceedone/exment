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
        Menu::query()->where('menu_name', 'sales')->delete();
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
            'menus' => Menu::query()->whereIn('menu_name', array_merge(['sales'], static::TABLES))->count(),
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
        $this->assertStringNotContainsString('checked', $input[0]);
    }

    public function testTheWordsOfTheRowAreInEveryLanguageWithoutPack(): void
    {
        $current = \App::getLocale();
        try {
            foreach (['ja', 'en', 'de', 'es', 'ko', 'vi', 'zh_CN'] as $locale) {
                \App::setLocale($locale);
                foreach (['features.sales', 'features.sales_help', 'pack_note', 'who_pack', 'pack_installed', 'pack_failed', 'pack_install_header', 'pack_install_question', 'pack_install_help', 'menu_pack', 'menu_pack_link'] as $key) {
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
        $this->assertSame(5, Menu::query()->where('parent_id', $parent->id)->count());
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
