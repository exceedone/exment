<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomColumn;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeQuery;
use Exceedone\Exment\Services\Dashboard\FilterState;

/**
 * FilterState::where() / whereExpr() — the ONLY place a filter value becomes SQL (box
 * query, cascade option lists, benchmark scopes, level probes all route through it).
 * A FakeQuery records the calls, so the exact SQL shape and bindings are asserted:
 *
 *  - single value keeps the legacy SQL byte-identical (where(index, v) / JSON... = ?);
 *  - list → IN with one placeholder per value (values only ever travel as bindings);
 *  - range → NOT NULL/blank guard + >= / <= with type-aware comparison;
 *  - invalid bounds for the column type are dropped, never turned into a wrong filter.
 */
class FilterStateSqlTest extends DashboardUnitTestCase
{
    // ---- where(): index column vs JSON extraction ------------------------------

    public function testSingleValueOnIndexedColumnKeepsLegacyWhere(): void
    {
        $q = new FakeQuery();
        FilterState::where($q, new FakeCustomColumn('region', 'select_table', true), 'region', '3');
        $this->assertSame([['where', ['column_suuid_region', '3']]], $q->calls);
    }

    public function testSingleValueOnJsonColumnKeepsLegacyRaw(): void
    {
        $q = new FakeQuery();
        FilterState::where($q, new FakeCustomColumn('region', 'select_table', false), 'region', '3');
        $this->assertSame(
            [['whereRaw', ["JSON_UNQUOTE(JSON_EXTRACT(`value`, '$.\"region\"')) = ?", ['3']]]],
            $q->calls
        );
    }

    public function testListOnIndexedColumnUsesWhereIn(): void
    {
        $q = new FakeQuery();
        FilterState::where($q, new FakeCustomColumn('region', 'select', true), 'region', ['3', '5']);
        $this->assertSame([['whereIn', ['column_suuid_region', ['3', '5']]]], $q->calls);
    }

    public function testListOnJsonColumnUsesPlaceholders(): void
    {
        $q = new FakeQuery();
        FilterState::where($q, new FakeCustomColumn('region', 'select', false), 'region', ['3', '5', '7']);
        $this->assertSame(
            [['whereRaw', ["JSON_UNQUOTE(JSON_EXTRACT(`value`, '$.\"region\"')) IN (?,?,?)", ['3', '5', '7']]]],
            $q->calls
        );
    }

    /** A one-element list is the same filter as the scalar — and must produce the same SQL. */
    public function testOneElementListEqualsScalar(): void
    {
        $a = new FakeQuery();
        $b = new FakeQuery();
        $col = new FakeCustomColumn('region', 'select', true);
        FilterState::where($a, $col, 'region', '3');
        FilterState::where($b, $col, 'region', ['3']);
        $this->assertSame($a->calls, $b->calls);
    }

    public function testNoFilterProducesNoSql(): void
    {
        $q = new FakeQuery();
        $col = new FakeCustomColumn('region', 'select', true);
        FilterState::where($q, $col, 'region', null);
        FilterState::where($q, $col, 'region', '');
        FilterState::where($q, $col, 'region', []);
        FilterState::where($q, $col, 'region', ['junk' => 1]);
        $this->assertSame([], $q->calls);
    }

    // ---- ranges -----------------------------------------------------------------

    public function testNumberRangeCastsAndGuardsBlank(): void
    {
        $q = new FakeQuery();
        FilterState::where($q, new FakeCustomColumn('score', 'integer', true), 'score', ['from' => '80', 'to' => '100']);
        $this->assertSame([
            ['whereRaw', ['`column_suuid_score` IS NOT NULL AND `column_suuid_score` <> \'\'']],
            ['whereRaw', ['CAST(`column_suuid_score` AS DECIMAL(20,4)) >= ?', ['80']]],
            ['whereRaw', ['CAST(`column_suuid_score` AS DECIMAL(20,4)) <= ?', ['100']]],
        ], $q->calls);
    }

    public function testNumberRangeOpenEnded(): void
    {
        $q = new FakeQuery();
        FilterState::whereExpr($q, 'X', ['from' => '80'], 'number');
        $this->assertSame([
            ['whereRaw', ['X IS NOT NULL AND X <> \'\'']],
            ['whereRaw', ['CAST(X AS DECIMAL(20,4)) >= ?', ['80']]],
        ], $q->calls);
    }

    public function testNonNumericBoundOnNumberColumnIsDropped(): void
    {
        $q = new FakeQuery();
        FilterState::whereExpr($q, 'X', ['from' => 'abc', 'to' => '9'], 'number');
        $this->assertSame([
            ['whereRaw', ['X IS NOT NULL AND X <> \'\'']],
            ['whereRaw', ['CAST(X AS DECIMAL(20,4)) <= ?', ['9']]],
        ], $q->calls, 'the invalid lower bound must not become CAST(...) >= "abc"');

        $q2 = new FakeQuery();
        FilterState::whereExpr($q2, 'X', ['from' => 'abc', 'to' => 'def'], 'number');
        $this->assertSame([], $q2->calls, 'both bounds invalid → no filter rather than a wrong one');
    }

    public function testDateRangeComparesIsoStrings(): void
    {
        $q = new FakeQuery();
        FilterState::whereExpr($q, 'D', ['from' => '2026-04-01', 'to' => '2026-04-30'], 'date');
        $this->assertSame([
            ['whereRaw', ['D IS NOT NULL AND D <> \'\'']],
            ['whereRaw', ['D >= ?', ['2026-04-01']]],
            ['whereRaw', ['D <= ?', ['2026-04-30']]],
        ], $q->calls);
    }

    public function testDatetimeRangeIncludesWholeLastDay(): void
    {
        $q = new FakeQuery();
        FilterState::whereExpr($q, 'D', ['from' => '2026-04-01T09:00', 'to' => '2026-04-30'], 'datetime');
        $this->assertSame([
            ['whereRaw', ['D IS NOT NULL AND D <> \'\'']],
            ['whereRaw', ['D >= ?', ['2026-04-01']]],
            ['whereRaw', ['D <= ?', ['2026-04-30 23:59:59']]],
        ], $q->calls, 'a datetime "to" day must include its evening; the "from" keeps only the date part');
    }

    public function testNonDateBoundOnDateColumnIsDropped(): void
    {
        $q = new FakeQuery();
        FilterState::whereExpr($q, 'D', ['from' => 'yesterday', 'to' => '2026-04-30'], 'date');
        $this->assertSame([
            ['whereRaw', ['D IS NOT NULL AND D <> \'\'']],
            ['whereRaw', ['D <= ?', ['2026-04-30']]],
        ], $q->calls);
    }

    public function testTextRangeIsPlainStringCompare(): void
    {
        $q = new FakeQuery();
        FilterState::whereExpr($q, 'T', ['from' => 'a', 'to' => 'm'], 'text');
        $this->assertSame([
            ['whereRaw', ['T IS NOT NULL AND T <> \'\'']],
            ['whereRaw', ['T >= ?', ['a']]],
            ['whereRaw', ['T <= ?', ['m']]],
        ], $q->calls);
    }

    /** where() picks the kind from the column, so a JSON (non-indexed) number column casts too. */
    public function testRangeOnJsonNumberColumn(): void
    {
        $q = new FakeQuery();
        FilterState::where($q, new FakeCustomColumn('score', 'decimal', false), 'score', ['from' => '1.5']);
        $expr = "JSON_UNQUOTE(JSON_EXTRACT(`value`, '$.\"score\"'))";
        $this->assertSame([
            ['whereRaw', ["{$expr} IS NOT NULL AND {$expr} <> ''"]],
            ['whereRaw', ["CAST({$expr} AS DECIMAL(20,4)) >= ?", ['1.5']]],
        ], $q->calls);
    }

    /** Values only ever travel as bindings — a value that looks like SQL is inert. */
    public function testValuesAreNeverInlined(): void
    {
        $q = new FakeQuery();
        $evil = "1' OR '1'='1";
        FilterState::where($q, new FakeCustomColumn('region', 'select', false), 'region', [$evil, 'x']);
        [$method, $args] = $q->calls[0];
        $this->assertSame('whereRaw', $method);
        $this->assertStringNotContainsString($evil, $args[0]);
        $this->assertSame([$evil, 'x'], $args[1]);
    }
}
