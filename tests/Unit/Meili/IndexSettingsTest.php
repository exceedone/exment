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
}
