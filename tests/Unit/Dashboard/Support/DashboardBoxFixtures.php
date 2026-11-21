<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Support;

use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\DB;

/**
 * Boxes and views of the test data (all_columns_table_fortest), for a test that rolls back
 * (DatabaseTransactions).
 */
trait DashboardBoxFixtures
{
    /** @var string */
    protected $tableName = 'all_columns_table_fortest';

    protected function asAdmin(): void
    {
        $login = LoginUser::find(1);
        if ($login === null) {
            $this->markTestSkipped('needs LoginUser 1 in the dev DB');
        }
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->actingAs($login, 'admin');
    }

    protected function table(): CustomTable
    {
        $table = CustomTable::getEloquent($this->tableName);
        if ($table === null || $table->getValueModel()::query()->count() < 2) {
            $this->markTestSkipped('needs the test data (' . $this->tableName . ')');
        }
        return $table;
    }

    protected function column(string $name): CustomColumn
    {
        $column = CustomColumn::getEloquent($name, $this->tableName);
        $this->assertNotNull($column, $name);
        return $column;
    }

    /**
     * The box's body and footer, and the queries it ran, from an empty request cache.
     *
     * @param mixed $item
     * @return array{0: string, 1: string[]}
     */
    protected function render($item): array
    {
        System::clearRequestSession();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        try {
            $html = (string) $item->body() . (string) $item->footer();
        } finally {
            app('events')->forget(\Illuminate\Database\Events\QueryExecuted::class);
        }
        // the ids the page draws anew each time (a chart's canvas)
        return [preg_replace('/[0-9a-f]{20,}/', '', $html), $queries];
    }

    /**
     * The queries reading records of the table by id (one per row and column without the prefetch).
     *
     * @param string[] $queries
     */
    protected function countById(array $queries, CustomTable $table): int
    {
        $name = preg_quote(getDBTableName($table), '/');
        return count((array) preg_grep('/from [`"\[]?' . $name . '[`"\]]? where [`"\[]?(' . $name . '[`"\]]?\.)?[`"\[]?id[`"\]]? (in|=)/', $queries));
    }

    /**
     * @param array<array{0: int, 1: int, 2: array<string, mixed>}> $columns  table id, column id, options
     */
    protected function makeView(string $kind, array $columns): int
    {
        $viewId = DB::table('custom_views')->insertGetId([
            'suuid' => short_uuid(), 'custom_table_id' => $this->table()->id, 'view_view_name' => 'prefetch view', 'view_type' => ViewType::SYSTEM, 'view_kind_type' => $kind, 'options' => '{}',
        ]);
        foreach ($columns as $i => [$tableId, $columnId, $options]) {
            DB::table('custom_view_columns')->insert([
                'suuid' => short_uuid(), 'custom_view_id' => $viewId, 'view_column_type' => 0, 'view_column_table_id' => $tableId, 'view_column_target_id' => $columnId, 'order' => $i + 1,
                'options' => json_encode((object) $options),
            ]);
        }
        System::clearRequestSession();
        return $viewId;
    }

    /**
     * An aggregate view grouped by one column, summing another.
     *
     * @return array{0: int, 1: int, 2: int}  the view, its group column, its summary
     */
    protected function aggregateView(int $tableId, int $groupColumnId, int $sumColumnId): array
    {
        $viewId = $this->makeView(ViewKindType::AGGREGATE, [[$tableId, $groupColumnId, []]]);
        $columnId = (int) DB::table('custom_view_columns')->where('custom_view_id', $viewId)->value('id');
        $summaryId = DB::table('custom_view_summaries')->insertGetId([
            'suuid' => short_uuid(), 'custom_view_id' => $viewId, 'view_column_type' => 0, 'view_column_table_id' => $tableId, 'view_column_target_id' => $sumColumnId,
            'view_summary_condition' => SummaryCondition::SUM, 'options' => '{}',
        ]);
        System::clearRequestSession();
        return [$viewId, $columnId, $summaryId];
    }

    /**
     * A box, not saved: an item reads its options only.
     *
     * @param array<string, mixed> $options
     */
    protected function box(string $type, array $options): DashboardBox
    {
        $box = new DashboardBox();
        $box->dashboard_box_type = $type;
        $box->suuid = short_uuid();
        $box->options = $options;
        return $box;
    }
}
