<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\ColumnItems\SystemItem;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * Hybrid storage P1 slice 2: purpose-aware query key of a custom column.
 *
 * Pins, through the production callers, the SQL that CustomItem::sqlname(), getSortName(),
 * getCastColumn() (sort / number filter) and the "empty" / "not empty" view filters build
 * for a custom column, and when the index-column DDL (alterColumn) runs.
 * Expected strings are written out literally (MySQL / MariaDB grammar).
 * No DDL is executed: alterColumn is replaced by a counter.
 */
class CustomItemQueryPurposeTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function makeColumn(string $name, bool $indexed, bool $multiple = false, string $type = ColumnType::TEXT, ?string $suuid = null)
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
        $column->column_type = $type;
        $column->suuid = $suuid ?? 'p2test' . substr(md5($name . ($indexed ? 'i' : 'n')), 0, 14);
        $column->options = array_filter([
            'index_enabled' => $indexed ? '1' : null,
            'multiple_enabled' => $multiple ? '1' : null,
        ]);
        return $column;
    }

    protected function item(CustomColumn $column): CustomItem
    {
        return CustomItem::getItem($column, null);
    }

    protected function table(): string
    {
        return getDBTableName(SystemTableName::USER);
    }

    protected function jsonExpr(string $name): string
    {
        return "json_unquote(json_extract(`{$this->table()}`.`value`, '$.\"{$name}\"'))";
    }

    /** Laravel MySqlGrammar::whereNull / whereNotNull on a JSON selector. */
    protected function jsonNullExpr(string $name, bool $not = false): string
    {
        $e = "json_extract(`{$this->table()}`.`value`, '$.\"{$name}\"')";
        return $not ? "({$e} is not null AND json_type({$e}) != 'NULL')" : "({$e} is null OR json_type({$e}) = 'NULL')";
    }

    protected function filterSql(int $option, CustomItem $item, $value = null): array
    {
        $query = getModelName(SystemTableName::USER)::query()->withoutGlobalScopes();
        ViewFilterBase::make($option, $item)->setFilter($query, $value);
        $sql = $query->toSql();
        return [substr($sql, strpos($sql, ' where ') + 7), $query->getBindings()];
    }

    // ---------------------------------------------------------------- sqlname / getSortName

    public function testSqlnameAndSortNameOfJsonColumn(): void
    {
        $column = $this->makeColumn('p2_plain', false);
        $item = $this->item($column);

        $this->assertSame('value->p2_plain', $item->sqlname());
        $this->assertSame($this->table() . '.value->p2_plain', $item->getSortName());
        $this->assertSame($this->table() . '.value->p2_plain', $item->getTableColumn());
        $this->assertSame(0, $column->alterCount);
    }

    public function testSqlnameOfIndexedColumnKeepsAutoDdl(): void
    {
        $column = $this->makeColumn('p2_missing', true);
        $item = $this->item($column);
        $this->assertFalse(hasColumn($this->table(), 'column_' . $column->suuid), 'precondition: index column missing');

        $this->assertSame('column_' . $column->suuid, $item->sqlname());
        $this->assertSame(1, $column->alterCount, 'sqlname() runs the DDL of getQueryKey()');

        $this->assertSame($this->table() . '.column_' . $column->suuid, $item->getSortName());
        $this->assertSame(2, $column->alterCount, 'getSortName() runs the DDL of getQueryKey()');
    }

    public function testExistingIndexColumnRunsNoDdl(): void
    {
        $boss = CustomColumn::getEloquent('boss', SystemTableName::USER);
        $column = $this->makeColumn('boss', true, false, ColumnType::USER, $boss->suuid);
        $item = $this->item($column);

        $this->assertSame('column_' . $boss->suuid, $item->sqlname());
        $this->assertSame($this->table() . '.column_' . $boss->suuid, $item->getSortName());
        $this->assertSame(0, $column->alterCount);
    }

    // ---------------------------------------------------------------- getCastColumn (sort)

    public function testCastSortColumnOfJsonAndIndexedColumns(): void
    {
        $text = $this->makeColumn('p2_text', false);
        $this->assertSame($this->jsonExpr('p2_text'), $this->item($text)->getSortWrapTableColumn());

        $integer = $this->makeColumn('p2_int', false, false, ColumnType::INTEGER);
        $this->assertSame('CAST(' . $this->jsonExpr('p2_int') . ' AS signed)', $this->item($integer)->getSortWrapTableColumn());
        $this->assertSame(0, $text->alterCount + $integer->alterCount);

        $indexed = $this->makeColumn('p2_int_idx', true, false, ColumnType::INTEGER);
        $this->assertSame("CAST(`{$this->table()}`.`column_{$indexed->suuid}` AS signed)", $this->item($indexed)->getSortWrapTableColumn());
        $this->assertSame(1, $indexed->alterCount, 'the cast column of an indexed column keeps the auto-DDL of index()');
    }

    public function testCastColumnWithExplicitNameIsUnchanged(): void
    {
        $integer = $this->makeColumn('p2_int2', false, false, ColumnType::INTEGER);
        $this->assertSame("CAST(`{$this->table()}`.`parent_id` AS signed)", $this->item($integer)->getCastWrapTableColumn('parent_id'));
        $this->assertSame(0, $integer->alterCount);
    }

    public function testSystemItemCastColumnUsesItsOwnIndexName(): void
    {
        $item = SystemItem::getItem(CustomTable::getEloquent(SystemTableName::USER), 'id', null);
        $this->assertSame("`{$this->table()}`.`id`", $item->getSortWrapTableColumn());
    }

    // ---------------------------------------------------------------- view filters

    public function testNumberFilterSql(): void
    {
        $column = $this->makeColumn('p2_num', false, false, ColumnType::INTEGER);
        [$where, $bindings] = $this->filterSql(FilterOption::NUMBER_GT, $this->item($column), 5);

        $this->assertSame('CAST(' . $this->jsonExpr('p2_num') . ' AS signed) > ?', $where);
        $this->assertSame([5], $bindings);
        $this->assertSame(0, $column->alterCount);
    }

    public function testEmptyFiltersOfSingleValueJsonColumn(): void
    {
        $column = $this->makeColumn('p2_null', false);
        $item = $this->item($column);

        $this->assertSame([$this->jsonNullExpr('p2_null'), []], $this->filterSql(FilterOption::NULL, $item));
        $this->assertSame([$this->jsonNullExpr('p2_null', true), []], $this->filterSql(FilterOption::NOT_NULL, $item));
        $this->assertSame(0, $column->alterCount);
    }

    public function testEmptyFiltersOfMultiValueJsonColumn(): void
    {
        $column = $this->makeColumn('p2_multi', false, true, ColumnType::SELECT);
        $item = $this->item($column);
        $j = $this->jsonExpr('p2_multi');

        $isNull = $this->jsonNullExpr('p2_multi');
        $notNull = $this->jsonNullExpr('p2_multi', true);

        $this->assertSame(["({$isNull} or {$j} = ?)", ['[]']], $this->filterSql(FilterOption::NULL, $item));
        $this->assertSame(["({$notNull} and {$j} <> ?)", ['[]']], $this->filterSql(FilterOption::NOT_NULL, $item));
    }

    public function testEmptyFiltersOfIndexedColumn(): void
    {
        $column = $this->makeColumn('p2_null_idx', true);
        $item = $this->item($column);
        $c = "`{$this->table()}`.`column_{$column->suuid}`";

        $this->assertSame(["{$c} is null", []], $this->filterSql(FilterOption::NULL, $item));
        $this->assertSame(1, $column->alterCount, 'the empty filter keeps the auto-DDL of getTableColumn()');
        $this->assertSame(["{$c} is not null", []], $this->filterSql(FilterOption::NOT_NULL, $item));
        $this->assertSame(2, $column->alterCount);
    }

    // ---------------------------------------------------------------- resolver contract (slice 2 only)

    public function testEveryPurposeResolvesToTheQueryKey(): void
    {
        $plain = $this->makeColumn('p2_purpose', false);
        foreach ([CustomColumn::QUERY_PURPOSE_GENERAL, CustomColumn::QUERY_PURPOSE_SORT, CustomColumn::QUERY_PURPOSE_CAST, CustomColumn::QUERY_PURPOSE_NULL_CHECK] as $purpose) {
            $this->assertSame('value->p2_purpose', $plain->getQueryKeyFor($purpose), $purpose);
        }
        $this->assertSame(0, $plain->alterCount);

        $this->expectException(\InvalidArgumentException::class);
        $plain->getQueryKeyFor('order_by');
    }
}
