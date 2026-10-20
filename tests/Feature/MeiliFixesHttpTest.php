<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Meili\ExmentIndexer;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Services\Meili\MeiliIndexOwnership;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The search screens over HTTP, for the paths the unit tests cannot reach:
 * the sidebar is only really exercised once the blade renders it, and the two
 * guards that answer "do not use Meilisearch" have to come out as a working
 * page on MySQL, not a 500.
 *
 * A live Meilisearch is needed for the ownership cases (they create a marker
 * index); those skip when there is none.
 */
class MeiliFixesHttpTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    /** @var array<int,string> indexes created here, dropped in tearDown */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['meilisearch.global_search' => true]);
        ExmentIndexer::markIndexBuilt((string) config('meilisearch.index'));
        ExmentIndexer::forgetIndexProbes();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        if (!empty($this->created)) {
            $client = MeiliClientFactory::make();
            foreach ($this->created as $index) {
                try {
                    $client->deleteIndex($index);
                } catch (\Throwable $e) {
                    // nothing to drop
                }
            }
            $this->created = [];
        }

        ExmentIndexer::forgetIndexProbes();
        System::clearCache();
        parent::tearDown();
    }

    /**
     * The filter sidebar renders through the batched (multi-search) path.
     *
     * A viewer with restricted tables is the case that fans out: the counts used
     * to cost one query per restricted table per checkbox group.
     */
    public function testSearchPageRendersTheSidebarForARestrictedViewer(): void
    {
        $this->be($this->restrictedUser());

        $response = $this->get(admin_url('search') . '?query=1');

        $response->assertStatus(200);
        // The sidebar form itself, so a page that silently rendered without it fails.
        $response->assertSee('meili-filter', false);
        $response->assertSee('filter-title', false);
    }

    /**
     * Groups with a selection are re-counted, which is what multiplies the
     * queries - and what the batch has to return the same numbers for.
     */
    public function testSearchPageRendersWithSeveralFacetsTicked(): void
    {
        $this->be($this->restrictedUser());

        $query = '?query=1&date_from=2025-01-01';
        foreach (['workflow1::status=1', 'custom_value_edit_all::multiples_of_3=NO'] as $i => $token) {
            $query .= '&facets[' . $i . ']=' . urlencode($token);
        }

        $this->get(admin_url('search') . $query)->assertStatus(200);
    }

    /** The header autocomplete, the endpoint the widened read serves. */
    public function testHeaderSuggestionsAnswerJson(): void
    {
        $this->be($this->restrictedUser());

        $response = $this->get(admin_url('search/header') . '?query=1');

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    /**
     * An index owned by another installation must take the screen down the
     * MySQL path, not break it.
     */
    public function testSearchPageFallsBackWhenTheIndexBelongsToAnotherInstallation(): void
    {
        $index = $this->foreignOwnedIndex();

        config(['meilisearch.index' => $index]);
        ExmentIndexer::markIndexBuilt($index);
        MeiliIndexOwnership::forget();

        $this->assertFalse(ExmentIndexer::indexIsBuilt(), 'a foreign index must not count as built');
        $this->get(admin_url('search') . '?query=1')->assertStatus(200);
        $this->get(admin_url('search/header') . '?query=1')->assertStatus(200);
    }

    /**
     * And the same for a Meilisearch that cannot be reached at all: the backoff
     * must not leak out of the page.
     */
    public function testSearchPageSurvivesAnUnreachableMeilisearch(): void
    {
        config(['meilisearch.host' => 'http://127.0.0.1:7999']);
        ExmentIndexer::forgetIndexProbes();

        $this->get(admin_url('search') . '?query=1')->assertStatus(200);
        $this->get(admin_url('search/header') . '?query=1')->assertStatus(200);
        // Second time round the probe is skipped, and the page is still fine.
        $this->get(admin_url('search') . '?query=1')->assertStatus(200);
    }

    /** A throwaway index whose owner marker names a different installation. */
    private function foreignOwnedIndex(): string
    {
        $client = MeiliClientFactory::make();

        try {
            $client->health();
        } catch (\Throwable $e) {
            $this->markTestSkipped('needs a running Meilisearch: ' . $e->getMessage());
        }

        $index = 'exment_test_foreign_' . substr(md5((string) mt_rand()), 0, 8);
        $marker = MeiliIndexOwnership::markerIndexName($index);
        $this->created = [$index, $marker];

        foreach ([$index, $marker] as $name) {
            $client->waitForTask($client->createIndex($name, ['primaryKey' => 'id'])['taskUid'], 30000);
        }
        $client->waitForTask($client->index($marker)->addDocuments([[
            'id' => MeiliIndexOwnership::MARKER_ID,
            'fingerprint' => str_repeat('b', 32),
        ]], 'id')['taskUid'], 30000);

        return $index;
    }

    /**
     * A non-administrator: the only kind of viewer that has restricted tables,
     * because ReadableAttributes::forTable() lets an administrator read it all.
     */
    private function restrictedUser(): LoginUser
    {
        foreach (LoginUser::all() as $user) {
            $this->be($user);
            System::clearRequestSession();
            $exmentUser = \Exment::user();
            if ($exmentUser && !$exmentUser->isAdministrator()) {
                return $user;
            }
        }

        $this->markTestSkipped('no non-administrator login user in the test data');
    }
}
