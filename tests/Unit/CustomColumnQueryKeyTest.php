<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\SyncMeiliReferencesJob;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Dashboard\FilterValue;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Support\DriverSql;

/**
 * Hybrid storage P1 slice 1: single-value query key of a custom column.
 *
 * Pins the behaviour of every caller of the shared query-key logic
 * (CustomColumn::getQueryKey / getIndexColumnName, FilterValue::columnExpr)
 * and of the caller-specific variant kept as it is (SyncMeiliReferencesJob):
 * the key itself, the generated SQL and bindings, and whether DDL (alterColumn)
 * runs. No DDL is executed by these tests: alterColumn is replaced by a counter.
 */
class CustomColumnQueryKeyTest extends UnitTestBase
{
    use DatabaseTransactions;
    use DriverSql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    /**
     * A column of the user table whose alterColumn() only counts its calls.
     */
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
        $column->suuid = $suuid ?? 'p1test' . substr(md5($name . ($indexed ? 'i' : 'n')), 0, 14);
        $column->options = array_filter([
            'index_enabled' => $indexed ? '1' : null,
            'multiple_enabled' => $multiple ? '1' : null,
        ]);
        return $column;
    }

    protected function userDbTable(): string
    {
        return getDBTableName(SystemTableName::USER);
    }

    // ---------------------------------------------------------------- getQueryKey / getIndexColumnName

    public function testNonIndexedColumnUsesJsonPathWithoutDdl(): void
    {
        $column = $this->makeColumn('p1_plain', false);

        $this->assertSame('value->p1_plain', $column->getQueryKey());
        $this->assertSame(0, $column->alterCount, 'a non-indexed column never runs DDL');
    }

    public function testIndexedColumnWithMissingDbColumnRunsDdlOnlyWhenAllowed(): void
    {
        $column = $this->makeColumn('p1_missing', true);
        $expected = 'column_' . $column->suuid;
        $this->assertFalse(hasColumn($this->userDbTable(), $expected), 'precondition: the index column does not exist');

        $this->assertSame($expected, $column->getIndexColumnName(false));
        $this->assertSame(0, $column->alterCount, 'getIndexColumnName(false) never runs DDL');

        $this->assertSame($expected, $column->getIndexColumnName());
        $this->assertSame(1, $column->alterCount, 'getIndexColumnName() runs DDL when the column is missing');

        $this->assertSame($expected, $column->getQueryKey());
        $this->assertSame(2, $column->alterCount, 'getQueryKey() keeps the auto-DDL of getIndexColumnName()');
    }

    public function testIndexedColumnWithExistingDbColumnRunsNoDdl(): void
    {
        $boss = CustomColumn::getEloquent('boss', SystemTableName::USER);
        $this->assertNotNull($boss, 'precondition: seeded indexed column "boss"');
        $column = $this->makeColumn('boss', true, false, ColumnType::USER, $boss->suuid);
        $expected = 'column_' . $boss->suuid;
        $this->assertTrue(hasColumn($this->userDbTable(), $expected), 'precondition: the index column exists');

        $this->assertSame($expected, $column->getQueryKey());
        $this->assertSame($expected, $column->getIndexColumnName());
        $this->assertSame(0, $column->alterCount);
    }

    public function testQueryKeyWithoutAlterNeverRunsDdl(): void
    {
        $indexed = $this->makeColumn('p1_missing2', true);
        $plain = $this->makeColumn('p1_plain2', false);

        $this->assertSame('column_' . $indexed->suuid, $indexed->getQueryKeyWithoutAlter());
        $this->assertSame('value->p1_plain2', $plain->getQueryKeyWithoutAlter());
        $this->assertSame(0, $indexed->alterCount + $plain->alterCount);
        $this->assertFalse(hasColumn($this->userDbTable(), 'column_' . $indexed->suuid));
    }

    public function testGeneratedSqlAndBindingsOfQueryKey(): void
    {
        $boss = CustomColumn::getEloquent('boss', SystemTableName::USER);
        $plain = $this->makeColumn('p1_plain3', false);

        $query = getModelName(SystemTableName::USER)::query()->withoutGlobalScopes()->where($boss->getQueryKey(), 'abc');
        $this->assertStringContainsString(static::q('column_' . $boss->suuid) . ' = ?', $query->toSql());
        $this->assertSame(['abc'], $query->getBindings());

        $query = getModelName(SystemTableName::USER)::query()->withoutGlobalScopes()->where($plain->getQueryKey(), 'abc');
        $this->assertStringContainsString(static::jsonText(static::q('value'), 'p1_plain3') . ' = ?', $query->toSql());
        $this->assertSame(['abc'], $query->getBindings());
    }

    // ---------------------------------------------------------------- FilterValue::columnExpr (no DDL caller)

    public function testFilterValueColumnExprWithRealColumns(): void
    {
        $grammar = \DB::getQueryGrammar();
        $table = $this->userDbTable();

        $indexed = $this->makeColumn('p1_fv_index', true);
        $this->assertSame($grammar->wrap($table . '.column_' . $indexed->suuid), FilterValue::columnExpr($indexed));

        $plain = $this->makeColumn('p1_fv_plain', false);
        $expr = FilterValue::columnExpr($plain);
        $this->assertSame($grammar->wrap($table . '.value->p1_fv_plain'), $expr);
        $this->assertStringContainsString(static::jsonExtract(static::q($table . '.value'), 'p1_fv_plain'), $expr);

        $this->assertSame(0, $indexed->alterCount + $plain->alterCount, 'columnExpr never runs DDL');
    }

    public function testFilterValueSqlServerBranchOnlyTouchesMultipleNonIndexedColumns(): void
    {
        \Exment::partialMock()->shouldReceive('isSqlServer')->andReturn(true);
        $grammar = \DB::getQueryGrammar();
        $table = $this->userDbTable();

        // MySQL / MariaDB grammar: the json_value -> JSON_QUERY rewrite has nothing to replace, so the expression
        // stays exactly the grammar's own. SQL Server grammar: json_value() reads an array as NULL, so the rewrite
        // to JSON_QUERY applies - and only the function name changes.
        $multiple = $this->makeColumn('p1_fv_multi', false, true, ColumnType::SELECT);
        if (static::sqlServer()) {
            $this->assertSame("JSON_QUERY(" . static::q($table . '.value') . ", '$.\"p1_fv_multi\"')", FilterValue::columnExpr($multiple));
        } else {
            $this->assertSame($grammar->wrap($table . '.value->p1_fv_multi'), FilterValue::columnExpr($multiple));
        }

        $indexedMultiple = $this->makeColumn('p1_fv_multi_idx', true, true, ColumnType::SELECT);
        $this->assertSame($grammar->wrap($table . '.column_' . $indexedMultiple->suuid), FilterValue::columnExpr($indexedMultiple));
        $this->assertSame(0, $multiple->alterCount + $indexedMultiple->alterCount);
    }

    // ---------------------------------------------------------------- SyncMeiliReferencesJob (caller-specific, unchanged)

    protected function referencingSql(array $columns, $valueId): array
    {
        $method = new \ReflectionMethod(SyncMeiliReferencesJob::class, 'referencingQuery');
        $method->setAccessible(true);
        $query = $method->invoke(null, CustomTable::getEloquent(SystemTableName::USER), $columns, $valueId);
        return [$query->toSql(), $query->getBindings()];
    }

    public function testMeiliReferencesFallsBackToJsonWhenIndexColumnIsMissing(): void
    {
        $missing = $this->makeColumn('p1_ref_missing', true, false, ColumnType::USER);
        [$sql, $bindings] = $this->referencingSql([$missing], 7);

        $this->assertStringContainsString(static::jsonText(static::q('value'), 'p1_ref_missing') . ' = ?', $sql);
        $this->assertStringNotContainsString('column_' . $missing->suuid, $sql);
        $this->assertSame(['7'], $bindings);
        $this->assertSame(0, $missing->alterCount, 'the reference sync never creates an index column');
    }

    public function testMeiliReferencesUsesExistingIndexColumnAndJsonContainsForMultiple(): void
    {
        $boss = CustomColumn::getEloquent('boss', SystemTableName::USER);
        $existing = $this->makeColumn('boss', true, false, ColumnType::USER, $boss->suuid);
        $multiple = $this->makeColumn('p1_ref_multi', false, true, ColumnType::USER);
        [$sql, $bindings] = $this->referencingSql([$existing, $multiple], 7);

        $this->assertStringContainsString(static::q('column_' . $boss->suuid) . ' = ?', $sql);
        // an element of the multi-valued JSON array: json_contains on MySQL / MariaDB, openjson on SQL Server
        if (static::sqlServer()) {
            $this->assertStringContainsString("in (select " . static::q('value') . " from openjson(" . static::q('value') . ", '$.\"p1_ref_multi\"'))", $sql);
        } else {
            $this->assertStringContainsString('json_contains(`value`', $sql);
        }
        $this->assertSame(0, $existing->alterCount + $multiple->alterCount);
        $this->assertContains('7', $bindings);
    }
}
