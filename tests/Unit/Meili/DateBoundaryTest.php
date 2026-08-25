<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\GlobalSearch\RequestFilters;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Upper bounds of a date filter must cover the whole day. A datetime column
 * indexes the real time of day, so an upper bound at midnight silently drops
 * every record of the day the user picked.
 */
class DateBoundaryTest extends TestCase
{
    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function parse(array $query): array
    {
        return RequestFilters::parse(Request::create('/admin/search', 'GET', $query));
    }

    public function testRangeUpperBoundCoversTheWholeDay(): void
    {
        $f = $this->parse(['range' => ['n_t::dt' => ['to' => '2025-01-15']]]);

        $this->assertSame(
            strtotime('2025-01-15 23:59:59'),
            $f['ranges']['n_t::dt']['to'],
            'A record at 2025-01-15 14:30 must stay inside "to 2025-01-15".'
        );
    }

    public function testRangeLowerBoundStartsAtMidnight(): void
    {
        $f = $this->parse(['range' => ['n_t::dt' => ['from' => '2025-01-15']]]);

        $this->assertSame(strtotime('2025-01-15 00:00:00'), $f['ranges']['n_t::dt']['from']);
    }

    public function testNumericRangeIsUntouched(): void
    {
        $f = $this->parse(['range' => ['n_t::amount' => ['from' => '10', 'to' => '99.5']]]);

        $this->assertSame(10, $f['ranges']['n_t::amount']['from']);
        $this->assertSame(99.5, $f['ranges']['n_t::amount']['to']);
    }

    public function testCreatedDateBoundsCoverTheWholeDay(): void
    {
        $f = $this->parse(['date_from' => '2025-01-01', 'date_to' => '2025-01-31']);

        $this->assertSame(strtotime('2025-01-01 00:00:00'), $f['date_from']);
        $this->assertSame(strtotime('2025-01-31 23:59:59'), $f['date_to']);
    }

    /**
     * Appending a time to a value that already has one made strtotime fail, and
     * the filter then vanished instead of being applied.
     */
    public function testValueThatAlreadyCarriesATimeIsKept(): void
    {
        $f = $this->parse(['date_to' => '2025-01-01 10:00:00']);

        $this->assertArrayHasKey('date_to', $f);
        $this->assertSame(strtotime('2025-01-01 10:00:00'), $f['date_to']);
    }

    public function testUnparseableDateIsDropped(): void
    {
        $this->assertArrayNotHasKey('date_to', $this->parse(['date_to' => 'not-a-date']));
    }
}
