<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\DashboardFilter;
use Exceedone\Exment\Services\Dashboard\FilterBarConfig;
use Illuminate\Support\Facades\DB;

/**
 * FIXED: the dashboard filter bar was built for every viewer of the dashboard, whatever their
 * permission on its source table. A user who may not view that table (a system dashboard
 * shared with everyone, its bar on a table only some roles read) saw the bar's items and the
 * whole catalogue of a select column (its select_item definition, listed without a query),
 * was redirected to the configured defaults and had boxes of other tables narrowed by a bar
 * they could not see. Such a bar now does not exist for that user (FilterBarConfig::forViewer):
 * no bar, no default redirect, nothing narrowed; a user who may view the table is unaffected.
 *
 * The data (a bare table no role grants, a system dashboard) is rolled back; the requests
 * use the first login user who may not view that table.
 */
class DashboardFilterBarViewerFixedTest extends SecurityRegressionTestCase
{
    public function test_a_user_who_may_not_view_the_source_table_gets_no_filter_bar(): void
    {
        $this->asUser(LoginUser::find(1));
        $table = $this->hiddenTable();
        $dashboard = $this->dashboardWithBar($table);
        $this->asViewer($table);

        // no redirect to the configured default (df_secret_item=東京), no bar, no catalogue
        $response = $this->get($this->home('?dashboard=' . $dashboard->suuid));
        $response->assertStatus(200);
        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('exment-df-bar', $content);
        $this->assertStringNotContainsString('秘密の選択肢', $content);

        // the bar partial of a selective change answers nothing either
        $this->get($this->home('?_df_bar=1&dashboard=' . $dashboard->suuid . '&df_secret_item=' . urlencode('東京')))->assertContent('');

        // and no box is narrowed by its selection
        request()->query->replace(['df_secret_item' => '東京']);
        request()->replace(['df_secret_item' => '東京']);
        $this->assertNull(FilterBarConfig::forViewer($dashboard));
        $this->assertTrue(DashboardFilter::fromRequest($dashboard)->isEmpty());
    }

    public function test_a_user_who_may_view_the_source_table_keeps_the_filter_bar(): void
    {
        $this->asUser(LoginUser::find(1));
        $table = $this->hiddenTable();
        $dashboard = $this->dashboardWithBar($table);

        $config = FilterBarConfig::forViewer($dashboard);
        $this->assertNotNull($config);
        $this->assertSame($table->table_name, $config->sourceTable());

        request()->replace(['df_secret_item' => '東京']);
        $this->assertSame(['in' => ['東京']], DashboardFilter::fromRequest($dashboard)->spec('secret_item'));
    }

    /**
     * A table no role grants, with one select column (inserted bare: saving a CustomTable
     * would create its table, whose DDL ends the rolled back transaction).
     */
    private function hiddenTable(): CustomTable
    {
        $tableId = DB::table('custom_tables')->insertGetId([
            'suuid' => short_uuid(), 'table_name' => 'perm_' . uniqid(), 'table_view_name' => 'hidden table', 'options' => '{}',
        ]);
        DB::table('custom_columns')->insert([
            'suuid' => short_uuid(), 'custom_table_id' => $tableId, 'column_name' => 'secret_item', 'column_view_name' => 'secret item', 'column_type' => 'select',
            'options' => json_encode(['select_item' => "東京\n秘密の選択肢"], JSON_UNESCAPED_UNICODE),
        ]);
        System::clearRequestSession();
        return CustomTable::getEloquent($tableId);
    }

    private function dashboardWithBar(CustomTable $table): Dashboard
    {
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::SYSTEM;
        $dashboard->dashboard_name = 'perm_' . uniqid();
        $dashboard->dashboard_view_name = 'filter bar on a hidden table';
        $dashboard->options = [
            'row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0,
            'filter_bar' => ['source_table' => $table->table_name, 'dims' => [['column' => 'secret_item', 'label' => 'secret item', 'default' => '東京']]],
        ];
        $dashboard->save();
        return $dashboard;
    }

    /**
     * Logs in the first login user who may not view $table. Lacking the system permission is
     * not enough: a role on all data (data_admin_group of the test data) views every table.
     */
    private function asViewer(CustomTable $table): void
    {
        foreach (LoginUser::all() as $login) {
            if ($login->base_user === null) {
                continue;
            }
            $this->asUser($login);
            if (!$table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                return;
            }
        }
        $this->markTestSkipped('needs a login user who may not view the table');
    }

    private function asUser(?LoginUser $login): void
    {
        if ($login === null) {
            $this->markTestSkipped('needs LoginUser 1 in the dev DB');
        }
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->actingAs($login, 'admin');
    }

    private function home(string $query): string
    {
        return '/' . config('admin.route.prefix', 'admin') . $query;
    }
}
