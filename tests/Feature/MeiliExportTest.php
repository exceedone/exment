<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * HTTP feature tests for the per-table search export endpoint
 * (SearchController::export).
 *
 * Only the routing/permission gates are covered here: when Meilisearch is
 * disabled the route 404s, and an unknown/forbidden table is denied - both
 * without touching the export writer.
 *
 * The download happy path is intentionally NOT tested in-process: the writer
 * (Exment's DataImportExportService, reused unchanged) calls exit() after
 * streaming the file, which would terminate the PHPUnit runner. That path is
 * covered by Exment's own ImportExportTest for the export machinery and was
 * verified end-to-end over real HTTP during development.
 */
class MeiliExportTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testExportReturns404WhenMeiliDisabled(): void
    {
        config(['meilisearch.global_search' => false]);

        $response = $this->get(
            admin_url('search/export') . '?query=test&table_name=user&format=csv',
            ['X-Requested-With' => 'XMLHttpRequest']
        );
        $response->assertStatus(404);
    }

    public function testExportDeniedForUnknownTable(): void
    {
        config(['meilisearch.global_search' => true]);

        // Send as AJAX: the deny path (notFoundOrDeny) then abort(403)s cleanly
        // instead of the pjax branch which streams a page and exit()s.
        $response = $this->get(
            admin_url('search/export') . '?query=test&table_name=__no_such_table__&format=csv',
            ['X-Requested-With' => 'XMLHttpRequest']
        );
        // A deny/not-found response, never a file download.
        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsStringIgnoringCase('attachment', (string) $response->headers->get('content-disposition'));
    }
}
