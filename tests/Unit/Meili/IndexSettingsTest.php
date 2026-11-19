<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\IndexSettings;
use PHPUnit\Framework\TestCase;

class IndexSettingsTest extends TestCase
{
    public function testDefaultsPutLabelFirstForWeighting(): void
    {
        $s = IndexSettings::build();

        // label first in searchableAttributes -> highest weight (ranking rule 'attribute').
        $this->assertSame(['label', 'fields', 'table_label'], $s['searchableAttributes']);
        // table_name + v1 filter axes are always filterable.
        $this->assertSame(['table_name', 'f_date', 'f_user', 'facets'], $s['filterableAttributes']);
        // f_date sortable (range filter + time ordering).
        $this->assertSame(['f_date'], $s['sortableAttributes']);
    }

    public function testTypoToleranceDefaults(): void
    {
        $s = IndexSettings::build();

        $this->assertTrue($s['typoTolerance']['enabled']);
        $this->assertSame(5, $s['typoTolerance']['minWordSizeForTypos']['oneTypo']);
        $this->assertSame(9, $s['typoTolerance']['minWordSizeForTypos']['twoTypos']);
    }

    public function testOverridesFromOptions(): void
    {
        $s = IndexSettings::build([
            'searchable_attributes' => ['label'],
            'stop_words' => ['the', 'a'],
            'synonyms' => ['nyc' => ['new york']],
            'typo_enabled' => false,
            'typo_one' => 4,
            'typo_two' => 8,
        ]);

        $this->assertSame(['label'], $s['searchableAttributes']);
        $this->assertSame(['the', 'a'], $s['stopWords']);
        $this->assertSame(['nyc' => ['new york']], $s['synonyms']);
        $this->assertFalse($s['typoTolerance']['enabled']);
        $this->assertSame(4, $s['typoTolerance']['minWordSizeForTypos']['oneTypo']);
        $this->assertSame(8, $s['typoTolerance']['minWordSizeForTypos']['twoTypos']);
    }

    public function testEmptyOverrideFallsBackToDefault(): void
    {
        // config/meilisearch.php ships these as empty arrays. A plain ?? would
        // pass [] straight through, leaving Meilisearch with nothing searchable
        // (every search returns 0 hits) and no ranking rules.
        $s = IndexSettings::build([
            'searchable_attributes' => [],
            'ranking_rules' => [],
        ]);

        $this->assertSame(IndexSettings::DEFAULT_SEARCHABLE, $s['searchableAttributes']);
        $this->assertSame(IndexSettings::DEFAULT_RANKING, $s['rankingRules']);
    }

    public function testRankingRulesOverrideIsHonoured(): void
    {
        $s = IndexSettings::build(['ranking_rules' => ['words', 'typo']]);

        $this->assertSame(['words', 'typo'], $s['rankingRules']);
    }

    public function testStopWordsDefaultEmptyArray(): void
    {
        $s = IndexSettings::build();

        $this->assertSame([], $s['stopWords']);
    }

    public function testSynonymsDefaultIsEmptyObjectNotArray(): void
    {
        // Meili needs empty synonyms as {} (object), not [] (array).
        $s = IndexSettings::build();

        $this->assertIsObject($s['synonyms']);
        $this->assertEquals((object) [], $s['synonyms']);
    }

    public function testFilterableAlwaysContainsTableNameEvenIfOverridden(): void
    {
        // table_name is required for facets/delete-by-table -> must not be dropped.
        $s = IndexSettings::build(['filterable_attributes' => ['foo']]);

        $this->assertContains('table_name', $s['filterableAttributes']);
        $this->assertContains('foo', $s['filterableAttributes']);
    }

    /**
     * Every sidebar group draws from one `facets` attribute, so Meilisearch's
     * per-attribute cap is shared by the whole sidebar. Its default of 100 is
     * passed by any real dataset, and once the cap truncates, values disappear
     * AND the counts of the survivors come back wrong.
     */
    public function testFacetValueCapIsRaisedAboveMeilisearchDefault(): void
    {
        $s = IndexSettings::build();

        $this->assertSame(1000, $s['faceting']['maxValuesPerFacet']);
    }

    public function testFacetValueCapCanBeRaisedButNeverLowered(): void
    {
        $this->assertSame(5000, IndexSettings::build(['max_facet_values' => 5000])['faceting']['maxValuesPerFacet']);
        // A smaller configured value would re-open the truncation it exists to prevent.
        $this->assertSame(1000, IndexSettings::build(['max_facet_values' => 50])['faceting']['maxValuesPerFacet']);
        $this->assertSame(1000, IndexSettings::build(['max_facet_values' => 0])['faceting']['maxValuesPerFacet']);
    }

    public function testLocalesBecomeALocalizedAttributeRuleOverEveryField(): void
    {
        $s = IndexSettings::build(['locales' => ['jpn']]);

        $this->assertSame([['attributePatterns' => ['*'], 'locales' => ['jpn']]], $s['localizedAttributes']);
    }

    public function testSeveralLocalesAreKeptInOrder(): void
    {
        $s = IndexSettings::build(['locales' => ['jpn', 'eng']]);

        $this->assertSame(['jpn', 'eng'], $s['localizedAttributes'][0]['locales']);
    }

    /**
     * null, not [] - an empty array would leave whatever the index already has,
     * so clearing MEILISEARCH_LOCALES could never undo the setting.
     */
    public function testNoLocalesClearsTheSettingWithNull(): void
    {
        $this->assertNull(IndexSettings::build()['localizedAttributes']);
        $this->assertNull(IndexSettings::build(['locales' => []])['localizedAttributes']);
    }

    /**
     * Meilisearch defaults to 'alpha'. Sorting by count is what makes a forced
     * truncation keep the values that matter - and it also returns correct
     * counts, because ranking by count means every count is computed first.
     */
    public function testFacetValuesAreOrderedByCountNotAlphabetically(): void
    {
        $s = IndexSettings::build();

        $this->assertSame(['*' => 'count'], $s['faceting']['sortFacetValuesBy']);
    }
}
