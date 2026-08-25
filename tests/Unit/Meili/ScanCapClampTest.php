<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

/**
 * Every Meilisearch page/export/autocomplete over-fetches
 * meilisearch.permission_scan_cap candidates and then filters them by
 * permission. The cap comes straight from the environment
 * (MEILISEARCH_PERMISSION_SCAN_CAP), and nothing clamped it:
 *
 *   cap = 0 -> hitsPerPage = 0 -> Meilisearch answers every query with zero
 *   hits, so the search screen reports "no result" for data that is indexed,
 *   the header count reads "0+", and every export is refused as "too many
 *   results" (count([]) >= 0).
 *
 * IndexSettings::build() already clamps the matching maxTotalHits to at least
 * Meilisearch's own default, so the two were inconsistent as well.
 */
class ScanCapClampTest extends TestCase
{
    public function testHitsPerPageNeverDropsToZero(): void
    {
        $this->assertSame(1, MeiliSearchService::buildTableSearchOptions('t', 0, 1)['hitsPerPage']);
        $this->assertSame(1, MeiliSearchService::buildTableSearchOptions('t', -5, 1)['hitsPerPage']);
    }

    public function testPageIsNeverBelowOne(): void
    {
        $this->assertSame(1, MeiliSearchService::buildTableSearchOptions('t', 10, 0)['page']);
        $this->assertSame(1, MeiliSearchService::buildTableSearchOptions('t', 10, -3)['page']);
    }

    public function testNormalValuesAreUntouched(): void
    {
        $opts = MeiliSearchService::buildTableSearchOptions('products', 25, 3);

        $this->assertSame(25, $opts['hitsPerPage']);
        $this->assertSame(3, $opts['page']);
    }

    /**
     * The two runtime readers of the cap must clamp it too: hitsPerPage alone
     * does not save them, because both compare the number of returned ids
     * against the cap ("the result set is a floor" / "refuse the export").
     * With cap = 0 those tests are true for any result, empty included.
     */
    public function testTheCallSitesClampTheConfiguredCap(): void
    {
        foreach ([
            'src/Services/Meili/GlobalSearch/ResultPaginator.php',
            'src/Services/Meili/GlobalSearch/SearchExporter.php',
        ] as $relative) {
            $src = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);

            $this->assertMatchesRegularExpression(
                '/max\(1,\s*\(int\)\s*config\(\x27meilisearch\.permission_scan_cap\x27/',
                $src,
                $relative . ' reads permission_scan_cap without clamping it to at least 1.'
            );
        }
    }
}
