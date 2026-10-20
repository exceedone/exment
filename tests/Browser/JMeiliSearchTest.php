<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Meili\ExmentIndexer;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Services\Meili\MeiliIndexOwnership;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * The search screens as a browser gets them: the rendered DOM, and forms
 * submitted the way the page submits them.
 *
 * What the HTTP-level tests cannot answer is whether the page a guard sends the
 * viewer to is a working screen. Both guards added for the Meilisearch findings
 * end in "do not use the index", and a 200 says nothing about whether the
 * sidebar came out half-built or the results boxes are there at all.
 *
 * No javascript runs here, so the parts of the sidebar that are scripted (the
 * "N more" expander, the sort select, the per-table result boxes that load over
 * ajax) are out of scope.
 */
class JMeiliSearchTest extends ExmentKitTestCase
{
    use DatabaseTransactions;

    /** @var array<int,string> indexes made here, dropped in tearDown */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['meilisearch.global_search' => true]);
        ExmentIndexer::markIndexBuilt((string) config('meilisearch.index'));
        ExmentIndexer::forgetIndexProbes();
        $this->login();
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
     * The sidebar is there, and it is a real form with a filter button - not
     * just the markup of an empty box.
     */
    public function testSearchPageRendersTheFilterSidebar(): void
    {
        $this->visit(admin_url('search') . '?query=test')
            ->seeElement('div[class="meili-filter"]')
            ->seeOuterElement('button[type="submit"]', trans('admin.filter'))
            ->seeElement('input[name="tables[]"]')
            ->see(exmtrans('custom_table.table'));
    }

    /**
     * Tick a table facet and press the sidebar's own filter button: the page
     * comes back with the applied-filter chip, and the box stays ticked.
     */
    public function testPressingFilterWithATableTickedShowsTheChip(): void
    {
        $this->visit(admin_url('search') . '?query=test');

        $boxes = $this->crawler->filter('input[name="tables[]"]');
        $this->assertGreaterThan(0, $boxes->count(), 'the sidebar offered no table to tick');
        $value = (string) $boxes->first()->attr('value');

        $this->submitForm(trans('admin.filter'), ['tables' => [$value]])
            ->seeElement('div[class="meili-applied"]')
            ->seeElement('a[class="applied-chip"]')
            // The sidebar is still there after the round trip, with the box ticked.
            ->seeElement('div[class="meili-filter"]')
            ->seeElement('input[name="tables[]"][value="' . $value . '"][checked]');
    }

    /**
     * The export of a search whose matches run past the cap has to come back as
     * a refusal on screen, not as a file that quietly holds a fraction of the
     * rows. meili_bulk is granted to login_user 12 record by record (see
     * tools/meili-testdata), so the viewer's rows are scattered past the cap.
     */
    public function testExportOfTooManyMatchesComesBackAsAMessage(): void
    {
        $this->login(12);

        $url = admin_url('search/export') . '?' . http_build_query([
            'query' => '',
            'table_name' => 'meili_bulk',
            'format' => 'csv',
        ]);

        $cap = max(1, (int) config('meilisearch.permission_scan_cap', 1000));
        $expected = sprintf(exmtrans('search.export_too_many'), $cap);

        $this->visit(admin_url('search') . '?query=')
            ->visit($url);

        $this->assertStringNotContainsString(
            'search/export',
            (string) $this->currentUri,
            'the export must not have been sent as a file'
        );
        // The refusal, on the screen the viewer is sent back to.
        $this->see($expected);
    }

    /**
     * An index another installation owns sends the screen down the MySQL path.
     * That path renders the same view without the sidebar, so the test is that
     * the results page is whole AND the Meili-only furniture is gone - a page
     * with a sidebar but no counts would pass a status check and still be wrong.
     */
    public function testForeignIndexFallsBackToAWorkingPage(): void
    {
        $index = $this->foreignOwnedIndex();
        config(['meilisearch.index' => $index]);
        ExmentIndexer::markIndexBuilt($index);
        MeiliIndexOwnership::forget();

        $this->visit(admin_url('search') . '?query=test')
            ->see(sprintf(exmtrans('search.result_label'), 'test'))
            ->dontSeeElement('div[class="meili-filter"]')
            ->dontSeeElement('div[class="meili-applied"]');
    }

    /**
     * A Meilisearch nothing can connect to is NOT treated as a foreign index:
     * ownership comes back "cannot tell", so the Meili branch still runs and the
     * screen keeps its sidebar - without counts, because the distribution query
     * is caught and logged. That is Exment's existing behaviour for an outage
     * (see MeiliSearchFilterTest), and what matters here is that the breaker
     * does not turn it into an error page.
     */
    public function testUnreachableMeilisearchStillRendersTheScreen(): void
    {
        config(['meilisearch.host' => 'http://127.0.0.1:7999']);
        ExmentIndexer::forgetIndexProbes();

        $this->visit(admin_url('search') . '?query=test')
            ->see(sprintf(exmtrans('search.result_label'), 'test'))
            ->seeElement('div[class="meili-filter"]')
            // The groups are built from the counts, so an outage leaves none.
            ->dontSeeElement('input[name="facets[]"]');
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

        $index = 'exment_ui_foreign_' . substr(md5((string) mt_rand()), 0, 8);
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
}
