<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\IndexSettings;
use PHPUnit\Framework\TestCase;

/**
 * Meilisearch applies the ranking rules in order, so where "sort" sits decides
 * whether an explicit sort wins or merely breaks ties.
 *
 * In Meilisearch's own default it sits fifth, below "attribute": picking
 * 新しい順 then returned a list that was not in date order at all whenever the
 * matches differed in relevance. The index must therefore rank by "sort" first.
 */
class SortRankingRuleTest extends TestCase
{
    public function testSortRanksBeforeTheRelevanceRules(): void
    {
        $rules = IndexSettings::DEFAULT_RANKING;

        $this->assertSame('sort', $rules[0], 'an explicit sort has to decide the order, not just break ties');
    }

    public function testSortComesBeforeAttribute(): void
    {
        $rules = IndexSettings::DEFAULT_RANKING;

        $this->assertLessThan(
            array_search('attribute', $rules, true),
            array_search('sort', $rules, true),
            '"attribute" above "sort" is what made 新しい順 return a non-chronological list'
        );
    }

    /** The rest of the rules must still be there - this is a reorder, not a trim. */
    public function testTheOtherRulesAreKept(): void
    {
        $rules = IndexSettings::DEFAULT_RANKING;

        foreach (['words', 'typo', 'proximity', 'attribute', 'exactness'] as $rule) {
            $this->assertContains($rule, $rules);
        }
        $this->assertCount(6, $rules);
    }
}
