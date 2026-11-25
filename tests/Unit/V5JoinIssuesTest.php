<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Database\QueryException;

/**
 * Hybrid storage P1 verification V5: the two existing join issues of slice 5, EXECUTED (not only
 * toSql) - identical on the pre-P1 baseline and on the P1 tree. Issue 1 is characterization only
 * (not fixed); issue 2 was fixed afterwards in its own bug-fix slice (V5-2).
 *
 * 1. A sort on a JSON (unindexed) column of a parent table joined through a select_table column:
 *    the order key is not qualified, MariaDB rejects the ambiguous `value`. Reached only through
 *    the public SearchService::orderBy(); a saved view sort on such a column is dropped by
 *    ColumnItem::setQuerySort() (not sortable), so the grid query runs unsorted on that key.
 * 2. The summary join of a child select_table column read from JSON: the outer ON named
 *    `<alias>`.`value`, which the grouped sub query does not select - "Unknown column
 *    '<alias>.value' in 'on clause'" on both trees before the fix. Fixed (slice V5-2): the sub
 *    query exposes the JSON key under the index column's name; the test now expects it to run.
 */
class V5JoinIssuesTest extends UnitTestBase
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

    private function outcome(\Closure $run): string
    {
        try {
            $count = count($run());
            return "ok:$count";
        } catch (QueryException $ex) {
            return 'error:' . $ex->getMessage();
        }
    }

    public function testSavedSortOnAJsonColumnOfAJoinedParentFailsWhenTheGridQueryRuns(): void
    {
        $child = $this->child();
        $parent = $this->parent();
        $pivot = CustomColumn::getEloquent('parent_select_table', $child);
        $text = CustomColumn::getEloquent('text', $parent);
        $this->assertFalse((bool)$text->index_enabled, 'precondition: parent text is not indexed');

        $view = CustomView::create([
            'custom_table_id' => $child->id,
            'view_view_name' => 'v5 sort',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::DEFAULT,
        ]);
        $sort = new CustomViewSort();
        $sort->custom_view_id = $view->id;
        $sort->view_column_type = ConditionType::COLUMN;
        $sort->view_column_table_id = $parent->id;
        $sort->view_column_target_id = $text->id;
        $sort->sort = 1;
        $sort->priority = 1;
        $sort->setOption('view_pivot_table_id', $child->id);
        $sort->setOption('view_pivot_column_id', $pivot->id);
        $sort->save();
        System::clearRequestSession();
        $view = CustomView::find($view->id);

        // the saved sort: ColumnItem::setQuerySort() drops a sort on a column that is not sortable
        // (not indexed) - the parent join is still added, the ORDER BY is not, and the query runs
        $query = $child->getValueQuery();
        $view->filterSortModel($query);
        $sql = $query->toSql();
        $this->assertStringContainsString(' left join ' . \DB::getQueryGrammar()->wrap(getDBTableName($parent)) . ' as ', $sql);
        $this->assertStringNotContainsString('order by', $sql);
        $this->assertSame('ok:' . $child->getValueQuery()->count(), $this->outcome(function () use ($query) {
            return $query->get();
        }));

        if (\Exceedone\Exment\Services\Storage\PhysicalSchemaManager::driverFamily() === 'sqlsrv') {
            return; // the remainder asserts the MySQL / MariaDB ambiguous-column error and JSON SQL text
        }
        // the public SearchService::orderBy() (no caller inside Exment src; reachable from plugins)
        // has no such guard: the unqualified JSON key after the join is rejected as ambiguous
        $query = (new \Exceedone\Exment\Services\Search\SearchService($child))->orderBy($text)->query();
        $this->assertStringContainsString("order by json_unquote(json_extract(`value`, '$.\"text\"')) asc", $query->toSql());
        $result = $this->outcome(function () use ($query) {
            return $query->get();
        });
        $this->assertStringStartsWith('error:', $result);
        $this->assertStringContainsString('ambiguous', $result);
    }

    public function testSummaryJoinOfAJsonSelectTablePivotRunsAfterTheFix(): void
    {
        $parent = $this->parent();
        $child = $this->child();
        $rt = RelationTable::getRelationTables($child, false, ['search_enabled_only' => false, 'get_parent_relation_tables' => true])
            ->first(function ($r) use ($parent) {
                return isMatchString($r->table->id, $parent->id) && (int)$r->searchType === SearchType::SUMMARY_SELECT_TABLE;
            });
        $this->assertNotNull($rt);

        // the pivot as it is read once its index is turned off: from JSON
        $pivot = new CustomColumn();
        $pivot->custom_table_id = $child->id;
        $pivot->column_name = 'parent_select_table';
        $pivot->column_type = ColumnType::SELECT_TABLE;
        $pivot->suuid = 'v5jsonpivot00000000';
        $pivot->options = ['select_target_table' => $parent->id];

        $p = getDBTableName($parent);
        $query = $parent->getValueQuery()->select("$p.id");
        $rt->setSummaryChildJoinSelectTable($query, $parent, $pivot, true);

        // FIXED in the bug-fix slice V5-2. Recorded before the fix (baseline 5d4ddd4bb and the P1 tree,
        // 2026-11-24, executed): "Unknown column '<alias>.value' in 'on clause'". Now it runs and,
        // like the indexed pivot, keeps one row per parent (results: SummarySelectTableJoinTest).
        $this->assertSame('ok:' . $parent->getValueQuery()->count(), $this->outcome(function () use ($query) {
            return $query->get();
        }), 'the summary join with a JSON pivot runs');

        // the same join with the indexed pivot runs
        $indexed = CustomColumn::getEloquent('parent_select_table', $child);
        $query = $parent->getValueQuery()->select("$p.id");
        $rt->setSummaryChildJoinSelectTable($query, $parent, $indexed, true);
        $this->assertSame('ok:' . $parent->getValueQuery()->count(), $this->outcome(function () use ($query) {
            return $query->get();
        }));
    }
}
