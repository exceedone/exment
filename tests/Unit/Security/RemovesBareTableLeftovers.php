<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * For a test that inserts a custom table bare (no DDL) and then sends a request: the request
 * creates the table's exm__ table on its first use (getModelName() -> createTable()), and that
 * DDL commits the test's transaction (MySQL implicit commit). The rows inserted before it — the
 * table, its columns and views, the dashboards — would outlive the rollback and pile up in the
 * dashboard menu of the dev DB. They are removed after the rollback: the rows of this test only
 * (newer than the test's start, named as these tests name them), and the exm__ tables.
 *
 * Laravel runs tearDownRemovesBareTableLeftovers() after DatabaseTransactions' rollback.
 */
trait RemovesBareTableLeftovers
{
    /** @var array{dashboards: int, custom_tables: int} the highest ids before the test */
    private array $leftoverIdsBefore = ['dashboards' => 0, 'custom_tables' => 0];

    protected function setUpRemovesBareTableLeftovers(): void
    {
        foreach (array_keys($this->leftoverIdsBefore) as $table) {
            $this->leftoverIdsBefore[$table] = (int) DB::table($table)->max('id');
        }
    }

    protected function tearDownRemovesBareTableLeftovers(): void
    {
        $dashboardIds = DB::table('dashboards')
            ->where('id', '>', $this->leftoverIdsBefore['dashboards'])
            ->where(function ($query) {
                $query->where('dashboard_name', 'like', 'ngfix\_%')
                    ->orWhere('dashboard_name', 'like', 'perm\_%')
                    ->orWhere('dashboard_name', 'like', 'shared\_%');
            })->pluck('id')->all();
        $tables = DB::table('custom_tables')
            ->where('id', '>', $this->leftoverIdsBefore['custom_tables'])
            ->where(function ($query) {
                $query->where('table_name', 'like', 'ngfix\_%')
                    ->orWhere('table_name', 'like', 'perm\_%');
            })->get(['id', 'suuid']);
        if (count($dashboardIds) === 0 && $tables->isEmpty()) {
            return;
        }

        $tableIds = $tables->pluck('id')->all();
        $viewIds = DB::table('custom_views')->whereIn('custom_table_id', $tableIds)->pluck('id')->all();
        foreach (['custom_view_columns', 'custom_view_summaries', 'custom_view_filters', 'custom_view_sorts'] as $table) {
            DB::table($table)->whereIn('custom_view_id', $viewIds)->delete();
        }
        DB::table('custom_views')->whereIn('id', $viewIds)->delete();
        DB::table('custom_columns')->whereIn('custom_table_id', $tableIds)->delete();
        DB::table('custom_tables')->whereIn('id', $tableIds)->delete();
        DB::table('dashboard_boxes')->whereIn('dashboard_id', $dashboardIds)->delete();
        DB::table('data_share_authoritables')->where('parent_type', 'dashboard')->whereIn('parent_id', $dashboardIds)->delete();
        DB::table('dashboards')->whereIn('id', $dashboardIds)->delete();
        foreach ($tables as $table) {
            Schema::dropIfExists('exm__' . $table->suuid);
        }
    }
}
