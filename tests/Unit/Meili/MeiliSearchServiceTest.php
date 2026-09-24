<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

class MeiliSearchServiceTest extends TestCase
{
    public function testSearchOptionsContainLimitOnlyWhenNoTableFilter(): void
    {
        $this->assertSame(
            ['limit' => 10],
            MeiliSearchService::buildSearchOptions(10, null)
        );
    }

    public function testSearchOptionsAddTableNameFilterWhenGiven(): void
    {
        $this->assertSame(
            ['limit' => 5, 'filter' => 'table_name = "products"'],
            MeiliSearchService::buildSearchOptions(5, 'products')
        );
    }

    public function testMapHitsKeepsOnlyTableNameValueIdAndLabel(): void
    {
        $rawHits = [
            ['id' => 'products__42', 'value_id' => 42, 'table_name' => 'products', 'table_label' => 'SP', 'label' => 'iPhone', 'fields' => ['name' => 'iPhone']],
        ];

        $this->assertSame(
            [['table_name' => 'products', 'value_id' => 42, 'label' => 'iPhone']],
            MeiliSearchService::mapHits($rawHits)
        );
    }

    public function testMapHitsToleratesMissingKeys(): void
    {
        $this->assertSame(
            [['table_name' => null, 'value_id' => null, 'label' => null]],
            MeiliSearchService::mapHits([[]])
        );
    }

    public function testTableSearchOptionsUsePageAndFilter(): void
    {
        $this->assertSame(
            [
                'filter' => 'table_name = "products"',
                'hitsPerPage' => 5,
                'page' => 2,
                'attributesToRetrieve' => ['value_id'],
            ],
            MeiliSearchService::buildTableSearchOptions('products', 5, 2)
        );
    }

    /**
     * The candidate fetch asks for up to permission_scan_cap hits; carrying the
     * whole document (every indexed field) for each of them is pure waste - the
     * rows themselves are loaded from MySQL afterwards.
     */
    public function testTableSearchOptionsOnlyRetrieveTheValueId(): void
    {
        $this->assertSame(
            ['value_id'],
            MeiliSearchService::buildTableSearchOptions('products', 1000, 1)['attributesToRetrieve']
        );
    }

    public function testSortExpressionMapsUiKeys(): void
    {
        $this->assertSame(['f_date:desc'], MeiliSearchService::buildSortExpression('newest'));
        $this->assertSame(['f_date:asc'], MeiliSearchService::buildSortExpression('oldest'));
        // Default / unknown value -> no sort (use Meili's ranking rules).
        $this->assertSame([], MeiliSearchService::buildSortExpression(null));
        $this->assertSame([], MeiliSearchService::buildSortExpression('relevance'));
        $this->assertSame([], MeiliSearchService::buildSortExpression('f_date:desc; drop'));
    }

    public function testTableSearchOptionsAppendSortOnlyWhenSorted(): void
    {
        $this->assertArrayNotHasKey('sort', MeiliSearchService::buildTableSearchOptions('products', 5, 1));
        $this->assertSame(
            ['f_date:desc'],
            MeiliSearchService::buildTableSearchOptions('products', 5, 1, [], 'newest')['sort']
        );
    }

    public function testQuoteFilterValueEscapesBackslashBeforeQuote(): void
    {
        $this->assertSame('"plain"', MeiliSearchService::quoteFilterValue('plain'));
        $this->assertSame('"say \\"hi\\""', MeiliSearchService::quoteFilterValue('say "hi"'));
        // A trailing backslash must not neutralize the closing quote
        // (filter-expression injection).
        $this->assertSame('"trailing\\\\"', MeiliSearchService::quoteFilterValue('trailing\\'));
        $this->assertSame(
            '"a\\\\\\" OR table_name = \\\\\\"secret"',
            MeiliSearchService::quoteFilterValue('a\\" OR table_name = \\"secret')
        );
    }

    public function testPickHighlightPrefersLabelWhenLabelIsHighlighted(): void
    {
        $pre = MeiliSearchService::HIGHLIGHT_PRE;
        $post = MeiliSearchService::HIGHLIGHT_POST;
        $formatted = [
            'label' => "invoice {$pre}budget{$post} release",
            'fields' => ['po' => 'PO-123'],
        ];

        $this->assertSame(
            "invoice {$pre}budget{$post} release",
            MeiliSearchService::pickHighlightedSnippet($formatted)
        );
    }

    public function testPickHighlightFallsBackToFieldWhenOnlyFieldMatches(): void
    {
        $pre = MeiliSearchService::HIGHLIGHT_PRE;
        $post = MeiliSearchService::HIGHLIGHT_POST;
        $formatted = [
            'label' => 'iPhone 15',
            'fields' => ['code' => 'no-match', 'po' => "{$pre}PO{$post}-123"],
        ];

        $this->assertSame(
            "{$pre}PO{$post}-123",
            MeiliSearchService::pickHighlightedSnippet($formatted)
        );
    }

    public function testPickHighlightReturnsLabelWhenNothingHighlighted(): void
    {
        $formatted = ['label' => 'iPhone 15', 'fields' => ['po' => 'PO-123']];

        $this->assertSame('iPhone 15', MeiliSearchService::pickHighlightedSnippet($formatted));
    }

    public function testSortFacetsDescendingByCount(): void
    {
        $this->assertSame(
            [['table' => 'b', 'count' => 10], ['table' => 'a', 'count' => 3], ['table' => 'c', 'count' => 1]],
            MeiliSearchService::sortFacets(['a' => 3, 'b' => 10, 'c' => 1])
        );
    }

    public function testSortFacetsEmpty(): void
    {
        $this->assertSame([], MeiliSearchService::sortFacets([]));
    }
}
