<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\GlobalSearch\HeaderSuggester;
use PHPUnit\Framework\TestCase;

/**
 * The header read LIMIT * OVER_FETCH hits once and then dropped the ones the
 * viewer may not be shown, so a viewer whose early hits are mostly hidden got
 * fewer than LIMIT suggestions while further matching records sat just outside
 * the window (reproduced on real data: 7 suggestions where 8 were available).
 */
class HeaderSuggestWideningTest extends TestCase
{
    public function testAFullPageOfSuggestionsStops(): void
    {
        $this->assertFalse(HeaderSuggester::needsWiderFetch(HeaderSuggester::LIMIT, 40, 40));
    }

    /**
     * Fewer hits came back than were asked for: Meilisearch has no more to
     * give, and a wider read would return the same hits again.
     */
    public function testAnExhaustedIndexStops(): void
    {
        $this->assertFalse(HeaderSuggester::needsWiderFetch(2, 17, 40));
    }

    public function testAShortPageOverAFullWindowWidens(): void
    {
        $this->assertTrue(HeaderSuggester::needsWiderFetch(7, 40, 40));
        $this->assertTrue(HeaderSuggester::needsWiderFetch(0, 160, 160));
    }

    /** One keystroke must not be able to walk the whole index. */
    public function testTheCeilingStops(): void
    {
        $this->assertFalse(HeaderSuggester::needsWiderFetch(
            0,
            HeaderSuggester::MAX_FETCH,
            HeaderSuggester::MAX_FETCH
        ));
    }

    public function testTheReadWidensAndThenClampsToTheCeiling(): void
    {
        $this->assertSame(160, HeaderSuggester::nextFetch(40));
        $this->assertSame(400, HeaderSuggester::nextFetch(160));
        $this->assertSame(HeaderSuggester::MAX_FETCH, HeaderSuggester::nextFetch(HeaderSuggester::MAX_FETCH));
    }

    /** At most three reads for one keystroke: 40 -> 160 -> 400. */
    public function testTheWidestSequenceIsThreeReads(): void
    {
        $reads = 1;
        for ($fetch = HeaderSuggester::LIMIT * HeaderSuggester::OVER_FETCH;
             HeaderSuggester::needsWiderFetch(0, $fetch, $fetch);
             $fetch = HeaderSuggester::nextFetch($fetch)) {
            $reads++;
            $this->assertLessThan(10, $reads, 'the widening loop must terminate');
        }

        $this->assertSame(3, $reads);
    }
}
