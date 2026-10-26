<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\GlobalSearch\HeaderSuggester;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

/**
 * The header suggester decides whether a hit may be shown by asking which
 * field the keyword matched: a hit whose only match is in a linked column the
 * viewer may not read is dropped, or the suggestions would answer "does a
 * record by this name exist?" about a table they cannot open.
 *
 * Reading that off the highlight markers would make the answer forgeable if the
 * markers were a fixed string: anyone who can write into a field they CAN read
 * would put the string there and the hit would pass as readable. Two things stop
 * that - _matchesPosition is the real source of truth, and the markers carry a
 * per-process nonce the record cannot know.
 */
class HighlightMarkerSpoofTest extends TestCase
{
    /** The marker as someone reading the source would write it into a record. */
    private const GUESSED_PRE = '__EXM_HL__';
    private const GUESSED_POST = '__/EXM_HL__';

    /** The markers carry a per-process nonce, so they cannot be class constants. */
    private function pre(): string
    {
        return MeiliSearchService::highlightPre();
    }

    private function post(): string
    {
        return MeiliSearchService::highlightPost();
    }

    public function testTheMatchedAttributeComesFromMeilisearch(): void
    {
        $matches = ['fields.torihikisaki' => [['start' => 4, 'length' => 6]]];

        $this->assertTrue(HeaderSuggester::attributeMatched($matches, 'fields.torihikisaki', $this->pre() . 'x' . $this->post()));
        $this->assertFalse(HeaderSuggester::attributeMatched($matches, 'fields.note', 'nothing here'));
    }

    public function testAMarkerTypedIntoTheRecordIsNotAMatch(): void
    {
        // The keyword really matched the hidden linked column; the viewer wrote
        // the marker into a column they may read.
        $matches = ['fields.torihikisaki' => [['start' => 4, 'length' => 6]]];

        $this->assertFalse(
            HeaderSuggester::attributeMatched($matches, 'fields.note', 'harmless ' . self::GUESSED_PRE . ' text'),
            'a marker inside the record content must not count as a match'
        );
    }

    /**
     * A payload with no _matchesPosition at all (another caller, an older
     * cached response) still has to decide something, and the markers are all
     * there is.
     */
    public function testWithoutMatchPositionsTheMarkersStillAnswer(): void
    {
        $this->assertTrue(HeaderSuggester::attributeMatched([], 'fields.note', $this->pre() . 'hit' . $this->post()));
        $this->assertFalse(HeaderSuggester::attributeMatched([], 'fields.note', 'no marker'));
        // Even on this fallback the record cannot forge a match: the real marker
        // carries a nonce it cannot know.
        $this->assertFalse(
            HeaderSuggester::attributeMatched([], 'fields.note', self::GUESSED_PRE . 'hit' . self::GUESSED_POST),
            'the marker a record can guess must not answer the fallback either'
        );
    }

    public function testSearchHighlightedAsksForTheMatchPositions(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Services/Meili/MeiliSearchService.php');

        $this->assertStringContainsString("'showMatchesPosition' => true", $source);
        $this->assertStringContainsString("'matches' => (array) (\$hit['_matchesPosition'] ?? [])", $source);
    }
}
