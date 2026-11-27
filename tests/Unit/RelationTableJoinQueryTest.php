<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Search\SearchService;
use Exceedone\Exment\Services\Storage\PhysicalQueryRouting;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Support\DriverSql;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Database\QueryException;

/**
 * Hybrid storage P1 slice 5: query key of the select-table column inside read-only joins.
 *
 * Pins, through the production callers RelationTable::setParentJoin() / setParentJoinSelectTable(),
 * setSummaryChildJoin() / setSummaryChildJoinSelectTable(), setQuerySelectTable() and
 * SearchService::orderBy() on a column of a joined table: SQL, bindings, aliases, join type,
 * result sets (admin and a restricted user) and when the index-column DDL (alterColumn) runs.
 * Expected strings are written out literally (MySQL / MariaDB grammar).
 */
class RelationTableJoinQueryTest extends UnitTestBase
{
    use DatabaseTransactions;
    use DriverSql;

    /**
     * Expected SQL written in MySQL / MariaDB grammar, rendered for the driver under test: identifier quoting and
     * the JSON accessor only. Real differences of the generated SQL (the id/text compare of a join, the
     * multi-value split) are written out per driver by the test itself, through tc() / multiOn().
     */
    protected static function sql(string $mysql): string
    {
        if (!static::sqlServer()) {
            return $mysql;
        }
        $mysql = preg_replace('/json_unquote\(json_extract\((`[^`]+`\.`[^`]+`|`[^`]+`), (\'[^\']+\')\)\)/', 'json_value($1, $2)', $mysql);
        return preg_replace_callback('/`([^`]+)`/', fn ($m) => '[' . $m[1] . ']', $mysql);
    }

    /** SQL Server reads an id / index text column through TRY_CAST in a join (an invalid reference must not raise). */
    protected static function tc(string $mysqlExpr): string
    {
        return static::sqlServer() ? "TRY_CAST($mysqlExpr AS bigint)" : $mysqlExpr;
    }

    /** ON clause of a multi-value join: MySQL FIND_IN_SET, SQL Server element-wise text compare over STRING_SPLIT. */
    protected static function multiOn(string $baseColumn, string $jsonExpr): string
    {
        if (static::sqlServer()) {
            if (preg_match("/^json_unquote\(json_extract\((.+), ('[^']*')\)\)$/", $jsonExpr, $m)) {
                $jsonExpr = "COALESCE(JSON_QUERY({$m[1]}, {$m[2]}), JSON_VALUE({$m[1]}, {$m[2]}))";
            }
            $clean = "REPLACE(REPLACE(REPLACE(REPLACE({$jsonExpr}, '[', ''), ' ', ''), ']', ''), '\"', '')";
            return "exists (select 1 from STRING_SPLIT({$clean}, ',') AS `CROSS_APPLY_TABLE` where `CROSS_APPLY_TABLE`.`value` = CAST({$baseColumn} AS nvarchar(40)))";
        }
        return "FIND_IN_SET({$baseColumn}, REPLACE(REPLACE(REPLACE(REPLACE({$jsonExpr}, '[', ''), ' ', ''), ']', ''), '\\\"', ''))";
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function parent(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_SELECT);
    }

    protected function child(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE_SELECT);
    }

    protected function pivot(): CustomColumn
    {
        return CustomColumn::getEloquent('parent_select_table', $this->child());
    }

    /**
     * Select-table column of the child table pointing to the parent table; alterColumn is a counter.
     */
    protected function countingPivot(string $name, bool $indexed, bool $multiple = false): CustomColumn
    {
        $column = new class () extends CustomColumn {
            public int $alterCount = 0;

            // @phpstan-ignore-next-line
            public function alterColumn($forceDropIndex = false)
            {
                $this->alterCount++;
            }
        };
        $column->custom_table_id = $this->child()->id;
        $column->column_name = $name;
        $column->column_type = ColumnType::SELECT_TABLE;
        $column->suuid = 'p5test' . substr(md5($name . ($indexed ? 'i' : 'n') . ($multiple ? 'm' : 's')), 0, 14);
        $column->options = array_filter([
            'index_enabled' => $indexed ? '1' : null,
            'multiple_enabled' => $multiple ? '1' : null,
            'select_target_table' => $this->parent()->id,
        ]);
        return $column;
    }

    /** RelationTable as SearchService::getRelationTable() finds it. */
    protected function relationTable(CustomTable $whereTable, CustomTable $searchTable, int $searchType, bool $asSummary): RelationTable
    {
        return RelationTable::getRelationTables($whereTable, false, [
            'search_enabled_only' => false,
            'get_parent_relation_tables' => $asSummary,
        ])->first(function ($r) use ($searchTable, $searchType) {
            return isMatchString($r->table->id, $searchTable->id) && (int)$r->searchType === $searchType
                && (!$r->selectTablePivotColumn || $r->selectTablePivotColumn->column_name === 'parent_select_table');
        });
    }

    protected function idx(CustomColumn $column): string
    {
        return 'column_' . $column->suuid;
    }

    /** @return array<int> */
    protected function sortedIds($query): array
    {
        return collect($query->pluck(getDBTableName($this->child()) . '.id'))->map(function ($id) {
            return (int)$id;
        })->sort()->values()->all();
    }

    /** @return array<int> */
    protected function visibleChildIds(): array
    {
        return collect($this->child()->getValueQuery()->pluck('id'))->map(function ($id) {
            return (int)$id;
        })->sort()->values()->all();
    }

    // ---------------------------------------------------------------- setParentJoinSelectTable

    public function testParentJoinOfExistingIndexPivot(): void
    {
        $p = getDBTableName($this->parent());
        $c = getDBTableName($this->child());
        $rt = $this->relationTable($this->parent(), $this->child(), SearchType::SELECT_TABLE, false);
        $a = $rt->tableUniqueName;

        $query = $this->child()->getValueQuery()->select("$c.*");
        $rt->setParentJoin($query, ['child_table' => $this->child(), 'parent_table' => $this->parent(), 'custom_column' => $this->pivot(), 'leftJoin' => true]);

        $this->assertSame(static::sql("select `{$c}`.* from `{$c}` left join `{$p}` as `{$a}` on `{$a}`.`id` = " . static::tc("`{$c}`.`{$this->idx($this->pivot())}`") . " where `{$c}`.`deleted_at` is null"), $query->toSql());
        $this->assertSame([], $query->getBindings());
        $this->assertSame($this->visibleChildIds(), $this->sortedIds($query));
    }

    public function testParentJoinOfJsonPivotAndInnerJoin(): void
    {
        $p = getDBTableName($this->parent());
        $c = getDBTableName($this->child());
        $rt = $this->relationTable($this->parent(), $this->child(), SearchType::SELECT_TABLE, false);
        $a = $rt->tableUniqueName;
        $pivot = $this->countingPivot('parent_select_table', false);

        $query = $this->child()->getValueQuery()->select("$c.*");
        $rt->setParentJoinSelectTable($query, $this->parent(), $pivot, false);

        $this->assertSame(static::sql("select `{$c}`.* from `{$c}` inner join `{$p}` as `{$a}` on `{$a}`.`id` = " . static::tc("json_unquote(json_extract(`{$c}`.`value`, '$.\"parent_select_table\"'))") . " where `{$c}`.`deleted_at` is null"), $query->toSql());
        $this->assertSame(0, $pivot->alterCount);
        $this->assertSame($this->visibleChildIds(), $this->sortedIds($query), 'every child row refers to a parent row');
    }

    public function testParentJoinOfMultipleJsonPivot(): void
    {
        $c = getDBTableName($this->child());
        $rt = $this->relationTable($this->parent(), $this->child(), SearchType::SELECT_TABLE, false);
        $a = $rt->tableUniqueName;
        $pivot = $this->countingPivot('p5_multi', false, true);

        $query = $this->child()->getValueQuery()->select("$c.*");
        $rt->setParentJoinSelectTable($query, $this->parent(), $pivot, true);

        $p = getDBTableName($this->parent());
        $j = "json_unquote(json_extract(`{$c}`.`value`, '$.\"p5_multi\"'))";
        $this->assertSame(
            static::sql("select `{$c}`.* from `{$c}` left join `{$p}` as `{$a}` on " . static::multiOn("`{$a}`.`id`", $j) . " where `{$c}`.`deleted_at` is null"),
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
        $this->assertSame(0, $pivot->alterCount);
    }

    public function testParentJoinOfMissingIndexPivotKeepsAutoDdl(): void
    {
        $c = getDBTableName($this->child());
        $rt = $this->relationTable($this->parent(), $this->child(), SearchType::SELECT_TABLE, false);
        $a = $rt->tableUniqueName;
        $pivot = $this->countingPivot('p5_missing', true);
        $this->assertFalse(hasColumn($c, $this->idx($pivot)), 'precondition: index column missing');

        $query = $this->child()->getValueQuery()->select("$c.*");
        $rt->setParentJoinSelectTable($query, $this->parent(), $pivot, true);

        $this->assertSame(1, $pivot->alterCount, 'the join keeps the auto-DDL of getQueryKey()');
        $this->assertStringContainsString(static::sql("on `{$a}`.`id` = " . static::tc("`{$c}`.`{$this->idx($pivot)}`")), $query->toSql());
    }

    // ---------------------------------------------------------------- setSummaryChildJoinSelectTable

    public function testSummaryChildJoinOfExistingIndexPivot(): void
    {
        $p = getDBTableName($this->parent());
        $c = getDBTableName($this->child());
        $rt = $this->relationTable($this->child(), $this->parent(), SearchType::SUMMARY_SELECT_TABLE, true);
        $a = $rt->tableUniqueName;
        $k = $this->idx($this->pivot());

        $query = $this->parent()->getValueQuery()->select("$p.id");
        $rt->setSummaryChildJoin($query, ['parent_table' => $this->parent(), 'child_table' => $this->child(), 'custom_column' => $this->pivot(), 'leftJoin' => true]);

        $this->assertSame(
            static::sql("select `{$p}`.`id` from `{$p}` left join (select `{$a}`.`{$k}` from `{$c}` as `{$a}` where `{$a}`.`deleted_at` is null group by `{$a}`.`{$k}`) as `{$a}` on `{$p}`.`id` = " . static::tc("`{$a}`.`{$k}`") . " where `{$p}`.`deleted_at` is null"),
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
        $this->assertSame($this->parent()->getValueQuery()->count(), $query->get()->count(), 'grouped sub query keeps one row per parent');
    }

    public function testSummaryChildJoinOfJsonPivot(): void
    {
        $p = getDBTableName($this->parent());
        $c = getDBTableName($this->child());
        $rt = $this->relationTable($this->child(), $this->parent(), SearchType::SUMMARY_SELECT_TABLE, true);
        $a = $rt->tableUniqueName;
        $pivot = $this->countingPivot('parent_select_table', false);
        $j = "json_unquote(json_extract(`{$a}`.`value`, '$.\"parent_select_table\"'))";
        $k = $this->idx($pivot);

        $query = $this->parent()->getValueQuery()->select("$p.id");
        $rt->setSummaryChildJoinSelectTable($query, $this->parent(), $pivot, true);

        // Bug-fix slice V5-2: the JSON key is selected under the index column's name (no DDL) and the
        // outer ON reads that name. Before: `... (select {$j} from ...) as a on p.id = {$j}` -
        // "Unknown column 'a.value' in 'on clause'" when run (baseline 5d4ddd4bb, V5JoinIssuesTest).
        $this->assertSame(
            static::sql("select `{$p}`.`id` from `{$p}` left join (select {$j} as `{$k}` from `{$c}` as `{$a}` where `{$a}`.`deleted_at` is null group by {$j}) as `{$a}` on `{$p}`.`id` = " . static::tc("`{$a}`.`{$k}`") . " where `{$p}`.`deleted_at` is null"),
            $query->toSql()
        );
        $this->assertSame(0, $pivot->alterCount);
        $this->assertSame($this->parent()->getValueQuery()->count(), $query->get()->count(), 'runs; grouped sub query keeps one row per parent');
    }

    public function testSummaryChildJoinOfMissingIndexPivotKeepsAutoDdl(): void
    {
        $rt = $this->relationTable($this->child(), $this->parent(), SearchType::SUMMARY_SELECT_TABLE, true);
        $pivot = $this->countingPivot('p5_missing_sum', true);

        $query = $this->parent()->getValueQuery();
        $rt->setSummaryChildJoinSelectTable($query, $this->parent(), $pivot, true);

        $this->assertSame(1, $pivot->alterCount);
        $this->assertStringContainsString(static::sql("`{$rt->tableUniqueName}`.`{$this->idx($pivot)}`"), $query->toSql());
    }

    // ---------------------------------------------------------------- setQuerySelectTable

    public function testSetQuerySelectTable(): void
    {
        $c = getDBTableName($this->child());
        $k = $this->idx($this->pivot());
        $parentId = (int)$this->parent()->getValueQuery()->orderBy('id')->value('id');

        $query = $this->child()->getValueQuery();
        RelationTable::setQuery($query, SearchType::SELECT_TABLE, $parentId, ['parent_table' => $this->parent(), 'child_table' => $this->child(), 'custom_column' => $this->pivot()]);
        $this->assertSame(static::sql("select * from `{$c}` where `{$k}` = ? and `{$c}`.`deleted_at` is null"), $query->toSql());
        // SQL Server compares a text key as text (a non-numeric stored value cannot raise a conversion error)
        $this->assertSame([static::sqlServer() && PhysicalQueryRouting::keyFor($this->pivot(), CustomColumn::QUERY_PURPOSE_GENERAL) === null ? (string)$parentId : $parentId], $query->getBindings());
        $expected = $this->child()->getValueQuery()->where($k, $parentId)->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, $query->pluck('id')->sort()->values()->all());

        $query = $this->child()->getValueQuery();
        RelationTable::setQuerySelectTable($query, $this->pivot(), [$parentId, $parentId + 1]);
        $this->assertSame(static::sql("select * from `{$c}` where `{$k}` in (?, ?) and `{$c}`.`deleted_at` is null"), $query->toSql());

        $json = $this->countingPivot('parent_select_table', false);
        $query = $this->child()->getValueQuery();
        RelationTable::setQuerySelectTable($query, $json, $parentId);
        $this->assertSame(static::sql("select * from `{$c}` where json_unquote(json_extract(`value`, '$.\"parent_select_table\"')) = ? and `{$c}`.`deleted_at` is null"), $query->toSql());
        $this->assertSame(0, $json->alterCount);

        $missing = $this->countingPivot('p5_missing_q', true);
        RelationTable::setQuerySelectTable($this->child()->getValueQuery(), $missing, $parentId);
        $this->assertSame(1, $missing->alterCount);
    }

    // ---------------------------------------------------------------- SearchService::orderBy after setJoin

    public function testOrderByIndexColumnOfJoinedParent(): void
    {
        $p = getDBTableName($this->parent());
        $c = getDBTableName($this->child());
        $rt = $this->relationTable($this->parent(), $this->child(), SearchType::SELECT_TABLE, false);
        $a = $rt->tableUniqueName;
        $order = CustomColumn::getEloquent('index_text', $this->parent());

        $query = (new SearchService($this->child()))->orderBy($order, 'desc')->query();

        $this->assertSame(static::sql("select `{$c}`.* from `{$c}` left join `{$p}` as `{$a}` on `{$a}`.`id` = " . static::tc("`{$c}`.`{$this->idx($this->pivot())}`") . " where `{$c}`.`deleted_at` is null order by `{$this->idx($order)}` desc"), $query->toSql());
        $this->assertSame([], $query->getBindings());
        $this->assertSame($this->visibleChildIds(), $this->sortedIds($query));
    }

    /** The JSON order key is qualified with the joined alias, so `value` is no longer ambiguous. */
    public function testOrderByJsonColumnOfJoinedParent(): void
    {
        $p = getDBTableName($this->parent());
        $c = getDBTableName($this->child());
        $a = $this->relationTable($this->parent(), $this->child(), SearchType::SELECT_TABLE, false)->tableUniqueName;
        $order = CustomColumn::getEloquent('text', $this->parent());

        $query = (new SearchService($this->child()))->orderBy($order)->query();

        $this->assertSame(static::sql("select `{$c}`.* from `{$c}` left join `{$p}` as `{$a}` on `{$a}`.`id` = " . static::tc("`{$c}`.`{$this->idx($this->pivot())}`") . " where `{$c}`.`deleted_at` is null order by json_unquote(json_extract(`{$a}`.`value`, '$.\"text\"')) asc"), $query->toSql());
        $this->assertSame($this->visibleChildIds(), $this->sortedIds($query));
    }

    public function testOrderByJoinedParentForRestrictedUser(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearRequestSession();
        $all = $this->child()->getValueModel()->query()->withoutGlobalScopes()->count();
        $visible = $this->visibleChildIds();
        $this->assertLessThan($all, count($visible), 'precondition: user2 sees only part of the child table');
        $this->assertNotEmpty($visible);

        $order = CustomColumn::getEloquent('index_text', $this->parent());
        $query = (new SearchService($this->child()))->orderBy($order, 'asc')->query();

        $this->assertSame($visible, $this->sortedIds($query), 'the joined order by neither hides nor exposes rows');
        $this->assertStringContainsString(static::sql("order by `{$this->idx($order)}` asc"), $query->toSql());
    }
}
