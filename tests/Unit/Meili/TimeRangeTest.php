<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\GlobalSearch\RequestFilters;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * A time column carries a clock time and no date, but it was indexed through
 * strtotime(): "10:30:00" resolved against whatever day the record happened to
 * be indexed on. Two consequences, both silent:
 *
 *  - the same clock time got a different number on every reindex, so a range
 *    filter matched only the records indexed on the same day as the query;
 *  - the sidebar rendered a number box for the column, and a browser will not
 *    let the user type "10:30" into <input type="number"> at all.
 *
 * Both sides now agree on seconds since midnight, the only stable comparable
 * number for a time of day.
 *
 * NOTE: this changes what gets written into the index. Any table with a time
 * range filter configured must be reindexed (php artisan exment:meili-index)
 * for the filter to work - the previously indexed values were day-dependent
 * garbage, so nothing usable is lost.
 */
class TimeRangeTest extends TestCase
{
    public function testTimeIsIndexedAsSecondsSinceMidnight(): void
    {
        $this->assertSame(0, DocumentMapper::rangeValue('00:00:00', 'time'));
        $this->assertSame(37800, DocumentMapper::rangeValue('10:30:00', 'time'));
        $this->assertSame(37800, DocumentMapper::rangeValue('10:30', 'time'), 'seconds are optional');
        $this->assertSame(86399, DocumentMapper::rangeValue('23:59:59', 'time'));
    }

    /**
     * The regression itself: the indexed number must not depend on the day the
     * indexer ran.
     */
    public function testIndexedTimeDoesNotDependOnTheCurrentDate(): void
    {
        $this->assertLessThan(
            86400,
            DocumentMapper::rangeValue('10:30:00', 'time'),
            'a time of day was indexed as a unix timestamp, so it changed on every reindex'
        );
    }

    /**
     * The value can arrive already cast to a date object (DatabaseDataType::TYPE_TIME).
     */
    public function testDateTimeObjectIsReadAsATimeOfDay(): void
    {
        $this->assertSame(
            37800,
            DocumentMapper::rangeValue(new \DateTimeImmutable('2020-02-02 10:30:00'), 'time')
        );
    }

    public function testAnImpossibleTimeIndexesNothing(): void
    {
        $this->assertNull(DocumentMapper::rangeValue('25:00', 'time'));
        $this->assertNull(DocumentMapper::rangeValue('10:70', 'time'));
        $this->assertNull(DocumentMapper::rangeValue('not-a-time', 'time'));
        $this->assertNull(DocumentMapper::timeOfDaySeconds('2025-01-15'));
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function parse(array $query): array
    {
        return RequestFilters::parse(Request::create('/admin/search', 'GET', $query));
    }

    public function testRequestBoundsUseTheSameUnitAsTheIndex(): void
    {
        $f = $this->parse(['range' => ['n_shift::start' => ['from' => '09:00', 'to' => '17:00']]]);

        $this->assertSame(9 * 3600, $f['ranges']['n_shift::start']['from']);
        // "to 17:00" covers 17:00:00-17:00:59, the same way a date bound covers
        // the whole day (see DateBoundaryTest).
        $this->assertSame(17 * 3600 + 59, $f['ranges']['n_shift::start']['to']);
    }

    public function testAnUpperBoundGivenToTheSecondIsNotWidened(): void
    {
        $f = $this->parse(['range' => ['n_shift::start' => ['to' => '17:00:30']]]);

        $this->assertSame(17 * 3600 + 30, $f['ranges']['n_shift::start']['to']);
    }

    /**
     * Date columns and the created_at filter must keep their unix timestamps -
     * only the range boxes learned about times of day.
     */
    public function testDateBoundsAreStillUnixTimestamps(): void
    {
        $f = $this->parse([
            'range' => ['n_t::dt' => ['from' => '2025-01-15']],
            'date_to' => '2025-01-31',
        ]);

        $this->assertSame(strtotime('2025-01-15 00:00:00'), $f['ranges']['n_t::dt']['from']);
        $this->assertSame(strtotime('2025-01-31 23:59:59'), $f['date_to']);
    }

    public function testSidebarRendersATimePickerForATimeColumn(): void
    {
        $this->assertSame('time', DocumentMapper::rangeInputType('time'));
        $this->assertSame('date', DocumentMapper::rangeInputType('date'));
        $this->assertSame('date', DocumentMapper::rangeInputType('datetime'));
        $this->assertSame('number', DocumentMapper::rangeInputType('integer'));
        $this->assertSame('number', DocumentMapper::rangeInputType('decimal'));
        $this->assertSame('number', DocumentMapper::rangeInputType('currency'));
    }
}
