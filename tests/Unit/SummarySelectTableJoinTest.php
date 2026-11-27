<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Bug-fix slice (V5 issue 2): the summary join of a child select_table pivot read from JSON
 * (RelationTable::setSummaryChildJoinSelectTable). Before the fix the outer ON named
 * `<alias>`.`value`, a column the grouped sub query does not have:
 * "Unknown column '<alias>.value' in 'on clause'" (V5JoinIssuesTest, baseline 5d4ddd4bb).
 *
 * Semantics taken from the indexed pivot (SearchService summary join): one sub query row per
 * pivot key (child rows grouped by the key, aggregates computed inside), LEFT JOINed to the
 * parent on parent.id = key (FIND_IN_SET for a multiple pivot); the outer query sums the
 * per-group aggregates. Results are executed and compared with values computed from the rows.
 */
class SummarySelectTableJoinTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
    }

    private function parent(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_SELECT);
    }

    private function child(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE_SELECT);
    }

    private function indexedPivot(): CustomColumn
    {
        return CustomColumn::getEloquent('parent_select_table', $this->child());
    }

    /** The same pivot as it is read once not indexed: from JSON (in memory, never saved). */
    private function jsonPivot(string $name = 'parent_select_table', bool $multiple = false): CustomColumn
    {
        $column = new CustomColumn();
        $column->custom_table_id = $this->child()->id;
        $column->column_name = $name;
        $column->column_type = ColumnType::SELECT_TABLE;
        $column->suuid = substr(md5('sstj' . $name), 0, 20);
        $column->options = array_filter([
            'multiple_enabled' => $multiple ? '1' : null,
            'select_target_table' => $this->parent()->id,
        ]);
        return $column;
    }

    private function relationTable(): RelationTable
    {
        $rt = RelationTable::getRelationTables($this->child(), false, ['search_enabled_only' => false, 'get_parent_relation_tables' => true])
            ->first(function ($r) {
                return isMatchString($r->table->id, $this->parent()->id) && (int)$r->searchType === SearchType::SUMMARY_SELECT_TABLE;
            });
        $this->assertNotNull($rt);
        return $rt;
    }

    /**
     * Summary as SearchService builds it: COUNT inside the sub query, SUM of it outside.
     *
     * @return array{rows: int, sql: string, counts: array<int, int|null>}
     */
    private function summary(CustomColumn $pivot, bool $groupByParent): array
    {
        $p = getDBTableName($this->parent());
        $rt = $this->relationTable();
        $a = $rt->tableUniqueName;
        $rt->subQueryCallbacks = [function ($sub) use ($a) {
            $g = \DB::getQueryGrammar(); // driver quoting: backticks on MySQL / MariaDB, brackets on SQL Server
            $sub->selectRaw('COUNT(' . $g->wrap("$a.id") . ') AS ' . $g->wrap('cnt'));
        }];
        $query = $this->parent()->getValueQuery();
        $g = \DB::getQueryGrammar();
        if ($groupByParent) {
            $query->selectRaw($g->wrap("$p.id") . ' AS ' . $g->wrap('pid') . ', SUM(' . $g->wrap("$a.cnt") . ') AS ' . $g->wrap('cnt'))->groupBy("$p.id");
        } else {
            $query->selectRaw($g->wrap("$p.id") . ' AS ' . $g->wrap('pid') . ', ' . $g->wrap("$a.cnt") . ' AS ' . $g->wrap('cnt'));
        }
        $rt->setSummaryChildJoinSelectTable($query, $this->parent(), $pivot, true);
        $sql = $query->toSql();
        $rows = $query->get();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row->pid] = $row->cnt === null ? null : (int)$row->cnt;
        }
        ksort($counts);
        return ['rows' => $rows->count(), 'sql' => $sql, 'counts' => $counts];
    }

    /** @return array<int, int|null> expected per visible parent, from the child rows themselves */
    private function expected(string $key, bool $multiple = false): array
    {
        $per = [];
        foreach (\DB::table(getDBTableName($this->child()))->whereNull('deleted_at')->get() as $row) {
            $v = json_decode((string)$row->value, true)[$key] ?? null;
            foreach ($multiple ? (array)$v : [$v] as $id) {
                if ($id !== null && $id !== '') {
                    $per[(int)$id] = ($per[(int)$id] ?? 0) + 1;
                }
            }
        }
        $out = [];
        foreach ($this->parent()->getValueQuery()->pluck('id') as $id) {
            $out[(int)$id] = $per[(int)$id] ?? null;
        }
        ksort($out);
        return $out;
    }

    /** A child row that matches no parent, one without the key, and a parent without children. */
    private function addNonMatching(string $key): int
    {
        $c = getDBTableName($this->child());
        // the index column is generated from the JSON `value`: it is populated through its source
        // JSON, never written directly (MySQL 8 and SQL Server reject a value for a generated column)
        foreach (['999999', null] as $json) {
            $row = ['value' => json_encode($json === null ? ['text' => 'sstj'] : [$key => $json]), 'suuid' => substr(md5(uniqid('', true)), 0, 20), 'created_at' => now(), 'updated_at' => now()];
            \DB::table($c)->insert($row);
        }
        return (int)\DB::table(getDBTableName($this->parent()))->insertGetId(['value' => json_encode(['text' => 'sstj orphan']), 'suuid' => substr(md5(uniqid('', true)), 0, 20), 'created_at' => now(), 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------ tests

    public function testJsonPivotRunsAndEqualsTheIndexedPivotAndTheRows(): void
    {
        $indexed = $this->indexedPivot();
        $orphan = $this->addNonMatching('parent_select_table');
        $expected = $this->expected('parent_select_table');
        $this->assertArrayHasKey($orphan, $expected);
        $this->assertNull($expected[$orphan], 'a parent without children stays, with no aggregate');

        $byIndex = $this->summary($indexed, false);
        $byJson = $this->summary($this->jsonPivot(), false);

        $this->assertSame($expected, $byIndex['counts'], 'indexed pivot (unchanged path)');
        $this->assertSame($expected, $byJson['counts'], 'JSON pivot: same per parent result');
        // single pivot: one sub query row per key => exactly one row per visible parent
        $this->assertSame(count($expected), $byJson['rows'], 'no duplicated parent rows');
        $this->assertSame($byIndex['rows'], $byJson['rows']);
        $this->assertSame(array_sum(array_filter($expected)), array_sum(array_filter($byJson['counts'])), 'no inflated or lost children');
        if (\Exceedone\Exment\Services\Storage\PhysicalSchemaManager::driverFamily() === 'sqlsrv') {
            return; // the SQL text asserted below is the MySQL / MariaDB grammar (json_unquote / json_extract)
        }
        // left join kept; the JSON value is exposed under the index column name, grouped by the JSON value
        $a = $this->relationTable()->tableUniqueName;
        $k = 'column_' . $this->jsonPivot()->suuid;
        $j = "json_unquote(json_extract(`$a`.`value`, '$.\"parent_select_table\"'))";
        $this->assertStringContainsString("left join (select $j as `$k`, COUNT(`$a`.`id`) AS `cnt` from `" . getDBTableName($this->child()) . "` as `$a` where `$a`.`deleted_at` is null group by $j) as `$a` on `" . getDBTableName($this->parent()) . "`.`id` = `$a`.`$k`", $byJson['sql']);
    }

    public function testRestrictedUserSeesTheSameAggregatesOnItsVisibleParents(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearRequestSession();
        $expected = $this->expected('parent_select_table');
        $this->assertLessThan($this->parent()->getValueModel()->withoutGlobalScopes()->count(), count($expected), 'precondition: user2 sees fewer parents');

        $byIndex = $this->summary($this->indexedPivot(), true);
        $byJson = $this->summary($this->jsonPivot(), true);
        $this->assertSame($expected, $byIndex['counts']);
        $this->assertSame($expected, $byJson['counts']);
    }

    /**
     * A MULTIPLE pivot used to fail to build (TypeError: whereInArrayColumn() on the JoinClause of a joinSub got the
     * sub query Expression as table name; history: testMultiplePivotSummaryJoinFailsToBuildAsBefore). Now it joins
     * element by element; expected totals are taken from the child rows written here: 1 vs 11 is no match, a repeated
     * element counts the child once, invalid / empty values match nothing, a parent without children keeps NULL.
     */
    public function testMultiplePivotSummaryJoinMatchesIndividualIds(): void
    {
        $ids = $this->parent()->getValueQuery()->orderBy('id')->pluck('id')->map(fn ($i) => (int)$i)->all();
        $this->assertGreaterThanOrEqual(3, count($ids));
        [$a, $b, $c] = $ids;
        $orphan = $this->addNonMatching('sstj_multi');
        $cdb = getDBTableName($this->child());
        $rows = [[$a, $b], [$a, $a], [(string)$a . '1', 'abc', ''], [], [$b]];
        foreach ($rows as $n => $v) {
            \DB::table($cdb)->insert(['value' => json_encode(['sstj_multi' => $v, 'text' => "m$n"]), 'suuid' => substr(md5(uniqid('', true)), 0, 20), 'created_at' => now(), 'updated_at' => now()]);
        }
        $expected = [];
        foreach ($ids as $id) {
            $expected[$id] = null;
        }
        $expected[$a] = 2; // [a,b] and [a,a] (once)
        $expected[$b] = 2; // [a,b] and [b]
        ksort($expected);

        $json = $this->summary($this->jsonPivot('sstj_multi', true), true);
        $this->assertSame(count($expected) + 1, $json['rows'], 'one row per parent (the orphan parent included)');
        $got = array_intersect_key($json['counts'], $expected);
        $this->assertSame($expected, $got);
        $this->assertArrayHasKey($orphan, $json['counts']);
        $this->assertNull($json['counts'][$orphan]);

        // the single value pivot is unchanged
        $single = $this->summary($this->jsonPivot(), true);
        $this->assertSame($this->expected('parent_select_table'), $single['counts']);
    }

    /**
     * Reachability (source + execution): SearchService finds summary select_table relations only
     * for indexed pivots (RelationTable::_getParentTablesSelectTable), so the screens never build
     * this join from JSON; it is reached by callers of the public RelationTable API.
     */
    public function testRelationDiscoveryOffersOnlyIndexedPivots(): void
    {
        $this->assertTrue((bool)$this->indexedPivot()->index_enabled);
        $found = RelationTable::getRelationTables($this->child(), false, ['search_enabled_only' => false, 'get_parent_relation_tables' => true])
            ->filter(function ($r) {
                return (int)$r->searchType === SearchType::SUMMARY_SELECT_TABLE;
            })->map(function ($r) {
                return $r->selectTablePivotColumn->column_name;
            })->values()->all();
        $this->assertContains('parent_select_table', $found);
        foreach ($found as $name) {
            $this->assertTrue((bool)CustomColumn::getEloquent($name, $this->child())->index_enabled, "$name is indexed");
        }
    }
}
