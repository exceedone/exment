<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Services\Dashboard\FilterValue;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomColumn;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeQuery;

class FilterValueTest extends DashboardUnitTestCase
{
    public function testParseShapes()
    {
        $this->assertSame(['in' => ['v']], FilterValue::parse('v'));
        $this->assertSame(['in' => ['v']], FilterValue::parse(' v '));
        $this->assertSame(['in' => ['a', 'b']], FilterValue::parse(['a', 'b', '', 'a']));
        $this->assertSame(['from' => '1', 'to' => '9'], FilterValue::parse(['from' => '1', 'to' => '9']));
        $this->assertSame(['from' => null, 'to' => '9'], FilterValue::parse(['from' => '', 'to' => '9']));
    }

    public function testParseRejectsEmptyAndJunk()
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

    public function testIdentifier()
    {
        $this->assertTrue(FilterValue::isIdentifier('grade_1'));
        $this->assertFalse(FilterValue::isIdentifier('grade-1'));
        $this->assertFalse(FilterValue::isIdentifier("grade\n"));
        $this->assertFalse(FilterValue::isIdentifier(''));
        $this->assertFalse(FilterValue::isIdentifier(null));
    }

    public function testKindAndStyleFollowColumnType()
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

    public function testConfiguredStyleOverridesTheColumnDefault()
    {
        $number = new FakeCustomColumn('revenue', 'integer');
        $date = new FakeCustomColumn('order_date', 'date');

        $this->assertSame('range', FilterValue::style($number, 'range'), 'a continuous column can ask for from / to');
        $this->assertSame('select', FilterValue::style($date, 'select'), 'a date can ask to be picked from a list');

        foreach ([null, '', 'nonsense'] as $ignored) {
            $this->assertSame('select', FilterValue::style($number, $ignored), 'an unusable style falls back to the default');
        }
    }

    public function testStyleForKeepsATypedRangeOnItsInputs()
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

    public function testFormatBoundShowsTheDataEndAsTheInputDoes()
    {
        // MIN / MAX of a DECIMAL cast come back with four decimals: shown like a typed number
        $this->assertSame('72', FilterValue::formatBound('72.0000', 'number'));
        $this->assertSame('1.5', FilterValue::formatBound('1.5000', 'number'));
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

    public function testCompareExprCastsNumbersOnly()
    {
        $this->assertSame('CAST(JSON_UNQUOTE(JSON_EXTRACT(`value`, \'$."score"\')) AS DECIMAL(20,4))', FilterValue::compareExpr(new FakeCustomColumn('score', 'integer')), 'so MIN / MAX and a range compare "72" before "1001"');
        $this->assertSame('JSON_UNQUOTE(JSON_EXTRACT(`value`, \'$."d"\'))', FilterValue::compareExpr(new FakeCustomColumn('d', 'date')), 'ISO dates order as text');
    }

    public function testApplyOnIndexedColumn()
    {
        $column = new FakeCustomColumn('grade', 'select_table', true);
        $q = new FakeQuery();
        FilterValue::apply($q, $column, ['in' => ['1']]);
        FilterValue::apply($q, $column, ['in' => ['1', '2']]);
        $this->assertSame(['column_grade = "1"', 'column_grade IN ["1","2"]'], $q->sql());
    }

    public function testApplyOnJsonColumn()
    {
        $column = new FakeCustomColumn('grade', 'select_table', false);
        $q = new FakeQuery();
        FilterValue::apply($q, $column, ['in' => ['1']]);
        FilterValue::apply($q, $column, ['in' => ['1', '2']]);
        $expr = 'JSON_UNQUOTE(JSON_EXTRACT(`value`, \'$."grade"\'))';
        $this->assertSame([
            $expr . ' = ? ["1"]',
            $expr . ' IN (?,?) ["1","2"]',
        ], $q->sql());
    }

    public function testNumberRangeCastsAndIgnoresInvalidBounds()
    {
        $column = new FakeCustomColumn('score', 'integer', true);
        $q = new FakeQuery();
        FilterValue::apply($q, $column, ['from' => '80', 'to' => 'abc']);
        $this->assertSame([
            '`column_score` IS NOT NULL AND `column_score` <> \'\'',
            'CAST(`column_score` AS DECIMAL(20,4)) >= ? ["80"]',
        ], $q->sql());

        $q = new FakeQuery();
        FilterValue::apply($q, $column, ['from' => 'x', 'to' => 'y']);
        $this->assertSame([], $q->sql(), 'both bounds invalid = no filter rather than a wrong one');
    }

    public function testDateRangeIncludesWholeLastDayOnDatetime()
    {
        $q = new FakeQuery();
        FilterValue::apply($q, new FakeCustomColumn('d', 'datetime', true), ['from' => '2026-01-01', 'to' => '2026-01-31T10:00']);
        $this->assertSame([
            '`column_d` IS NOT NULL AND `column_d` <> \'\'',
            '`column_d` >= ? ["2026-01-01"]',
            '`column_d` <= ? ["2026-01-31 23:59:59"]',
        ], $q->sql());

        $q = new FakeQuery();
        FilterValue::apply($q, new FakeCustomColumn('d', 'date', true), ['from' => null, 'to' => '2026-01-31']);
        $this->assertSame([
            '`column_d` IS NOT NULL AND `column_d` <> \'\'',
            '`column_d` <= ? ["2026-01-31"]',
        ], $q->sql());
    }

    public function testTokenIsOrderIndependent()
    {
        $this->assertSame(FilterValue::token(['in' => ['b', 'a']]), FilterValue::token(['in' => ['a', 'b']]));
        $this->assertNotSame(FilterValue::token(['in' => ['a']]), FilterValue::token(['from' => 'a', 'to' => null]));
    }

    public function testValuesForDisplay()
    {
        $this->assertSame(['a', 'b'], FilterValue::values(['in' => ['a', 'b']]));
        $this->assertSame(['1 – 9'], FilterValue::values(['from' => '1', 'to' => '9']));
        $this->assertSame(['– 9'], FilterValue::values(['from' => null, 'to' => '9']));
    }

    public function testFromRequest()
    {
        $this->swapRequest(['df_grade' => ['1', '2'], 'df_score' => ['from' => '10']]);
        $this->assertSame(['in' => ['1', '2']], FilterValue::fromRequest('df_grade'));
        $this->assertSame(['from' => '10', 'to' => null], FilterValue::fromRequest('df_score'));
        $this->assertNull(FilterValue::fromRequest('df_none'));
    }
}
