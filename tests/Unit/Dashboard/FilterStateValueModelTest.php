<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomColumn;
use Exceedone\Exment\Services\Dashboard\FilterState;

/**
 * FilterState — the request-side value model shared by the dashboard filter (df_*) and
 * the chart-level filter (bf_*): how one request param becomes a filter spec, how a spec
 * is summarised (single / values), how it is fingerprinted for the AI cache, and which
 * columns count as "active" for the not-affected badge. Pure request/array logic — no DB.
 */
class FilterStateValueModelTest extends DashboardUnitTestCase
{
    // ---- spec(): one param, three shapes -------------------------------------

    public function testScalarBecomesSingleValueIn(): void
    {
        $this->assertSame(['in' => ['12']], FilterState::spec('12'));
        $this->assertSame(['in' => ['12']], FilterState::spec(12));
        $this->assertSame(['in' => ['0']], FilterState::spec('0'), '"0" is a real value, not empty');
    }

    public function testEmptyAndJunkCarryNoFilter(): void
    {
        $this->assertNull(FilterState::spec(null));
        $this->assertNull(FilterState::spec(''));
        $this->assertNull(FilterState::spec([]));
        $this->assertNull(FilterState::spec(['']), 'list of blanks');
        $this->assertNull(FilterState::spec(['x' => 'y']), 'assoc key the bar never emits');
        $this->assertNull(FilterState::spec(['from' => 'a', 'junk' => 'b']), 'mixed range + junk key');
        $this->assertNull(FilterState::spec(['from' => '', 'to' => '  ']), 'range with both sides blank');
        $this->assertNull(FilterState::spec([['nested']]), 'non-scalar list items are skipped');
        $this->assertNull(FilterState::spec(new \stdClass()));
    }

    public function testListBecomesIn(): void
    {
        $this->assertSame(['in' => ['a', 'b']], FilterState::spec(['a', 'b']));
        // '' dropped, de-duplicated, ORDER KEPT (the bar's chip order)
        $this->assertSame(['in' => ['b', 'a']], FilterState::spec(['b', '', 'a', 'b', 'a']));
        // ints become strings (query strings are strings; keeps == semantics stable)
        $this->assertSame(['in' => ['1', '2']], FilterState::spec([1, 2]));
        // integer keys need not be contiguous (df_col[3]=x)
        $this->assertSame(['in' => ['x']], FilterState::spec([3 => 'x']));
    }

    public function testRangeShapes(): void
    {
        $this->assertSame(['from' => '10', 'to' => '20'], FilterState::spec(['from' => '10', 'to' => '20']));
        $this->assertSame(['from' => '10', 'to' => null], FilterState::spec(['from' => ' 10 ']), 'trimmed, open upper bound');
        $this->assertSame(['from' => null, 'to' => '20'], FilterState::spec(['to' => '20']));
        $this->assertSame(['from' => null, 'to' => '2026-01-01'], FilterState::spec(['from' => '', 'to' => '2026-01-01']));
    }

    /** columnsOn()/boxFilters() hand specs back to where(); spec() must accept its own output. */
    public function testSpecIsIdempotent(): void
    {
        foreach ([['in' => ['a']], ['in' => ['a', 'b']], ['from' => '1', 'to' => null], ['from' => null, 'to' => '9']] as $s) {
            $this->assertSame($s, FilterState::spec($s), json_encode($s));
        }
        // and the compact string form
        $this->assertSame(['in' => ['a']], FilterState::spec(FilterState::single('a')));
    }

    // ---- single() / values() --------------------------------------------------

    public function testSingleOnlyForExactlyOneEqualityValue(): void
    {
        $this->assertSame('a', FilterState::single('a'));
        $this->assertSame('a', FilterState::single(['a']));
        $this->assertSame('a', FilterState::single(['a', 'a', '']), 'dedupe → still one value');
        $this->assertNull(FilterState::single(['a', 'b']), 'two values is not "selected" in the single sense');
        $this->assertNull(FilterState::single(['from' => '1', 'to' => '2']));
        $this->assertNull(FilterState::single(null));
        $this->assertNull(FilterState::single(''));
    }

    public function testValuesForLabels(): void
    {
        $this->assertSame(['a', 'b'], FilterState::values(['a', 'b']));
        $this->assertSame(['a'], FilterState::values('a'));
        $this->assertSame(['1 – 9'], FilterState::values(['from' => '1', 'to' => '9']));
        $this->assertSame(['1 –'], FilterState::values(['from' => '1']));
        $this->assertSame(['– 9'], FilterState::values(['to' => '9']));
        $this->assertSame([], FilterState::values(null));
    }

    // ---- kind() / style(): which control + how ranges compare -----------------

    public function testKindByColumnType(): void
    {
        $this->assertSame('number', FilterState::kind(new FakeCustomColumn('c', 'integer')));
        $this->assertSame('number', FilterState::kind(new FakeCustomColumn('c', 'decimal')));
        $this->assertSame('number', FilterState::kind(new FakeCustomColumn('c', 'currency')));
        $this->assertSame('date', FilterState::kind(new FakeCustomColumn('c', 'date')));
        $this->assertSame('datetime', FilterState::kind(new FakeCustomColumn('c', 'datetime')));
        $this->assertSame('text', FilterState::kind(new FakeCustomColumn('c', 'text')));
        $this->assertSame('text', FilterState::kind(new FakeCustomColumn('c', 'select_table')));
        $this->assertSame('text', FilterState::kind(new FakeCustomColumn('c', 'time')), 'time is not a date range');
        $this->assertSame('text', FilterState::kind(null));
    }

    public function testStyleAutoAndOverride(): void
    {
        $num = new FakeCustomColumn('c', 'integer');
        $txt = new FakeCustomColumn('c', 'select');
        $this->assertSame('range', FilterState::style($num));
        $this->assertSame('select', FilterState::style($txt));
        $this->assertSame('select', FilterState::style($num, 'select'), 'admin override wins');
        $this->assertSame('range', FilterState::style($txt, 'range'));
        $this->assertSame('range', FilterState::style($num, 'junk'), 'unknown override = auto');
        $this->assertSame('select', FilterState::style(null));
    }

    // ---- raw() / fingerprint(): AI-cache identity of the filter state -----------

    public function testRawCollectsOnlyDfParamsWithValues(): void
    {
        $this->swapRequest(['df_region' => '3', 'df_empty' => '', 'bf_x' => '1', 'ct' => 'line', 'other' => 'y']);
        $this->assertSame(['df_region' => '3'], FilterState::raw());
    }

    public function testRawTokensAreOrderIndependentForLists(): void
    {
        $this->swapRequest(['df_region' => ['b', 'a']]);
        $ab = FilterState::raw();
        $this->swapRequest(['df_region' => ['a', 'b']]);
        $ba = FilterState::raw();
        $this->assertSame($ab, $ba, 'same rows → same cache key');
        $this->assertSame(['df_region' => json_encode(['in' => ['a', 'b']])], $ab);
    }

    public function testRawSingleElementListEqualsLegacyToken(): void
    {
        $this->swapRequest(['df_region' => ['3']]);
        $this->assertSame(['df_region' => '3'], FilterState::raw(), 'one-element list must not invalidate legacy cache entries');
    }

    public function testRawRangeToken(): void
    {
        $this->swapRequest(['df_score' => ['from' => '80', 'to' => '']]);
        $this->assertSame(['df_score' => json_encode(['from' => '80', 'to' => null])], FilterState::raw());
    }

    public function testFingerprintIsStableAndEmptyWhenNoFilter(): void
    {
        $this->swapRequest([]);
        $this->assertSame('', FilterState::fingerprint());

        $this->swapRequest(['df_b' => '2', 'df_a' => '1']);
        $f1 = FilterState::fingerprint();
        $this->swapRequest(['df_a' => '1', 'df_b' => '2']);
        $this->assertSame($f1, FilterState::fingerprint(), 'param order does not matter');
        $this->assertSame(32, strlen($f1));

        // exact port of the original fingerprint: md5 of the ksorted json
        $this->assertSame(md5(json_encode(['df_a' => '1', 'df_b' => '2'])), $f1);
    }

    /** raw() has NO identifier guard on purpose (a rejected-later param must still change the key). */
    public function testRawKeepsNonIdentifierParamsButActiveColumnsDrops(): void
    {
        $this->swapRequest(['df_bad-name' => '1', 'df_ok' => '2']);
        $this->assertSame(['df_bad-name' => '1', 'df_ok' => '2'], FilterState::raw());
        $this->assertSame(['ok'], FilterState::activeColumns());
    }

    // ---- activeColumns(): what the badge counts as "active" ----------------------

    public function testActiveColumnsInRequestOrder(): void
    {
        $this->swapRequest(['df_z' => '1', 'df_a' => ['x', 'y'], 'df_m' => ['from' => '1'], 'df_none' => '', 'df_' => '1', 'bf_a' => '9']);
        $this->assertSame(['z', 'a', 'm'], FilterState::activeColumns());
    }

    public function testActiveColumnsEmptyWithoutFilters(): void
    {
        $this->swapRequest(['ct' => 'line', 'bf_region' => '1']);
        $this->assertSame([], FilterState::activeColumns(), 'a chart-level filter is not a dashboard filter');
    }
}
