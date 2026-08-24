<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\MeiliSavedSearch;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * HTTP feature tests for the saved-search endpoints
 * (MeiliSavedSearchController: store / listJson / apply / destroy).
 *
 * These endpoints are pure DB + service logic and do NOT need a running
 * Meilisearch server, so they run in any environment.
 */
class MeiliSavedSearchTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testStoreRequiresName(): void
    {
        $response = $this->post(admin_urls('search', 'saved'), [
            'query' => 'foo',
            'name' => '',
        ]);

        $response->assertStatus(422);
    }

    public function testStorePersonalWithKeyword(): void
    {
        $response = $this->post(admin_urls('search', 'saved'), [
            'query' => 'contract',
            'name' => 'My personal search',
            'with_query' => '1',
            'share_type' => 'personal',
        ]);

        $response->assertStatus(200);
        $id = $response->json('id');
        $this->assertNotNull($id);

        $saved = MeiliSavedSearch::find((int) $id);
        $this->assertSame('My personal search', $saved->name);
        $this->assertSame('contract', (string) $saved->query);
        $this->assertSame(MeiliSavedSearch::SHARE_PERSONAL, $saved->share_type);
        $this->assertSame((int) LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->getUserId(), (int) $saved->owner_user_id);
    }

    public function testStoreWithoutKeywordKeepsQueryEmpty(): void
    {
        // with_query=0 -> quick filter: store filters only, no keyword.
        $response = $this->post(admin_urls('search', 'saved'), [
            'query' => 'ignored',
            'name' => 'Quick filter',
            'with_query' => '0',
            'share_type' => 'personal',
        ]);

        $id = $response->json('id');
        $this->assertSame('', (string) MeiliSavedSearch::find((int) $id)->query);
    }

    public function testStoreShareAll(): void
    {
        $response = $this->post(admin_urls('search', 'saved'), [
            'query' => 'x',
            'name' => 'Shared with all',
            'share_type' => 'all',
        ]);

        $this->assertSame(MeiliSavedSearch::SHARE_ALL, MeiliSavedSearch::find((int) $response->json('id'))->share_type);
    }

    public function testStoreShareRoleGroupKeepsTargets(): void
    {
        // Ids come from the DB: hardcoded ones only exist in one fixture, and
        // validShareTargets() drops whatever does not exist -> targets end up [].
        $ids = RoleGroup::orderBy('id')->limit(2)->pluck('id')->map('intval')->all();
        if (count($ids) < 2) {
            $this->markTestSkipped('needs at least 2 role groups seeded');
        }

        $response = $this->post(admin_urls('search', 'saved'), [
            'query' => 'x',
            'name' => 'Shared with role groups',
            'share_type' => 'role_group',
            // the trailing id does not exist and must be filtered out
            'share_targets' => array_merge(array_map('strval', $ids), ['999999']),
        ]);

        $saved = MeiliSavedSearch::find((int) $response->json('id'));
        $this->assertSame(MeiliSavedSearch::SHARE_ROLE_GROUP, $saved->share_type);
        $this->assertSame($ids, array_map('intval', (array) $saved->share_targets));
    }

    public function testStoreKeepsGenericFilterParams(): void
    {
        // filters are stored as the generic param contract (whitelisted).
        $response = $this->post(admin_urls('search', 'saved'), [
            'query' => 'x',
            'name' => 'With filters',
            'facets' => ['priority=high'],
            'date_from' => '2025-01-01',
        ]);

        $filters = (array) MeiliSavedSearch::find((int) $response->json('id'))->filters;
        $this->assertContains('priority=high', $filters['facets'] ?? []);
        $this->assertSame('2025-01-01', $filters['date_from'] ?? null);
    }

    public function testListForCurrentUserReturnsOwnRecords(): void
    {
        $this->post(admin_urls('search', 'saved'), [
            'query' => 'listme',
            'name' => 'Listed search',
            'share_type' => 'personal',
        ]);

        // listForCurrentUser (used by the quickbar) constrains in SQL first;
        // the owner must still see their own personal search.
        $names = MeiliSavedSearch::listForCurrentUser()->pluck('name')->all();
        $this->assertContains('Listed search', $names);
    }

    public function testApplyRedirectsWithQueryAndParams(): void
    {
        $id = $this->post(admin_urls('search', 'saved'), [
            'query' => 'applyme',
            'name' => 'Apply search',
            'with_query' => '1',
            'facets' => ['priority=high'],
        ])->json('id');

        $response = $this->get(admin_urls('search', 'saved', $id, 'apply'));

        $response->assertStatus(302);
        $location = $response->headers->get('Location');
        // redirect to the search page, carrying keyword + ss (active chip) + the filter.
        $this->assertStringContainsString('/search?', $location);
        $this->assertStringContainsString('query=applyme', $location);
        $this->assertStringContainsString('ss=' . $id, $location);
    }

    public function testDestroyOwnRecord(): void
    {
        $id = $this->post(admin_urls('search', 'saved'), [
            'query' => 'delme',
            'name' => 'Delete me',
        ])->json('id');

        $this->delete(admin_urls('search', 'saved', $id))->assertStatus(200);
        $this->assertNull(MeiliSavedSearch::find((int) $id));
    }

    public function testDestroyOtherUsersRecordDenied(): void
    {
        $nonAdmin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1);
        if (!$nonAdmin) {
            $this->markTestSkipped('non-admin test user (login id 2) is not seeded in this environment');
        }

        // A record owned by admin; a non-admin, non-owner must not delete it.
        $saved = MeiliSavedSearch::create([
            'name' => 'Owned by admin',
            'owner_user_id' => (int) LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->getUserId(),
            'query' => 'x',
            'filters' => [],
            'share_type' => MeiliSavedSearch::SHARE_ALL,
            'share_targets' => [],
        ]);

        $this->be($nonAdmin);
        // The XHR header is what the admin permission layer keys on: without it a
        // denial renders an HTML error page with status 200 instead of 403.
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->delete(admin_urls('search', 'saved', $saved->id))
            ->assertStatus(403);
        $this->assertNotNull(MeiliSavedSearch::find((int) $saved->id));
    }
}
