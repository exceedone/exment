<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

/**
 * [Filter v1] Build the Meili filter expression from table + date range + creator.
 */
class BuildFilterExpressionTest extends TestCase
{
    public function testTableOnly(): void
    {
        $this->assertSame(
            'table_name = "user"',
            MeiliSearchService::buildFilterExpression('user', [])
        );
    }

    public function testDateFromAndTo(): void
    {
        $this->assertSame(
            'table_name = "user" AND f_date >= 100 AND f_date <= 200',
            MeiliSearchService::buildFilterExpression('user', ['date_from' => 100, 'date_to' => 200])
        );
    }

    public function testUsersIn(): void
    {
        $this->assertSame(
            'table_name = "user" AND f_user IN [1, 2]',
            MeiliSearchService::buildFilterExpression('user', ['users' => [1, 2]])
        );
    }

    public function testNoTableForFacet(): void
    {
        $this->assertSame(
            'f_date >= 100',
            MeiliSearchService::buildFilterExpression(null, ['date_from' => 100])
        );
    }

    public function testEmpty(): void
    {
        $this->assertSame('', MeiliSearchService::buildFilterExpression(null, []));
    }

    public function testEscapesQuotesInTable(): void
    {
        $this->assertSame(
            'table_name = "a\\"b"',
            MeiliSearchService::buildFilterExpression('a"b', [])
        );
    }

    public function testIgnoresEmptyUsersAndNullDates(): void
    {
        $this->assertSame(
            'table_name = "user"',
            MeiliSearchService::buildFilterExpression('user', ['users' => [], 'date_from' => null, 'date_to' => null])
        );
    }

    public function testTablesIn(): void
    {
        $this->assertSame(
            'table_name IN ["a", "b"]',
            MeiliSearchService::buildFilterExpression(null, ['tables' => ['a', 'b']])
        );
    }

    public function testTablesEscapesQuotes(): void
    {
        $this->assertSame(
            'table_name IN ["a\\"b"]',
            MeiliSearchService::buildFilterExpression(null, ['tables' => ['a"b']])
        );
    }

    public function testFiltersWithoutFacetColumn(): void
    {
        $filters = ['date_from' => 100, 'facets' => ['status=open', 'status=closed', 'category=A']];

        $out = MeiliSearchService::filtersWithoutFacetColumn($filters, 'status');

        $this->assertSame(['category=A'], $out['facets']);
        $this->assertSame(100, $out['date_from']);
        // original filters unchanged
        $this->assertCount(3, $filters['facets']);
    }

    public function testFiltersWithoutFacetColumnDropsEmptyKey(): void
    {
        $out = MeiliSearchService::filtersWithoutFacetColumn(['facets' => ['status=open']], 'status');

        $this->assertArrayNotHasKey('facets', $out);
    }

    public function testFiltersWithoutKey(): void
    {
        $out = MeiliSearchService::filtersWithoutKey(['users' => [1], 'date_from' => 5], 'users');

        $this->assertSame(['date_from' => 5], $out);
    }
}
