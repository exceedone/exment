<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\DashboardFilter;
use Exceedone\Exment\Services\Dashboard\FilterBarConfig;
use Illuminate\Support\Facades\DB;

/**
 * FIXED: a filter bar item whose column was deleted from the source table after the bar was
 * configured kept filtering. The bar draws no control for it (FilterBarView skips it), but a
 * selection of it — remembered, or in the URL the default redirect builds — still narrowed
 * every box whose table has a column of that name: numbers filtered with no filter shown.
 * Such an item is now left out of the bar as the viewer gets it (FilterBarConfig::forViewer).
 *
 * The table is inserted bare (no DDL) and nothing here sends a request that would create it;
 * everything is rolled back.
 */
class DashboardFilterBarDeletedColumnFixedTest extends SecurityRegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $login = LoginUser::find(1);
        if ($login === null) {
            $this->markTestSkipped('needs LoginUser 1');
        }
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->actingAs($login, 'admin');
    }

    public function test_an_item_whose_column_was_deleted_filters_nothing(): void
    {
        $table = $this->tableWithColumns(['grade']);
        // "subject" was an item of the bar; its column has been deleted since
        $dashboard = $this->dashboardWithBar($table, ['grade', 'subject']);

        $config = FilterBarConfig::forViewer($dashboard);
        $this->assertNotNull($config);
        $this->assertSame(['grade'], array_column($config->dims(), 'column'));

        request()->replace(['df_grade' => '1', 'df_subject' => 'math']);
        $filter = DashboardFilter::fromRequest($dashboard);
        $this->assertSame(['in' => ['1']], $filter->spec('grade'), 'an item that still has its column filters as before');
        $this->assertNull($filter->spec('subject'), 'the deleted column narrows no box');

        // the stored configuration is not touched: the dashboard form still shows the item
        $stored = FilterBarConfig::fromDashboard($dashboard);
        $this->assertNotNull($stored);
        $this->assertSame(['grade', 'subject'], array_column($stored->dims(), 'column'));
    }

    public function test_a_bar_whose_columns_were_all_deleted_does_not_exist(): void
    {
        $table = $this->tableWithColumns(['grade']);
        $dashboard = $this->dashboardWithBar($table, ['subject']);

        $this->assertNull(FilterBarConfig::forViewer($dashboard));
        request()->replace(['df_subject' => 'math']);
        $this->assertTrue(DashboardFilter::fromRequest($dashboard)->isEmpty());
    }

    public function test_a_bar_whose_columns_all_exist_is_unchanged(): void
    {
        $table = $this->tableWithColumns(['grade', 'subject']);
        $dashboard = $this->dashboardWithBar($table, ['grade', 'subject']);

        $config = FilterBarConfig::forViewer($dashboard);
        $this->assertNotNull($config);
        $this->assertSame(['grade', 'subject'], array_column($config->dims(), 'column'));
        $this->assertSame(['grade', 'subject'], array_column(FilterBarConfig::fromDashboard($dashboard)->dims(), 'column'));
    }

    /**
     * @param list<string> $columns
     */
    private function tableWithColumns(array $columns): CustomTable
    {
        $tableId = DB::table('custom_tables')->insertGetId([
            'suuid' => short_uuid(), 'table_name' => 'perm_' . uniqid(), 'table_view_name' => 'filter bar source', 'options' => '{}',
        ]);
        foreach ($columns as $column) {
            DB::table('custom_columns')->insert([
                'suuid' => short_uuid(), 'custom_table_id' => $tableId, 'column_name' => $column, 'column_view_name' => $column, 'column_type' => 'text', 'options' => '{}',
            ]);
        }
        System::clearRequestSession();
        return CustomTable::getEloquent($tableId);
    }

    /**
     * @param list<string> $columns
     */
    private function dashboardWithBar(CustomTable $table, array $columns): Dashboard
    {
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::SYSTEM;
        $dashboard->dashboard_name = 'perm_' . uniqid();
        $dashboard->dashboard_view_name = 'filter bar with a deleted column';
        $dashboard->options = [
            'row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0,
            'filter_bar' => ['source_table' => $table->table_name, 'dims' => array_map(function ($column) {
                return ['column' => $column, 'label' => $column];
            }, $columns)],
        ];
        $dashboard->save();
        return $dashboard;
    }
}
