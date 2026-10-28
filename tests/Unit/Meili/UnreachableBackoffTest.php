<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use PHPUnit\Framework\TestCase;

/**
 * Every call to a Meilisearch that is down costs a full connect timeout, and
 * nothing used to remember that it was down. On the 'sync' queue driver the sync
 * job runs inside the request that saved the record, so every single save paid
 * that timeout again - measured at ~1.0s per save with the default
 * MEILISEARCH_CONNECT_TIMEOUT, i.e. ~17 minutes for a 1000-row import. A search
 * results page paid it several times over, once per call it makes.
 *
 * The breaker therefore sits in the client, where every call goes through it,
 * and not around any one of them.
 */
class UnreachableBackoffTest extends TestCase
{
    public function testAProbeRightAfterAFailedOneIsNotDue(): void
    {
        $this->assertFalse(MeiliClientFactory::probeIsDue(1_000_000, 10, 1_000_000));
        $this->assertFalse(MeiliClientFactory::probeIsDue(1_000_000, 10, 1_000_009));
    }

    public function testTheProbeIsDueOnceTheBackoffHasPassed(): void
    {
        $this->assertTrue(MeiliClientFactory::probeIsDue(1_000_000, 10, 1_000_010));
        $this->assertTrue(MeiliClientFactory::probeIsDue(1_000_000, 10, 1_000_100));
    }

    /**
     * The window must stay short: a write made inside it never reaches the
     * index, and only exment:meili-index or exment:meili-reconcile brings it
     * back. 0 turns the backoff off entirely.
     */
    public function testZeroBackoffProbesEveryTime(): void
    {
        $this->assertTrue(MeiliClientFactory::probeIsDue(1_000_000, 0, 1_000_000));
    }

    public function testTheDefaultWindowIsShort(): void
    {
        $this->assertLessThanOrEqual(60, MeiliClientFactory::UNREACHABLE_BACKOFF);
        $this->assertGreaterThan(0, MeiliClientFactory::UNREACHABLE_BACKOFF);
    }
}
