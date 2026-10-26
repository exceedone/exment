<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\GlobalSearch\HeaderSuggester;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

/**
 * HighlightMarkerSpoofTest covers the decision "which attribute matched".
 * This one covers the other half: rendering the snippet.
 *
 * toHighlightedHtml() escapes the snippet and only then turns the markers into
 * <mark>. Escaping first is what keeps record content from becoming html - but
 * it does nothing for the markers themselves, because they are deliberately
 * made of characters e() leaves alone. So a fixed marker string typed into a
 * record survives the escape and is converted into a real tag: the record
 * decides where the keyword looks highlighted.
 */
class HighlightMarkerRenderTest extends TestCase
{
    /** What an attacker can type: the marker as it appears in the source. */
    private const GUESSED_PRE = '__EXM_HL__';
    private const GUESSED_POST = '__/EXM_HL__';

    public function testTheMarkerMeilisearchInsertedBecomesAMarkTag(): void
    {
        $snippet = 'Amazon ' . MeiliSearchService::highlightPre() . 'AMZ' . MeiliSearchService::highlightPost() . ' kit';

        $this->assertSame('Amazon <mark>AMZ</mark> kit', HeaderSuggester::toHighlightedHtml($snippet));
    }

    public function testAMarkerTypedIntoTheRecordDoesNotBecomeAMarkTag(): void
    {
        // TC-37 payload 3: the record's own label carries the marker string.
        $snippet = 'PREFIX' . self::GUESSED_PRE . 'INJECT' . self::GUESSED_POST;

        $html = HeaderSuggester::toHighlightedHtml($snippet);

        $this->assertStringNotContainsString('<mark>', $html, 'record content must not be able to open a mark tag');
        $this->assertStringNotContainsString('</mark>', $html);
        $this->assertStringContainsString('PREFIX', $html);
        $this->assertStringContainsString('INJECT', $html);
    }

    /**
     * The real marker is unguessable, so a record carrying the guessable one
     * cannot forge emphasis even while a genuine highlight is rendered.
     */
    public function testRecordMarkerStaysTextNextToARealHighlight(): void
    {
        $snippet = self::GUESSED_PRE . 'fake' . self::GUESSED_POST
            . ' ' . MeiliSearchService::highlightPre() . 'real' . MeiliSearchService::highlightPost();

        $html = HeaderSuggester::toHighlightedHtml($snippet);

        $this->assertSame(1, substr_count($html, '<mark>'), 'only Meilisearch may open a mark tag');
        $this->assertStringContainsString('<mark>real</mark>', $html);
        $this->assertStringContainsString('fake', $html);
    }

    public function testTheMarkerIsNotPredictableFromTheSource(): void
    {
        $pre = MeiliSearchService::highlightPre();
        $post = MeiliSearchService::highlightPost();

        $this->assertNotSame(self::GUESSED_PRE, $pre);
        $this->assertNotSame(self::GUESSED_POST, $post);
        // Still free of the characters e() escapes, so escaping cannot damage it.
        $this->assertSame($pre, e($pre));
        $this->assertSame($post, e($post));
        // Stable within one request: the search and the rendering must agree.
        $this->assertSame($pre, MeiliSearchService::highlightPre());
        $this->assertSame($post, MeiliSearchService::highlightPost());
    }

    /** TC-37 payloads 1 and 2: html in record content stays text. */
    public function testHtmlInRecordContentStaysText(): void
    {
        foreach (['<script>alert(1)</script>', '<img src=x onerror=alert(1)>'] as $payload) {
            $html = HeaderSuggester::toHighlightedHtml($payload);

            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('<img', $html);
            $this->assertStringContainsString('&lt;', $html);
        }
    }
}
