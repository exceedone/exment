<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\ExmentIndexer;
use Exceedone\Exment\Services\Meili\FilterConfig;
use Exceedone\Exment\Services\Meili\IndexingContext;
use Exceedone\Exment\Services\Meili\IndexSettings;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Services\Meili\MeiliIndexOwnership;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The search screens as a viewer who may read a table but not the records its
 * linked column points at - over HTTP, through the real controllers and views.
 *
 * Two things only show up at this level:
 *
 *  - the header autocomplete must not turn into a yes/no oracle for names in a
 *    table the viewer cannot open, even when a record they CAN write carries
 *    the highlight marker as plain text;
 *  - a readable attribute path the index does not know (a freeword column no
 *    document holds a value for) must not take the results page down to MySQL
 *    or answer a download with a 500.
 *
 * A live Meilisearch is needed, and the test builds a throwaway index of its
 * own; everything skips when there is none.
 */
class MeiliPermissionScreensTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    private const LINKED_TABLE = 'torihikisaki';
    private const TABLE = 'keiyaku';

    /** A freeword column of TABLE left empty in every document, so the index never learns it. */
    private const EMPTIED_COLUMN = 'kanri_no';

    /**
     * The highlight marker as someone reading the source would write it. The real
     * markers carry a per-process nonce (MeiliSearchService::highlightPre()), so
     * this is what a record can actually contain - and it must not be taken for a
     * match.
     */
    private const GUESSED_MARKER = '__EXM_HL__';

    /**
     * One index for the whole class, not one per test: a Meilisearch index
     * reserves a large memory mapping whether or not it holds anything, and
     * four of them at once is enough to take a developer machine down.
     */
    private static ?string $sharedIndex = null;

    /** @var array{0:string,1:string} */
    private static array $sharedBuilt = ['', ''];

    /** Kept so the drop below can reach Meilisearch after the application is gone. */
    private static ?string $host = null;
    private static ?string $key = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['meilisearch.global_search' => true]);
        // The throwaway index holds one small table; asking Meilisearch for the
        // production over-fetch of 1000 hits per box only costs memory.
        config(['meilisearch.permission_scan_cap' => 50]);
        ExmentIndexer::forgetIndexProbes();
    }

    protected function tearDown(): void
    {
        ExmentIndexer::forgetIndexProbes();
        System::clearCache();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$sharedIndex !== null) {
            try {
                // Built by hand, not through MeiliClientFactory: by now the
                // application is torn down and config() is gone.
                $client = new \Meilisearch\Client((string) self::$host, self::$key);
                foreach ([self::$sharedIndex, MeiliIndexOwnership::markerIndexName(self::$sharedIndex)] as $name) {
                    $client->waitForTask($client->deleteIndex($name)['taskUid'], 30000);
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, "\n[MeiliPermissionScreensTest] could not drop " . self::$sharedIndex . ': ' . $e->getMessage() . "\n");
            }
            self::$sharedIndex = null;
        }

        parent::tearDownAfterClass();
    }

    // ---- the header autocomplete -------------------------------------------

    /**
     * The keyword matches only the supplier name, which this viewer may not
     * read, so there must be no suggestion - and the marker typed into the
     * `note` column must not change that.
     */
    public function testTheHeaderDoesNotConfirmAHiddenLinkedName(): void
    {
        $viewer = $this->contractViewer();
        [$supplier, $title] = $this->buildIndex();

        $this->be($viewer);
        $response = $this->get(admin_url('search/header') . '?query=' . rawurlencode($supplier));

        $response->assertStatus(200);
        $this->assertSame(
            [],
            $response->json(),
            "typing '{$supplier}' must not confirm that a record by that name exists"
        );
    }

    /**
     * The control for the test above: a keyword matching a column the viewer
     * MAY read still answers, so an empty answer there means something.
     */
    public function testTheHeaderStillSuggestsOnAReadableColumn(): void
    {
        $viewer = $this->contractViewer();
        [$supplier, $title] = $this->buildIndex();

        $this->be($viewer);
        $response = $this->get(admin_url('search/header') . '?query=' . rawurlencode($title));

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json(), "'{$title}' matches a readable column and must suggest");
    }

    // ---- the results page ---------------------------------------------------

    /**
     * ReadableAttributes hands Meilisearch every readable freeword path,
     * EMPTIED_COLUMN among them, and the index has never seen that one. Left
     * alone Meilisearch answers 400, the box falls back to MySQL and - with a
     * filter applied - reports that the filter could not be used.
     */
    public function testTheResultsBoxSurvivesAnAttributeTheIndexDoesNotKnow(): void
    {
        $viewer = $this->contractViewer();
        [$supplier, $title] = $this->buildIndex();

        $this->be($viewer);
        $response = $this->get(
            admin_url('search/list')
            . '?query=' . rawurlencode($title)
            . '&table_name=' . self::TABLE
            . '&date_from=2000-01-01'
        );

        $response->assertStatus(200);
        $body = (string) ($response->json()['body'] ?? '');
        $this->assertStringNotContainsString(
            exmtrans('search.filter_unavailable'),
            $body,
            'the search must not have fallen back to MySQL'
        );
        $this->assertSame(1, (int) ($response->json()['total'] ?? 0), 'the matching contract must be listed');
    }

    // ---- the export ---------------------------------------------------------

    /**
     * There is no MySQL export to fall back to, and the writer normally never
     * returns at all (it sends the file and exits). A search that cannot run
     * has to come back as a message on screen, not as a 500 answering a
     * download.
     */
    public function testAFailedSearchAnswersTheExportOnScreen(): void
    {
        $viewer = $this->contractViewer();
        $this->buildIndex();

        // The index is marked built, so the export still goes through Meili -
        // and the host it then talks to is not there.
        ExmentIndexer::markIndexBuilt((string) config('meilisearch.index'));
        config(['meilisearch.host' => 'http://127.0.0.1:7999']);
        ExmentIndexer::forgetIndexProbes();

        $this->be($viewer);
        // As AJAX: a deny would otherwise take the pjax branch, which streams a
        // whole page and exit()s - taking the test runner with it.
        $response = $this->get(
            admin_url('search/export')
            . '?query=x&table_name=' . self::TABLE . '&format=csv',
            ['X-Requested-With' => 'XMLHttpRequest']
        );

        $response->assertStatus(302);
        $this->assertStringContainsString(
            exmtrans('search.export_failed'),
            json_encode(session()->get('toastr'), JSON_UNESCAPED_UNICODE) ?: ''
        );
    }

    // ---- fixtures -----------------------------------------------------------

    /**
     * A non-administrator who may read the contracts but none of the suppliers
     * they point at. The permission is added to a role group they are already
     * in and rolled back with the test.
     */
    private function contractViewer(): LoginUser
    {
        $table = CustomTable::getEloquent(self::TABLE);
        $linked = CustomTable::getEloquent(self::LINKED_TABLE);
        if (!$table || !$linked) {
            $this->markTestSkipped('the test data has no ' . self::TABLE . ' / ' . self::LINKED_TABLE . ' table');
        }

        foreach (LoginUser::all() as $user) {
            $this->be($user);
            System::clearRequestSession();

            $exmentUser = \Exment::user();
            if (!$exmentUser || $exmentUser->isAdministrator()) {
                continue;
            }
            if (getModelName($linked)::query()->count() > 0) {
                continue;   // they may read the suppliers, so nothing is hidden
            }
            $roleGroupId = RoleGroupUserOrganization::where('role_group_user_org_type', SystemTableName::USER)
                ->where('role_group_target_id', $exmentUser->getUserId())
                ->value('role_group_id');
            if (!$roleGroupId) {
                continue;
            }

            RoleGroupPermission::create([
                'role_group_id' => $roleGroupId,
                'role_group_permission_type' => RoleType::TABLE,
                'role_group_target_id' => $table->id,
                'permissions' => ['custom_value_view_all', 'custom_value_export'],
            ]);
            System::clearCache();
            System::clearRequestSession();
            $this->be($user);
            System::clearRequestSession();

            if (getModelName($table)::query()->count() > 0) {
                return $user;
            }
        }

        $this->markTestSkipped('no login user who can be given the contracts without the suppliers');
    }

    /**
     * A throwaway index holding this table's records as the queue worker would
     * write them - the supplier names resolved in full - with two changes that
     * put the two screens under test:
     *
     *  - the highlight marker typed into the readable `note` column, as a
     *    viewer able to edit the record could;
     *  - EMPTIED_COLUMN blanked, so the index never learns that path while
     *    ReadableAttributes still hands it over.
     *
     * @return array{0:string,1:string} a hidden supplier name, and a title that is readable
     */
    private function buildIndex(): array
    {
        // config() and the "index is built" mark are per test, the index itself
        // is not: re-point at it and carry on.
        if (self::$sharedIndex !== null) {
            config(['meilisearch.index' => self::$sharedIndex]);
            ExmentIndexer::forgetIndexProbes();
            ExmentIndexer::markIndexBuilt(self::$sharedIndex);

            return self::$sharedBuilt;
        }

        $client = MeiliClientFactory::make();
        try {
            $client->health();
        } catch (\Throwable $e) {
            $this->markTestSkipped('needs a running Meilisearch: ' . $e->getMessage());
        }

        $table = CustomTable::getEloquent(self::TABLE);
        $mapper = new DocumentMapper();
        $columns = $table->getFreewordSearchColumns();
        $linkedColumn = $columns->first(
            fn ($c) => \Exceedone\Exment\Enums\ColumnType::isSelectTable($c->column_type)
        );
        if (!$linkedColumn) {
            $this->markTestSkipped(self::TABLE . ' has no freeword linked column');
        }

        $docs = [];
        $supplier = null;
        $title = null;
        IndexingContext::run(function () use ($table, $mapper, $columns, $linkedColumn, &$docs, &$supplier, &$title) {
            $records = getModelName($table)::query()
                ->withoutGlobalScope(CustomValueModelScope::class)
                ->get();
            foreach ($records as $record) {
                $record->setValue(self::EMPTIED_COLUMN, null);
                $record->setValue('note', self::GUESSED_MARKER . ' ' . (string) $record->getValue('note', true));
                $docs[] = $mapper->map(
                    $record,
                    $columns,
                    $table->table_name,
                    $table->table_view_name,
                    FilterConfig::equalityColumns($table),
                    FilterConfig::rangeColumns($table),
                    FilterConfig::aliasMap($table)
                );
                if ($supplier === null) {
                    $value = $record->getValue($linkedColumn, true);
                    $supplier = is_array($value) ? (string) reset($value) : (string) $value;
                    $title = (string) $record->label;
                }
            }
        });

        if (empty($docs) || $supplier === '' || $supplier === null) {
            $this->markTestSkipped('the test data has no indexable contract with a supplier');
        }

        $index = 'exment_test_screens_' . substr(md5((string) mt_rand()), 0, 8);
        $client->waitForTask($client->createIndex($index, ['primaryKey' => 'id'])['taskUid'], 30000);
        $client->waitForTask($client->index($index)->updateSettings(IndexSettings::build(IndexSettings::fromSystem()))['taskUid'], 60000);
        $client->waitForTask($client->index($index)->addDocuments($docs, 'id')['taskUid'], 60000);

        config(['meilisearch.index' => $index]);
        ExmentIndexer::forgetIndexProbes();
        ExmentIndexer::markIndexBuilt($index);

        self::$sharedIndex = $index;
        self::$sharedBuilt = [$supplier, $title];
        self::$host = (string) config('meilisearch.host');
        self::$key = config('meilisearch.key');

        return self::$sharedBuilt;
    }
}
