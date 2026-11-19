<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\GlobalSearch\SearchExporter;
use PHPUnit\Framework\TestCase;

/**
 * The export "too many results" guard compares against what Meilisearch can
 * actually return. The index caps every answer at maxTotalHits, so a bigger
 * permission_scan_cap can never be reached and the guard would never fire -
 * shipping a silently truncated file instead.
 */
class ExportCapTest extends TestCase
{
    public function testCapAboveTheIndexCeilingIsClampedToIt(): void
    {
        // .env raised to 5000 but the index was never re-configured.
        $this->assertSame(1000, SearchExporter::effectiveCap(5000, 1000));
    }

    public function testCapBelowTheIndexCeilingStillRules(): void
    {
        $this->assertSame(500, SearchExporter::effectiveCap(500, 1000));
    }

    public function testUnknownCeilingFallsBackToMeilisDefault(): void
    {
        // Cannot read the index setting -> assume Meilisearch's own default
        // rather than the raw cap, so the guard errs towards refusing.
        $this->assertSame(1000, SearchExporter::effectiveCap(5000, null));
        $this->assertSame(300, SearchExporter::effectiveCap(300, null));
    }
}
