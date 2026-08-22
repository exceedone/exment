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

        $this->assertSame('range', FilterValue::style(new FakeCustomColumn('n', 'integer')));
        $this->assertSame('range', FilterValue::style(new FakeCustomColumn('d', 'date')));
        $this->assertSame('select', FilterValue::style(new FakeCustomColumn('s', 'select')));
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
