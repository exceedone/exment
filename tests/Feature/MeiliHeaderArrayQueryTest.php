<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Regression tests for array-shaped query params on the JSON search endpoints
 * (SearchController::header / getList / getLists).
 *
 * 2764a781f fixed `?query[]=x` on the results page (SearchController::index)
 * and on the saved-search controller by routing every keyword read through
 * RequestFilters::str. The three JSON endpoints kept the raw
 * `$request->input('query')` read, so a crafted URL like
 * `/search/header?query[]=admin`
 *
 * - Meili branch: HeaderSuggester::suggest((string) $q) casts an array to
 *   string -> Laravel promotes the E_WARNING to ErrorException.
 * - MySQL branch: CustomTable::searchValue($q, ...) and the paginator's
 *   `"?query=$q"` path interpolation both hit the same array-to-string cast.
 *
 * The result is a 200 HTML error page instead of a JSON body, which breaks
 * the header autocomplete and the per-table result boxes on the search page.
 *
 * Every test asserts a JSON response - status code alone is not enough
 * because the Exment error page also renders with status 200.
 */
class MeiliHeaderArrayQueryTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    /**
     * Build /search/xxx?query[]=admin the same way a browser/attacker would.
     *
     * @param  array<string,mixed>  $extra
     */
    private function url(string $endpoint, array $extra = []): string
    {
        return admin_urls('search', $endpoint) . '?' . http_build_query(
            ['query' => ['admin']] + $extra
        );
    }

    /**
     * A concrete search-enabled table name, so getList/getLists reach the
     * MySQL branch that trips over "?query=$q" string interpolation.
     *
     * Skips the test when the environment has no search-enabled table with
     * VIEW permission for the admin user - nothing to exercise then.
     */
    private function anySearchEnabledTableName(): string
    {
        $t = CustomTable::searchEnabled()->get()
            ->first(fn ($ct) => $ct->hasPermission(\Exceedone\Exment\Enums\Permission::AVAILABLE_VIEW_CUSTOM_VALUE));

        if (!$t) {
            $this->markTestSkipped('no search-enabled table available in this environment');
        }
        return (string) $t->table_name;
    }

    /**
     * Assert the response is a JSON body, not a rendered HTML error page.
     * The Exment global error handler returns 200 + text/html, so only
     * checking the status code hides the bug.
     */
    private function assertIsJsonResponse(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(200);
        $ct = (string) $response->headers->get('content-type');
        $this->assertStringContainsString(
            'application/json',
            $ct,
            "Expected JSON response, got Content-Type: {$ct}\nBody preview: "
                . substr((string) $response->getContent(), 0, 200)
        );
    }

    public function testHeaderRejectsArrayQuery(): void
    {
        $response = $this->get($this->url('header'));

        $this->assertIsJsonResponse($response);
        $this->assertSame([], $response->json());
    }

    public function testHeaderRejectsNestedArrayQuery(): void
    {
        // `?query[][]=admin` -> input('query') returns [['admin']] (nested).
        $url = admin_urls('search', 'header') . '?' . http_build_query([
            'query' => [['admin']],
        ]);
        $response = $this->get($url);

        $this->assertIsJsonResponse($response);
        $this->assertSame([], $response->json());
    }

    public function testHeaderStillAcceptsPlainString(): void
    {
        // Sanity check: the fix must not turn a normal keyword into [].
        $response = $this->get(admin_urls('search', 'header') . '?query=admin');

        $this->assertIsJsonResponse($response);
        $this->assertIsArray($response->json());
    }

    public function testGetListRejectsArrayQuery(): void
    {
        $table_name = $this->anySearchEnabledTableName();

        $response = $this->get($this->url('list', ['table_name' => $table_name]));

        $this->assertIsJsonResponse($response);
    }

    public function testGetListsRejectsArrayQuery(): void
    {
        $table_name = $this->anySearchEnabledTableName();

        $response = $this->get($this->url('lists', ['table_names' => $table_name]));

        $this->assertIsJsonResponse($response);
    }
}
