<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Controllers\ApiDataController;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\ColumnOptions;
use Exceedone\Exment\Services\Dashboard\FilterValue;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hybrid storage P1 slice 14: the business-table read paths that name the index column
 * unconditionally (or without DDL), through their production callers.
 *
 * - ItemTrait::getSearchQueries() / setSearchOrWhere(): free-text search (auto-DDL, no gate).
 * - ApiDataController query-column filter and orderby (index_enabled gate, 400 otherwise),
 *   calendar (no gate; the name is also the row attribute read back), column data (gate; the
 *   name is also the pluck key).
 * - Dashboard ColumnOptions::liveValues() (no DDL, USE INDEX hint) and FilterValue::apply()
 *   on an indexed column (no DDL).
 *
 * Each pins SQL and bindings, the result, and how often index-column DDL runs.
 */
class ReadIndexNameQueryTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
    }

    /**
     * @return array{0: mixed, 1: array<int, array{query: string, bindings: array<mixed>}>}
     */
    protected function logged(\Closure $callback): array
    {
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        try {
            $result = $callback();
        } finally {
            $log = \DB::getQueryLog();
            \DB::disableQueryLog();
        }
        return [$result, $log];
    }

    /**
     * @param array<int, array{query: string, bindings: array<mixed>}> $log
     * @return array<int, array{query: string, bindings: array<mixed>}>
     */
    protected function matching(array $log, string $needle): array
    {
        return array_values(array_filter($log, function ($q) use ($needle) {
            return strpos($q['query'], $needle) !== false;
        }));
    }

    /**
     * A copy of $source (or a column of $table whose index column does not exist) that counts
     * alterColumn() calls instead of running DDL.
     */
    protected function counting(?CustomColumn $source, ?CustomTable $table = null): CustomColumn
    {
        $column = new class () extends CustomColumn {
            public int $alterCount = 0;

            // @phpstan-ignore-next-line
            public function alterColumn($forceDropIndex = false)
            {
                $this->alterCount++;
            }
        };
        if (isset($source)) {
            $column->setRawAttributes($source->getAttributes(), true);
            $column->exists = true;
            return $column;
        }
        $column->custom_table_id = $table->id;
        $column->column_name = 'p14_missing';
        $column->column_type = ColumnType::TEXT;
        $column->suuid = 'p14missing000000000';
        $column->options = ['index_enabled' => '1'];
        return $column;
    }

    protected function request(array $params): Request
    {
        $request = Request::create('/p14', 'GET', $params);
        app()->instance('request', $request);
        return $request;
    }

    protected function allColumns(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
    }

    protected function editAll(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
    }

    /**
     * @return array<int>
     */
    protected function ids($rows): array
    {
        return collect($rows)->map(function ($v) {
            return intval(is_object($v) ? $v->id : (is_array($v) ? $v['id'] : $v));
        })->sort()->values()->all();
    }

    // ------------------------------------------------- ItemTrait free-text search

    public function testSearchQueriesNameTheIndexColumn(): void
    {
        $table = $this->allColumns();
        $column = CustomColumn::getEloquent('text', $table);
        $this->assertTrue((bool)$column->index_enabled, 'precondition: text is indexed');
        $db = getDBTableName($table);
        $index = 'column_' . $column->suuid;
        $value = \DB::table($db)->whereNull('deleted_at')->whereNotNull($index)->orderBy('id')->value($index);
        $this->assertNotNull($value);

        [$queries] = CustomItem::getItem($column)->getSearchQueries('=', $value, 7, null);
        $this->assertSame("select `id` from `$db` where `$index` = ? and `$db`.`deleted_at` is null limit 7", $queries->toSql());
        $this->assertSame([$value], $queries->getBindings());
        $expected = \DB::table($db)->whereNull('deleted_at')->where($index, $value)->orderBy('id')->limit(7)->pluck('id');
        $this->assertSame($this->ids($expected), $this->ids($queries->get()));

        // a list: whereIn on the same column
        [$queries] = CustomItem::getItem($column)->getSearchQueries('=', [$value, 'p14 none'], 7, null);
        $this->assertSame("select `id` from `$db` where `$index` in (?, ?) and `$db`.`deleted_at` is null limit 7", $queries->toSql());
        $this->assertSame([$value, 'p14 none'], $queries->getBindings());
    }

    public function testSearchQueriesRunIndexDdlOnceWhenTheColumnIsMissing(): void
    {
        $table = $this->allColumns();
        $present = $this->counting(CustomColumn::getEloquent('text', $table));
        CustomItem::getItem($present)->getSearchQueries('like', '%x%', 5, null);
        $this->assertSame(0, $present->alterCount, 'index column present: no DDL');

        $missing = $this->counting(null, $table);
        [$queries] = CustomItem::getItem($missing)->getSearchQueries('like', '%x%', 5, null);
        $this->assertSame(1, $missing->alterCount);
        $this->assertStringContainsString('where `column_p14missing000000000` like ?', $queries->toSql());

        // no index_enabled gate: an unindexed column is still searched by its index-column name
        $textarea = CustomColumn::getEloquent('textarea', $table);
        $this->assertFalse((bool)$textarea->index_enabled);
        $unindexed = $this->counting($textarea);
        [$queries] = CustomItem::getItem($unindexed)->getSearchQueries('like', '%x%', 5, null);
        $this->assertStringContainsString('where `column_' . $textarea->suuid . '` like ?', $queries->toSql());
    }

    public function testSearchOrWhereNamesTheIndexColumnOncePerCall(): void
    {
        $table = $this->allColumns();
        $column = $this->counting(CustomColumn::getEloquent('text', $table));
        $index = 'column_' . $column->suuid;
        $db = getDBTableName($table);

        $query = $table->getValueModel()->query();
        $item = CustomItem::getItem($column);
        $item->setSearchOrWhere($query, 'like', '%a%', null);
        $item->setSearchOrWhere($query, '=', ['b', 'c'], null);
        $this->assertSame("select * from `$db` where (`$index` like ? or `$index` in (?, ?)) and `$db`.`deleted_at` is null", $query->toSql());
        $this->assertSame(['%a%', 'b', 'c'], $query->getBindings());
        $this->assertSame(0, $column->alterCount);

        // through the production caller: CustomValue::setSearchQueryOrWhere
        $value = \DB::table($db)->whereNull('deleted_at')->whereNotNull($index)->orderBy('id')->value($index);
        $query = $table->getValueModel()->query();
        $table->getValueModel()->setSearchQueryOrWhere($query, $value, ['searchColumns' => collect([CustomColumn::getEloquent('text', $table)]), 'isLike' => false]);
        $this->assertStringContainsString("(`$index` = ?)", $query->toSql());
        $expected = \DB::table($db)->whereNull('deleted_at')->where($index, $value)->pluck('id');
        $this->assertSame($this->ids($expected), $this->ids($query->get()));

        $missing = $this->counting(null, $table);
        $query = $table->getValueModel()->query();
        CustomItem::getItem($missing)->setSearchOrWhere($query, '=', 'x', null);
        $this->assertSame(1, $missing->alterCount);
    }

    // ------------------------------------------------------------ data API

    public function testQueryColumnFilterAndOrderByNameTheIndexColumn(): void
    {
        $table = $this->editAll();
        $column = CustomColumn::getEloquent('index_text', $table);
        $this->assertTrue((bool)$column->index_enabled);
        $db = getDBTableName($table);
        $index = 'column_' . $column->suuid;
        $request = $this->request(['q' => 'index_text ne index_002_001', 'orderby' => 'index_text desc', 'count' => 5]);
        $controller = new ApiDataController($table, $request);

        [$response, $log] = $this->logged(function () use ($controller, $request, $table) {
            return $controller->dataQueryColumn($request, $table->table_name);
        });
        $this->assertNotInstanceOf(Response::class, $response);
        $select = $this->matching($log, "`$index` <> ?");
        $this->assertNotEmpty($select, 'filter on the index column');
        $main = $this->matching($log, "order by `$index` desc, `id` asc limit 5 offset 0) s on");
        $this->assertCount(1, $main);
        // paginate(): the page's ids in a derived table (value inlined by the pager), then the rows
        $this->assertSame("select * from `$db` inner join (select `$db`.`id` as `sid` from `$db` where (`$index` <> 'index_002_001') and `$db`.`deleted_at` is null order by `$index` desc, `id` asc limit 5 offset 0) s on $db.id in (s.sid) where (`$index` <> ?) and `$db`.`deleted_at` is null order by `$index` desc, `id` asc", $main[0]['query']);
        $this->assertSame(['index_002_001'], $main[0]['bindings']);
        $expected = \DB::table($db)->whereNull('deleted_at')->where($index, '<>', 'index_002_001')->orderBy($index, 'desc')->orderBy('id')->limit(5)->pluck('id');
        $this->assertSame($expected->map(function ($v) {
            return intval($v);
        })->all(), collect($response->items())->pluck('id')->map(function ($v) {
            return intval($v);
        })->all());

        // gate: an unindexed column is refused before any index name is asked for
        $request = $this->request(['q' => 'text eq 1']);
        $refused = (new ApiDataController($table, $request))->dataQueryColumn($request, $table->table_name);
        $this->assertInstanceOf(Response::class, $refused);
        $this->assertSame(400, $refused->getStatusCode());
        $request = $this->request(['orderby' => 'text']);
        $refused = (new ApiDataController($table, $request))->dataList($request);
        $this->assertInstanceOf(Response::class, $refused);
        $this->assertSame(400, $refused->getStatusCode());
    }

    public function testCalendarNamesTheIndexColumnForQueryAndRowAttribute(): void
    {
        $table = $this->editAll();
        $date = CustomColumn::getEloquent('date', $table);
        $db = getDBTableName($table);
        $index = 'column_' . $date->suuid;
        $value = $table->getValueModel();
        $value->setValue(['text' => 'p14 calendar', 'index_text' => 'p14 calendar', 'date' => '2026-10-10']);
        $value->save();

        $calendar = CustomView::create([
            'custom_table_id' => $table->id,
            'view_view_name' => 'p14 calendar',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::CALENDAR,
        ]);
        $view_column = new CustomViewColumn();
        $view_column->custom_view_id = $calendar->id;
        $view_column->view_column_type = ConditionType::COLUMN;
        $view_column->view_column_table_id = $table->id;
        $view_column->view_column_target_id = $date->id;
        $view_column->setOption('end_date_type', ConditionType::COLUMN);
        $view_column->setOption('end_date_target', $date->id);
        $view_column->order = 1;
        $view_column->save();
        System::clearRequestSession();

        $request = $this->request(['view' => $calendar->suuid, 'start' => '2026-10-01', 'end' => '2026-11-01']);
        [$tasks, $log] = $this->logged(function () use ($table, $request) {
            return (new ApiDataController($table, $request))->calendarList($request);
        });
        $tasks = json_decode($tasks, true);
        $this->assertIsArray($tasks);
        $start = $this->matching($log, "`$db`.`$index` >= ? and `$db`.`$index` < ?");
        $this->assertNotEmpty($start, 'start date filter on the index column');
        $this->assertNotEmpty($this->matching($log, "`$db`.`$index` <= ? and `$db`.`$index` >= ?"), 'start-end filter on the index column');
        $task = collect($tasks)->first(function ($task) use ($value) {
            return $task['id'] == $value->id;
        });
        $this->assertNotNull($task);
        // the row attribute read back by the same name
        $this->assertSame('2026-10-10', substr((string)$task['start'], 0, 10));
        $this->assertSame('2026-10-10', substr((string)$task['end'], 0, 10));
    }

    public function testColumnDataUsesTheIndexColumnAsSearchColumnAndPluckKey(): void
    {
        $table = $this->editAll();
        $column = CustomColumn::getEloquent('index_text', $table);
        $db = getDBTableName($table);
        $index = 'column_' . $column->suuid;
        $request = $this->request(['query' => 'index_002']);

        [$json, $log] = $this->logged(function () use ($table, $request) {
            return (new ApiDataController($table, $request))->columnData($request, $table->table_name, 'index_text');
        });
        $search = $this->matching($log, "(select `id` from `$db` where `$index` LIKE ? and `$db`.`deleted_at` is null limit 5)");
        $this->assertCount(1, $search, json_encode(array_column($log, 'query')));
        $this->assertSame(['index_002%'], $search[0]['bindings'], 'forward match');
        // searchValue() keeps maxCount (5) rows in no fixed order: the values come from matching rows
        $matching = \DB::table($db)->whereNull('deleted_at')->where($index, 'like', 'index_002%')->pluck($index)->unique()->values()->all();
        $actual = json_decode($json, true);
        $this->assertNotEmpty($actual);
        $this->assertLessThanOrEqual(5, count($actual));
        $this->assertSame([], array_values(array_diff($actual, $matching)));
        $this->assertSame(count($actual), count(array_unique($actual)), 'pluck by the same name, unique');

        // gate: unindexed column ⇒ empty list, no query on it
        $this->assertSame('[]', (new ApiDataController($table, $request))->columnData($request, $table->table_name, 'text'));
    }

    // ------------------------------------------------------------ dashboard

    public function testLiveValuesProbeTheIndexColumnWithoutDdl(): void
    {
        $table = $this->editAll();
        $real = CustomColumn::getEloquent('index_text', $table);
        $column = $this->counting($real);
        $db = getDBTableName($table);
        $index = 'column_' . $real->suuid;
        $codes = \DB::table($db)->whereNull('deleted_at')->whereNotNull($index)->orderBy('id')->limit(3)->pluck($index)->map(function ($v) {
            return (string)$v;
        })->unique()->values()->all();
        $this->assertGreaterThanOrEqual(2, count($codes));
        // only deleted rows hold the first value now
        \DB::table($db)->where($index, $codes[0])->update(['deleted_at' => now()]);

        [$live, $log] = $this->logged(function () use ($table, $column, $codes) {
            return (new \ReflectionMethod(ColumnOptions::class, 'liveValues'))->invoke(null, $table, $column, array_merge($codes, ['p14 none']));
        });
        $this->assertSame(array_values(array_slice($codes, 1)), $live);
        $this->assertSame(0, $column->alterCount);
        $probe = $this->matching($log, 'USE INDEX');
        $this->assertCount(1, $probe);
        $this->assertStringContainsString("from `$db` USE INDEX (`index_$index`) where `$index` = ? and `deleted_at` is null limit 1", $probe[0]['query']);
    }

    public function testFilterValueInListOnAnIndexedColumnWithoutDdl(): void
    {
        $table = $this->editAll();
        $column = $this->counting(CustomColumn::getEloquent('index_text', $table));
        $db = getDBTableName($table);
        $index = 'column_' . $column->suuid;

        $query = $table->getValueModel()->query();
        FilterValue::apply($query, $column, ['in' => ['a']]);
        FilterValue::apply($query, $column, ['in' => ['b', 'c']]);
        $this->assertSame("select * from `$db` where `$db`.`$index` = ? and `$db`.`$index` in (?, ?) and `$db`.`deleted_at` is null", $query->toSql());
        $this->assertSame(['a', 'b', 'c'], $query->getBindings());
        $this->assertSame(0, $column->alterCount);
    }
}
