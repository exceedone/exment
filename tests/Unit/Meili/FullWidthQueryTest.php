<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

/**
 * A Japanese IME left in full-width mode types "ＴＯＫ０２", which is a
 * different code point per character from the "TOK02" stored in the record.
 * Without folding, that keyword matched nothing while the half-width spelling
 * of the same thing matched five records.
 */
class FullWidthQueryTest extends TestCase
{
    public function testFullWidthLettersAndDigitsFoldToHalfWidth(): void
    {
        $this->assertSame('TOK02', MeiliSearchService::normalizeQuery('ＴＯＫ０２'));
        $this->assertSame('TOK', MeiliSearchService::normalizeQuery('ＴＯＫ'));
        $this->assertSame('02', MeiliSearchService::normalizeQuery('０２'));
    }

    public function testTheHalfWidthSpellingIsLeftAlone(): void
    {
        $this->assertSame('TOK02', MeiliSearchService::normalizeQuery('TOK02'));
    }

    public function testBothSpellingsNormaliseToTheSameKeyword(): void
    {
        $this->assertSame(
            MeiliSearchService::normalizeQuery('TOK02'),
            MeiliSearchService::normalizeQuery('ＴＯＫ０２'),
            'the two spellings have to reach Meilisearch as one keyword'
        );
    }

    public function testJapaneseTextIsNotTouched(): void
    {
        // Folding is limited to the full-width forms of ascii; kana and kanji
        // must survive untouched or every Japanese keyword would change.
        $this->assertSame('北海道乳業', MeiliSearchService::normalizeQuery('北海道乳業'));
        $this->assertSame('クレーム', MeiliSearchService::normalizeQuery('クレーム'));
    }

    public function testTheIdeographicSpaceBecomesAPlainSpace(): void
    {
        $this->assertSame('棚卸 差異', MeiliSearchService::normalizeQuery('棚卸　差異'));
    }

    /** Folding must not undo the guard that keeps a leading '-' from meaning "exclude". */
    public function testLeadingMinusIsStillStripped(): void
    {
        $this->assertSame('TOK02', MeiliSearchService::normalizeQuery('-TOK02'));
        // A run of minuses loses the ones that have a character behind them.
        $this->assertSame('-', MeiliSearchService::normalizeQuery('---'));
    }

    /** The full-width minus folds to the ascii one, so the same guard then applies to it. */
    public function testAFullWidthMinusIsGuardedToo(): void
    {
        $this->assertSame('TOK02', MeiliSearchService::normalizeQuery('－ＴＯＫ０２'));
    }
}
