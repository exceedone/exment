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

    /**
     * Regression: `?query[]=x&table_name=user` used to reach the exporter's
     * Grid quick-search - open-admin-core's `HasQuickSearch` is wired to the
     * `query` key by `Middleware\Initialize` (Grid::setSearchKey('query')),
     * so `Grid::applyQuickSearch()` does `trim(request()->get('query'))` and
     * TypeErrors on the array.
     *
     * `SearchController::export` sanitizes `$q` for the Meili call via
     * `RequestFilters::str(...)` but the injected `$request` (== the facade
     * request the Grid reads) still carries the raw array, so the fix has to
     * write the sanitized value back into the request source before the
     * exporter builds the Grid.
     *
     * The endpoint must NOT render an HTML error page - it either serves the
     * XLSX/CSV file, redirects back (cap hit), or denies with a JSON error
     * for AJAX callers. Anything with a 500-ish HTML body indicates the bug.
     *
     * Skipped when there is no live Meilisearch on the configured host: the
     * export code path calls Meili before touching the Grid, so an unreachable
     * server would fail earlier for an unrelated reason.
     */
    /**
     * Regression: `?query[]=x&table_name=user` used to reach the exporter's
     * Grid quick-search - open-admin-core's `HasQuickSearch` is wired to the
     * `query` key by `Middleware\Initialize` (Grid::setSearchKey('query')),
     * so `Grid::applyQuickSearch()` does `trim(request()->get('query'))` and
     * TypeErrors on the array.
     *
     * `SearchController::export` sanitized `$q` for the Meili call via
     * `RequestFilters::str(...)` but the injected `$request` (== the facade
     * request the Grid reads) still carried the raw array, so the fix has to
     * write the sanitized value back into the request source before the
     * exporter builds the Grid.
     *
     * The end-to-end HTTP variant of this test cannot run in-process because
     * on the happy path the exporter's writer calls `exit()` after streaming
     * the file (FormatBase::sendResponse). Instead we exercise the controller
     * seam directly: `export()` must normalize `$request->input('query')` into
     * a scalar BEFORE delegating - the Grid (and the injected `$q`) then both
     * see the sanitized value.
     */
    public function testExportNormalizesArrayQueryBeforeDelegating(): void
    {
        config(['meilisearch.global_search' => true]);
        config(['exment.search_document' => false]);

        // A stub controller that captures the values that would have been
        // handed to the Meili exporter. This exercises SearchController::export
        // as-is up to the delegate point, without ever streaming or exit()ing.
        $controller = new class extends \Exceedone\Exment\Controllers\SearchController {
            /** @var array<string,mixed> */
            public $captured = ['called' => false];

            protected function exportByMeili($request, $q, $custom_table)
            {
                $this->captured = [
                    'called' => true,
                    'q_type' => gettype($q),
                    'q_value' => $q,
                    'request_query' => $request->input('query'),
                    'facade_query' => request()->input('query'),
                    'grid_search_query' => request()->get(
                        \ExmentAdminCore\Admin\Grid::getSearchKey()
                    ),
                ];
                return null;
            }
        };

        // Wire the facade request to the same instance the controller uses so
        // request()->get(...) inside the Grid path sees whatever export()
        // wrote back via $request->merge().
        $request = \Illuminate\Http\Request::create(
            admin_url('search/export'),
            'GET',
            ['query' => ['test'], 'table_name' => 'user', 'format' => 'csv']
        );
        $this->app->instance('request', $request);
        \Illuminate\Support\Facades\Request::swap($request);

        $controller->export($request);

        $this->assertTrue(
            (bool) $controller->captured['called'],
            'export() short-circuited before delegating: user table missing or unauthorized.'
        );

        $this->assertSame(
            'string',
            $controller->captured['q_type'],
            "\$q passed to exportByMeili must be a string, got: " . $controller->captured['q_type']
        );

        $this->assertIsNotArray(
            $controller->captured['request_query'],
            'The injected $request must no longer carry an array `query` - the exporter'
                . ' Grid reads it via HasQuickSearch and trim()s the value.'
        );
        $this->assertIsNotArray(
            $controller->captured['facade_query'],
            'The facade request must also see a scalar `query` - Grid reads request()->get().'
        );
        $this->assertIsNotArray(
            $controller->captured['grid_search_query'],
            'Grid::getSearchKey() lookup must see a scalar - HasQuickSearch does trim() on it.'
        );
    }

}
