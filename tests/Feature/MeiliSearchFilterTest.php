<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * HTTP feature tests for the global search results page + unified filter
 * (SearchController::getFreeWord with Meilisearch enabled).
 *
 * The results page renders even when the Meilisearch server is down: the
 * sidebar distribution query is wrapped in try/catch and the per-table result
 * boxes are loaded later via AJAX. So these tests exercise routing, auth, the
 * applied-filter chips and the table filter without needing a live server.
 */
class MeiliSearchFilterTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        // Force the Meili branch on so the filter sidebar + applied chips render.
        config(['meilisearch.global_search' => true]);
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testSearchWithoutQueryRedirects(): void
    {
        // No query param -> redirect to the admin home.
        $this->get(admin_url('search'))->assertRedirect(admin_url());
    }

    public function testSearchPageRenders(): void
    {
        $response = $this->get(admin_url('search') . '?query=test');

        $response->assertStatus(200);
        // The result box for the keyword is present.
        $response->assertSee('test', false);
    }

    public function testAppliedChipsRenderedForFilters(): void
    {
        $response = $this->get(admin_url('search') . '?query=test&date_from=2025-01-01');

        $response->assertStatus(200);
        // Applied-filter chip + its value are rendered above the results.
        $response->assertSee('applied-chip', false);
        $response->assertSee('2025-01-01', false);
    }

    public function testTableFilterLimitsResultBoxes(): void
    {
        $firstTable = CustomTable::searchEnabled()->get()
            ->first(fn ($t) => $t->hasPermission(\Exceedone\Exment\Enums\Permission::AVAILABLE_VIEW_CUSTOM_VALUE));
        if (!$firstTable) {
            $this->markTestSkipped('no search-enabled table available in this environment');
        }

        $response = $this->get(admin_url('search') . '?query=test&tables[]=' . $firstTable->table_name);
        $response->assertStatus(200);

        // Only the selected table produces a result box.
        $this->assertSame(1, substr_count((string) $response->getContent(), 'data-box_key='));
    }

    public function testSearchRequiresAuth(): void
    {
        auth('admin')->logout();
        $response = $this->get(admin_url('search') . '?query=test');
        // Unauthenticated -> redirect to the admin login.
        $response->assertStatus(302);
        $this->assertStringContainsString('auth/login', $response->headers->get('Location'));
    }
}
