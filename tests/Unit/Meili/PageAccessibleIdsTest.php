<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

/**
 * Pagination over the permission-filtered id set: count & pages honor permissions,
 * preserving Meili's relevance order.
 */
class PageAccessibleIdsTest extends TestCase
{
    public function testFiltersAndKeepsMeiliOrder(): void
    {
        // candidates in Meili order; accessible returned (from DB) out of order.
        $out = MeiliSearchService::pageAccessibleIds([1, 2, 3, 4, 5], [5, 2, 4], 1, 2);

        $this->assertSame(3, $out['total']);         // only 3 records accessible
        $this->assertSame([2, 4], $out['pageIds']);  // page 1, preserving Meili order (2 before 4)
    }

    public function testSecondPage(): void
    {
        $out = MeiliSearchService::pageAccessibleIds([1, 2, 3, 4, 5], [2, 4, 5], 2, 2);

        $this->assertSame(3, $out['total']);
        $this->assertSame([5], $out['pageIds']);
    }

    public function testNoneAccessible(): void
    {
        $out = MeiliSearchService::pageAccessibleIds([1, 2, 3], [], 1, 5);

        $this->assertSame(0, $out['total']);
        $this->assertSame([], $out['pageIds']);
    }

    public function testPageBeyondRange(): void
    {
        $out = MeiliSearchService::pageAccessibleIds([1, 2, 3], [1, 2, 3], 5, 2);

        $this->assertSame(3, $out['total']);
        $this->assertSame([], $out['pageIds']);
    }
}
