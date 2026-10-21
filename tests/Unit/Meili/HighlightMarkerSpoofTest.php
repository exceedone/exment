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
 * Reading that off the highlight markers makes the answer forgeable. The
 * markers are a fixed string, so anyone who can write into a field they CAN
 * read puts the string there and the hit passes as readable.
 */
class HighlightMarkerSpoofTest extends TestCase
{
    private const PRE = MeiliSearchService::HIGHLIGHT_PRE;
    private const POST = MeiliSearchService::HIGHLIGHT_POST;

    public function testTheMatchedAttributeComesFromMeilisearch(): void
    {
        $matches = ['fields.torihikisaki' => [['start' => 4, 'length' => 6]]];

        $this->assertTrue(HeaderSuggester::attributeMatched($matches, 'fields.torihikisaki', self::PRE . 'x' . self::POST));
        $this->assertFalse(HeaderSuggester::attributeMatched($matches, 'fields.note', 'nothing here'));
    }

    public function testAMarkerTypedIntoTheRecordIsNotAMatch(): void
    {
        // The keyword really matched the hidden linked column; the viewer wrote
        // the marker into a column they may read.
        $matches = ['fields.torihikisaki' => [['start' => 4, 'length' => 6]]];

        $this->assertFalse(
            HeaderSuggester::attributeMatched($matches, 'fields.note', 'harmless ' . self::PRE . ' text'),
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
        $this->assertTrue(HeaderSuggester::attributeMatched([], 'fields.note', self::PRE . 'hit' . self::POST));
        $this->assertFalse(HeaderSuggester::attributeMatched([], 'fields.note', 'no marker'));
    }

    public function testSearchHighlightedAsksForTheMatchPositions(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Services/Meili/MeiliSearchService.php');

        $this->assertStringContainsString("'showMatchesPosition' => true", $source);
        $this->assertStringContainsString("'matches' => (array) (\$hit['_matchesPosition'] ?? [])", $source);
    }
}
