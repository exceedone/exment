<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

/**
 * The select/query API pages over the candidate ids Meilisearch returned. That
 * is only exact when the list holds every match; otherwise the total and the
 * last pages stop at the cap (select2 loaded 200 of 1115 records).
 */
class CompleteCandidateSetTest extends TestCase
{
    public function testFewerMatchesThanTheCapAreComplete(): void
    {
        $this->assertTrue(MeiliSearchService::isCompleteCandidateSet(30, 200, 1000, 30));
    }

    public function testReachingTheCapIsNotComplete(): void
    {
        // count=10 -> cap 200: exactly what cut the select2 list at 200.
        $this->assertFalse(MeiliSearchService::isCompleteCandidateSet(200, 200, 1000, 1000));
    }

    public function testMoreReportedHitsThanReturnedIsNotComplete(): void
    {
        $this->assertFalse(MeiliSearchService::isCompleteCandidateSet(150, 200, 1000, 180));
    }

    public function testReachingTheIndexCeilingIsNotComplete(): void
    {
        // permission_scan_cap raised above the index's maxTotalHits: Meilisearch
        // stops at 1000 although the cap asked for 5000.
        $this->assertFalse(MeiliSearchService::isCompleteCandidateSet(1000, 5000, 1000, 1000));
    }

    public function testUnknownIndexCeilingFallsBackToTheOtherChecks(): void
    {
        $this->assertTrue(MeiliSearchService::isCompleteCandidateSet(40, 200, null, 40));
        $this->assertFalse(MeiliSearchService::isCompleteCandidateSet(200, 200, null, 200));
    }
}
