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

    /**
     * A crafted `?range[n_t::c][from][]=1` made FilterSidebar::rangeInputs() and
     * AppliedChips::build() cast an array to string. That is an E_WARNING, which
     * Laravel turns into an ErrorException - and getFreeWord() catches Throwable,
     * so the page still answered 200 while quietly rendering the NON-Meili view:
     * no filter sidebar, no chips, no export, no saved-search bar, no sort.
     *
     * See tests/Unit/Meili/RangeSideGuardTest.php for the unit-level contract.
     */
    public function testArrayShapedRangeParamKeepsTheMeiliFilterUi(): void
    {
        // Control first: without the crafted param the Meili branch renders, so
        // the assertion below actually distinguishes the two code paths.
        $control = $this->get(admin_url('search') . '?query=test');
        $control->assertStatus(200);
        $control->assertSee('meili-filter', false);

        $response = $this->get(
            admin_url('search') . '?query=test&range[n_t%3A%3Ac][from][]=1&range[n_t%3A%3Ac][to][]=9'
        );

        $response->assertStatus(200);
        $response->assertSee('meili-filter', false);
        $response->assertSee('meili-quickbar', false);
    }

    /**
     * The other half of the same defect. Laravel only turns the warning into an
     * ErrorException when E_WARNING is part of error_reporting
     * (HandleExceptions::handleError). Where it is not, nothing throws: the page
     * keeps its Meili UI and the cast quietly yields the literal string "Array",
     * which lands in the applied-chip label as the filter's value.
     */
    public function testArrayShapedRangeParamProducesNoGarbageChip(): void
    {
        $previous = error_reporting(E_ALL & ~E_WARNING);
        try {
            $response = $this->get(
                admin_url('search') . '?query=test&range[n_t%3A%3Ac][from][]=1'
            );
        } finally {
            error_reporting($previous);
        }

        $response->assertStatus(200);
        // The Meili branch really did render here - otherwise there are no chips
        // to be wrong about and the assertion below would pass for free.
        $response->assertSee('meili-filter', false);
        $this->assertStringNotContainsString(
            'Array',
            (string) $response->getContent(),
            'a nested range value reached the page as a filter whose value is literally "Array"'
        );
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
