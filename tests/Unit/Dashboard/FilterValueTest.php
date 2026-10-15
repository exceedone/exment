<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Services\Dashboard\FilterValue;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomColumn;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeQuery;

class FilterValueTest extends DashboardUnitTestCase
{
    public function testParseShapes(): void
    {
        $this->assertSame(['in' => ['v']], FilterValue::parse('v'));
        $this->assertSame(['in' => ['v']], FilterValue::parse(' v '));
        $this->assertSame(['in' => ['a', 'b']], FilterValue::parse(['a', 'b', '', 'a']));
        $this->assertSame(['from' => '1', 'to' => '9'], FilterValue::parse(['from' => '1', 'to' => '9']));
        $this->assertSame(['from' => null, 'to' => '9'], FilterValue::parse(['from' => '', 'to' => '9']));
    }

    public function testParseRejectsEmptyAndJunk(): void
    {
        $this->assertNull(FilterValue::parse(null));
        $this->assertNull(FilterValue::parse(''));
        $this->assertNull(FilterValue::parse('   '));
        $this->assertNull(FilterValue::parse([]));
        $this->assertNull(FilterValue::parse(['', '']));
        $this->assertNull(FilterValue::parse(['from' => '', 'to' => '']));
        $this->assertNull(FilterValue::parse(['x' => 'y']), 'an associative value that is not a range is not a filter');
        $this->assertNull(FilterValue::parse([['nested']]));
    }

    public function testIdentifier(): void
    {
        $this->assertTrue(FilterValue::isIdentifier('grade_1'));
        $this->assertFalse(FilterValue::isIdentifier('grade-1'));
        $this->assertFalse(FilterValue::isIdentifier("grade\n"));
        $this->assertFalse(FilterValue::isIdentifier(''));
        $this->assertFalse(FilterValue::isIdentifier(null));
    }

    public function testKindAndStyleFollowColumnType(): void
    {
        $this->assertSame('number', FilterValue::kind(new FakeCustomColumn('n', 'integer')));
        $this->assertSame('number', FilterValue::kind(new FakeCustomColumn('n', 'decimal')));
        $this->assertSame('date', FilterValue::kind(new FakeCustomColumn('d', 'date')));
        $this->assertSame('datetime', FilterValue::kind(new FakeCustomColumn('d', 'datetime')));
        $this->assertSame('text', FilterValue::kind(new FakeCustomColumn('s', 'select_table')));
        $this->assertSame('text', FilterValue::kind(null));

        // a filter lists the values the data holds, numbers included; only dates, whose
        // input already carries a calendar, keep a from / to range
        $this->assertSame('select', FilterValue::style(new FakeCustomColumn('n', 'integer')));
        $this->assertSame('select', FilterValue::style(new FakeCustomColumn('n', 'decimal')));
        $this->assertSame('select', FilterValue::style(new FakeCustomColumn('s', 'select')));
        $this->assertSame('range', FilterValue::style(new FakeCustomColumn('d', 'date')));
        $this->assertSame('range', FilterValue::style(new FakeCustomColumn('d', 'datetime')));
    }

    public function testConfiguredStyleOverridesTheColumnDefault(): void
    {
        $number = new FakeCustomColumn('revenue', 'integer');
        $date = new FakeCustomColumn('order_date', 'date');

        $this->assertSame('range', FilterValue::style($number, 'range'), 'a continuous column can ask for from / to');
        $this->assertSame('select', FilterValue::style($date, 'select'), 'a date can ask to be picked from a list');

        foreach ([null, '', 'nonsense'] as $ignored) {
            $this->assertSame('select', FilterValue::style($number, $ignored), 'an unusable style falls back to the default');
        }
    }

    public function testStyleForKeepsATypedRangeOnItsInputs(): void
    {
        $number = new FakeCustomColumn('revenue', 'integer');
        $text = new FakeCustomColumn('grade', 'text');
        $range = FilterValue::parse(['from' => '1000']);

        $this->assertSame('range', FilterValue::styleFor($number, null, $range), 'a from / to typed while the list was capped stays a from / to when the list is not');
        $this->assertSame('select', FilterValue::styleFor($number, null, FilterValue::parse('1000')), 'a picked value keeps the list');
        $this->assertSame('select', FilterValue::styleFor($number, null, null), 'nothing selected: the column default');
        $this->assertSame('range', FilterValue::styleFor($number, 'range', null), 'the configured style');
        $this->assertSame('select', FilterValue::styleFor($text, null, $range), 'a text column never compares: no control for a stale range (and no filter, DashboardFilter::columnsFor)');
    }

    public function testFormatBoundShowsTheDataEndAsTheInputDoes(): void
    {
        // MIN / MAX of a DECIMAL cast come back with trailing zeros: shown like a typed number
        $this->assertSame('72', FilterValue::formatBound('72.0000', 'number'));
        $this->assertSame('1.5', FilterValue::formatBound('1.5000', 'number'));
        $this->assertSame('72', FilterValue::formatBound('72.00000000', 'number'));
        $this->assertSame('0.12345678', FilterValue::formatBound('0.12345678', 'number'), 'every decimal a column may store');
        $this->assertSame('1001', FilterValue::formatBound(1001, 'number'));
        $this->assertSame('0', FilterValue::formatBound('0.0000', 'number'));
        $this->assertSame('-3.25', FilterValue::formatBound('-3.2500', 'number'));
        $this->assertSame('', FilterValue::formatBound('abc', 'number'));
        $this->assertSame('', FilterValue::formatBound(null, 'number'), 'no rows: no end');
        $this->assertSame('', FilterValue::formatBound('', 'date'));
        $this->assertSame('2026-01-05', FilterValue::formatBound('2026-01-05 13:45:00', 'datetime'), 'the date input takes a day');
        $this->assertSame('2026-01-05', FilterValue::formatBound('2026-01-05', 'date'));
        $this->assertSame('', FilterValue::formatBound('yesterday', 'date'));
        $this->assertSame('x', FilterValue::formatBound('x', 'text'));
    }

    public function testCompareExprCastsNumbersOnly(): void
    {
        $this->assertSame('CAST(' . $this->wrap('exm__fake.value->score') . ' AS DECIMAL(22,8))', FilterValue::compareExpr(new FakeCustomColumn('score', 'integer')), 'so MIN / MAX and a range compare "72" before "1001", 14 digits and 8 decimals unrounded');
        $this->assertSame($this->wrap('exm__fake.value->d'), FilterValue::compareExpr(new FakeCustomColumn('d', 'date')), 'ISO dates order as text');
    }

    public function testNumberCastOnSqlServerReadsAnInvalidNumberAsNull(): void
    {
        // SQL Server fails the whole query on a CAST of a text that is no number
        /** @var \Mockery\Expectation $sqlServer */
        $sqlServer = \Exment::partialMock()->shouldReceive('isSqlServer');
        $sqlServer->andReturn(true);
        $score = $this->wrap('exm__fake.value->score');
        $this->assertSame("TRY_CAST({$score} AS DECIMAL(22,8))", FilterValue::compareExpr(new FakeCustomColumn('score', 'integer')));

        $q = new FakeQuery();
        FilterValue::applyExpr($q, $score, ['from' => '80', 'to' => '100'], 'number');
        $this->assertContains("TRY_CAST({$score} AS DECIMAL(22,8)) >= ? [\"80\"]", $q->sql());
        $this->assertContains("TRY_CAST({$score} AS DECIMAL(22,8)) <= ? [\"100\"]", $q->sql());
    }

    public function testColumnExprIsQualifiedWithTheColumnsTableInTheConnectionsGrammar(): void
    {
        // the box query may JOIN other value tables (a select_table's master), which carry a
        // `value` column too: an unqualified `value` is ambiguous there
        $this->assertSame($this->wrap('exm__fake.column_score'), FilterValue::columnExpr(new FakeCustomColumn('score', 'integer', true)), 'an index column');
        $this->assertSame($this->wrap('exm__fake.value->score'), FilterValue::columnExpr(new FakeCustomColumn('score', 'integer')), "a JSON value, in the database's own JSON syntax");
    }

    public function testApplyOnIndexedColumn(): void
    {
        $column = new FakeCustomColumn('grade', 'select_table', true);
        $q = new FakeQuery();
        FilterValue::apply($q, $column, ['in' => ['1']]);
        FilterValue::apply($q, $column, ['in' => ['1', '2']]);
        $this->assertSame(['exm__fake.column_grade = "1"', 'exm__fake.column_grade IN ["1","2"]'], $q->sql());
    }

    public function testApplyOnJsonColumn(): void
    {
        $column = new FakeCustomColumn('grade', 'select_table', false);
        $q = new FakeQuery();
        FilterValue::apply($q, $column, ['in' => ['1']]);
        FilterValue::apply($q, $column, ['in' => ['1', '2']]);
        $expr = $this->wrap('exm__fake.value->grade');
        $this->assertSame([
            $expr . ' = ? ["1"]',
            $expr . ' IN (?,?) ["1","2"]',
        ], $q->sql());
    }

    public function testMultipleNeedsTheTypeAndTheOption(): void
    {
        $this->assertTrue(FilterValue::isMultiple($this->multiple('select')));
        $this->assertTrue(FilterValue::isMultiple($this->multiple('select_table')));
        $this->assertTrue(FilterValue::isMultiple($this->multiple('user')));
        $this->assertFalse(FilterValue::isMultiple(new FakeCustomColumn('grade', 'select')), 'multiple_enabled off');
        $this->assertFalse(FilterValue::isMultiple($this->multiple('text')), 'a type that is never multiple');
        $this->assertFalse(FilterValue::isMultiple(null));
    }

    public function testListItemsReadTheStoredArray(): void
    {
        $this->assertSame(['1', '2'], FilterValue::listItems('["1", "2"]'));
        $this->assertSame(['1', '2'], FilterValue::listItems('[1,2,1]'), 'numbers, once each');
        $this->assertSame(['1'], FilterValue::listItems('["1"]'));
        $this->assertSame(['5'], FilterValue::listItems('5'), 'a value stored before the column turned multiple');
        $this->assertSame(['a b'], FilterValue::listItems('["a b", "", null]'), 'empty items dropped');
        $this->assertSame([], FilterValue::listItems('[]'));
    }

    public function testApplyOnMultipleColumnSearchesTheList(): void
    {
        foreach ([true, false] as $indexed) {
            $column = $this->multiple('select_table', $indexed);
            $q = new FakeQuery();
            FilterValue::apply($q, $column, ['in' => ['1']]);
            FilterValue::apply($q, $column, ['in' => ['1', 'a b']]);
            $list = "REPLACE(REPLACE(REPLACE(REPLACE(" . FilterValue::columnExpr($column) . ", '[', ''), ' ', ''), ']', ''), '\"', '')";
            $this->assertSame([
                "(FIND_IN_SET(?, {$list}) > 0) [\"1\"]",
                "(FIND_IN_SET(?, {$list}) > 0 OR FIND_IN_SET(?, {$list}) > 0) [\"1\",\"ab\"]",
            ], $q->sql(), $indexed ? 'an index column holds the list too' : 'the JSON value');
        }
    }

    public function testApplyOnMultipleColumnOnSqlServerSplitsTheList(): void
    {
        /** @var \Mockery\Expectation $sqlServer */
        $sqlServer = \Exment::partialMock()->shouldReceive('isSqlServer');
        $sqlServer->andReturn(true);
        $q = new FakeQuery();
        FilterValue::apply($q, $this->multiple('select_table', true), ['in' => ['1', '2']]);
        $list = "REPLACE(REPLACE(REPLACE(REPLACE(" . $this->wrap('exm__fake.column_grade') . ", '[', ''), ' ', ''), ']', ''), '\"', '')";
        $this->assertSame(["EXISTS (SELECT 1 FROM STRING_SPLIT({$list}, ',') AS list_items WHERE list_items.value IN (?,?)) [\"1\",\"2\"]"], $q->sql());
    }

    public function testRangeOnMultipleColumnIsUnchanged(): void
    {
        $q = new FakeQuery();
        FilterValue::apply($q, $this->multiple('select'), ['from' => 'a', 'to' => 'b']);
        $expr = $this->wrap('exm__fake.value->grade');
        $this->assertSame(["{$expr} IS NOT NULL AND {$expr} <> ''", "{$expr} >= ? [\"a\"]", "{$expr} <= ? [\"b\"]"], $q->sql());
    }

    public function testNumberRangeCastsAndIgnoresInvalidBounds(): void
    {
        $column = new FakeCustomColumn('score', 'integer', true);
        $q = new FakeQuery();
        FilterValue::apply($q, $column, ['from' => '80', 'to' => 'abc']);
        $score = $this->wrap('exm__fake.column_score');
        $this->assertSame([
            "{$score} IS NOT NULL AND {$score} <> ''",
            "CAST({$score} AS DECIMAL(22,8)) >= ? [\"80\"]",
        ], $q->sql());

        $q = new FakeQuery();
        FilterValue::apply($q, $column, ['from' => 'x', 'to' => 'y']);
        $this->assertSame([], $q->sql(), 'both bounds invalid = no filter rather than a wrong one');
    }

    public function testDateRangeIncludesWholeLastDayOnDatetime(): void
    {
        $q = new FakeQuery();
        FilterValue::apply($q, new FakeCustomColumn('d', 'datetime', true), ['from' => '2026-01-01', 'to' => '2026-01-31T10:00']);
        $d = $this->wrap('exm__fake.column_d');
        $this->assertSame([
            "{$d} IS NOT NULL AND {$d} <> ''",
            "{$d} >= ? [\"2026-01-01\"]",
            "{$d} <= ? [\"2026-01-31 23:59:59\"]",
        ], $q->sql());

        $q = new FakeQuery();
        FilterValue::apply($q, new FakeCustomColumn('d', 'date', true), ['from' => null, 'to' => '2026-01-31']);
        $this->assertSame([
            "{$d} IS NOT NULL AND {$d} <> ''",
            "{$d} <= ? [\"2026-01-31\"]",
        ], $q->sql());
    }

    public function testTokenIsOrderIndependent(): void
    {
        $this->assertSame(FilterValue::token(['in' => ['b', 'a']]), FilterValue::token(['in' => ['a', 'b']]));
        $this->assertNotSame(FilterValue::token(['in' => ['a']]), FilterValue::token(['from' => 'a', 'to' => null]));
    }

    public function testValuesForDisplay(): void
    {
        $this->assertSame(['a', 'b'], FilterValue::values(['in' => ['a', 'b']]));
        $this->assertSame(['1 – 9'], FilterValue::values(['from' => '1', 'to' => '9']));
        $this->assertSame(['– 9'], FilterValue::values(['from' => null, 'to' => '9']));
    }

    private function multiple(string $type, bool $indexed = false): FakeCustomColumn
    {
        $column = new FakeCustomColumn('grade', $type, $indexed);
        $column->options = ['multiple_enabled' => '1'];
        return $column;
    }
}
