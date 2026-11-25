<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Search\SearchService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Hybrid storage P1 slice 4: SearchService::orderBy() on a column of the searched table.
 *
 * Pins, through the production caller, the SQL, bindings and result set of the order-by query
 * (unqualified query key), and when the index-column DDL (alterColumn) runs.
 * The related-table branch (after setJoin) is out of scope and not covered here.
 * Expected strings are written out literally (MySQL / MariaDB grammar).
 */
class SearchServiceOrderByQueryTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function countingColumn(string $name, bool $indexed): CustomColumn
    {
        $column = new class () extends CustomColumn {
            public int $alterCount = 0;

            // @phpstan-ignore-next-line
            public function alterColumn($forceDropIndex = false)
            {
                $this->alterCount++;
            }
        };
        $column->custom_table_id = CustomTable::getEloquent(SystemTableName::USER)->id;
        $column->column_name = $name;
        $column->column_type = ColumnType::TEXT;
        $column->suuid = 'p4test' . substr(md5($name . ($indexed ? 'i' : 'n')), 0, 14);
        $column->options = array_filter(['index_enabled' => $indexed ? '1' : null]);
        return $column;
    }

    protected function editTable(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
    }

    /**
     * @return array{0: string, 1: array<mixed>, 2: array<int>}
     */
    protected function orderBy(CustomTable $table, $column, string $direction, bool $execute = true): array
    {
        $query = (new SearchService($table))->orderBy($column, $direction)->query();
        $ids = $execute ? $query->get()->pluck('id')->map(function ($id) {
            return (int)$id;
        })->sort()->values()->all() : [];
        return [$query->toSql(), $query->getBindings(), $ids];
    }

    /** @return array<int> */
    protected function allIds(CustomTable $table): array
    {
        return $table->getValueQuery()->pluck('id')->map(function ($id) {
            return (int)$id;
        })->sort()->values()->all();
    }

    public function testOrderByJsonColumn(): void
    {
        $table = $this->editTable();
        $db = getDBTableName($table);

        [$sql, $bindings, $ids] = $this->orderBy($table, 'text', 'desc');

        $this->assertSame("select `{$db}`.* from `{$db}` where `{$db}`.`deleted_at` is null order by json_unquote(json_extract(`value`, '$.\"text\"')) desc", $sql);
        $this->assertSame([], $bindings);
        $this->assertSame($this->allIds($table), $ids, 'order by does not change the result set');
    }

    public function testOrderByExistingIndexColumn(): void
    {
        $table = $this->editTable();
        $db = getDBTableName($table);
        $index = CustomColumn::getEloquent('index_text', $table);

        [$sql, $bindings, $ids] = $this->orderBy($table, $index, 'asc');

        $this->assertSame("select `{$db}`.* from `{$db}` where `{$db}`.`deleted_at` is null order by `column_{$index->suuid}` asc", $sql);
        $this->assertSame([], $bindings);
        $this->assertSame($this->allIds($table), $ids);
    }

    public function testOrderByJsonColumnRunsNoDdl(): void
    {
        $table = CustomTable::getEloquent(SystemTableName::USER);
        $db = getDBTableName($table);
        $column = $this->countingColumn('p4_plain', false);

        [$sql] = $this->orderBy($table, $column, 'asc', false);

        $this->assertSame("select `{$db}`.* from `{$db}` where `{$db}`.`deleted_at` is null order by json_unquote(json_extract(`value`, '$.\"p4_plain\"')) asc", $sql);
        $this->assertSame(0, $column->alterCount);
    }

    public function testOrderByIndexedColumnKeepsAutoDdl(): void
    {
        $table = CustomTable::getEloquent(SystemTableName::USER);
        $db = getDBTableName($table);
        $column = $this->countingColumn('p4_missing', true);
        $this->assertFalse(hasColumn($db, 'column_' . $column->suuid), 'precondition: index column missing');

        [$sql] = $this->orderBy($table, $column, 'asc', false);

        $this->assertSame("select `{$db}`.* from `{$db}` where `{$db}`.`deleted_at` is null order by `column_{$column->suuid}` asc", $sql);
        $this->assertSame(1, $column->alterCount, 'orderBy() keeps the auto-DDL of getQueryKey()');
    }
}
