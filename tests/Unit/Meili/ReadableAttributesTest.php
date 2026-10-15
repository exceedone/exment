<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\GlobalSearch\FilterSidebar;
use Exceedone\Exment\Services\Meili\GlobalSearch\ReadableAttributes;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

/**
 * A document keeps the real label of a linked record, because it is built by a
 * job with no logged-in user; the record screen shows "no permission" for the
 * same value. The search must therefore not match on it, or the hits alone
 * would confirm a hidden record's name.
 */
class ReadableAttributesTest extends TestCase
{
    /**
     * Null must mean "search everything", and an empty list must stay empty -
     * turning [] back into "no restriction" is exactly the leak.
     */
    public function testSearchOnIsOnlySentWhenTheViewerIsRestricted(): void
    {
        $open = MeiliSearchService::buildTableSearchOptions('contracts', 10, 1, [], null, null);
        $this->assertArrayNotHasKey(
            'attributesToSearchOn',
            $open,
            'An unrestricted viewer must get the same query as before.'
        );

        $closed = MeiliSearchService::buildTableSearchOptions('contracts', 10, 1, [], null, []);
        $this->assertSame(
            [],
            $closed['attributesToSearchOn'],
            'Nothing readable must reach Meilisearch as an empty list, not as "search everything".'
        );

        $limited = MeiliSearchService::buildTableSearchOptions(
            'contracts',
            10,
            1,
            [],
            null,
            ['table_label', 'label', 'fields.title']
        );
        $this->assertSame(['table_label', 'label', 'fields.title'], $limited['attributesToSearchOn']);
    }

    /** The paths are passed on verbatim; a gap would silently widen the search. */
    public function testSearchOnIsReindexedSoMeilisearchReceivesAJsonArray(): void
    {
        $sparse = [2 => 'label', 5 => 'fields.title'];

        $options = MeiliSearchService::buildTableSearchOptions('contracts', 10, 1, [], null, $sparse);

        $this->assertSame(['label', 'fields.title'], $options['attributesToSearchOn']);
    }

    public function testFacetTokensOfHiddenColumnsAreDropped(): void
    {
        $filters = ['facets' => [
            'contracts::status=draft',
            'contracts::partner=Aurora Logistics',
            'territory=north',
        ]];

        $kept = ReadableAttributes::stripFacetPrefixes($filters, ['contracts::partner']);

        $this->assertSame(['contracts::status=draft', 'territory=north'], $kept['facets']);
    }

    /** An alias groups several columns, and its token carries no table name. */
    public function testAHiddenAliasGroupIsDroppedToo(): void
    {
        $filters = ['facets' => ['territory=north', 'contracts::status=draft']];

        $kept = ReadableAttributes::stripFacetPrefixes($filters, ['territory']);

        $this->assertSame(['contracts::status=draft'], $kept['facets']);
    }

    /**
     * An empty facets key would be read as "no facet filter" by
     * buildFilterExpression, which is what we want - but it must not be left as
     * an empty array that other code tests with isset().
     */
    public function testTheFacetsKeyIsRemovedWhenEveryTokenWasHidden(): void
    {
        $filters = ['facets' => ['contracts::partner=Aurora Logistics'], 'users' => [1]];

        $kept = ReadableAttributes::stripFacetPrefixes($filters, ['contracts::partner']);

        $this->assertArrayNotHasKey('facets', $kept);
        $this->assertSame([1], $kept['users']);
    }

    public function testNothingIsStrippedWhenTheViewerSeesEverything(): void
    {
        $filters = ['facets' => ['contracts::partner=Aurora Logistics']];

        $this->assertSame($filters, ReadableAttributes::stripFacetPrefixes($filters, []));
    }

    /**
     * AppliedChips strips the query params once, up front, rather than inside
     * the chip loop: every chip's remove-url repeats whatever is left of them,
     * so a token removed only from the facet chips would come straight back
     * when the user removes the table chip - and the page would again show a
     * condition the search does not apply.
     */
    public function testRemovingAnotherChipDoesNotBringAHiddenFacetBack(): void
    {
        $params = [
            'query' => 'aurora',
            'tables' => ['contracts'],
            'facets' => ['contracts::partner=Aurora Logistics', 'contracts::status=draft'],
        ];

        $params = ReadableAttributes::stripFacetPrefixes($params, ['contracts::partner']);

        // What AppliedChips builds the table chip's remove-url from.
        $afterRemovingTheTableChip = $params;
        unset($afterRemovingTheTableChip['tables']);

        $this->assertSame(['contracts::status=draft'], $afterRemovingTheTableChip['facets']);
    }

    /**
     * The sidebar counts restricted tables in queries of their own and adds the
     * results up. Dropping a shared token instead of summing it would report
     * fewer hits than the result boxes show.
     */
    public function testPerTableCountsAreSummedIntoTheMergedDistribution(): void
    {
        $merged = new \ReflectionMethod(FilterSidebar::class, 'sumDistributions');
        $merged->setAccessible(true);

        $result = $merged->invoke(null, [
            'table_name' => ['customers' => 4],
            'facets' => ['customers::tier=gold' => 4],
        ], [
            'table_name' => ['contracts' => 2],
            'facets' => ['customers::tier=gold' => 1, 'contracts::status=draft' => 2],
        ]);

        $this->assertSame(['customers' => 4, 'contracts' => 2], $result['table_name']);
        $this->assertSame(
            ['customers::tier=gold' => 5, 'contracts::status=draft' => 2],
            $result['facets'],
            'A token both queries returned must be added up, not overwritten.'
        );
    }
}
