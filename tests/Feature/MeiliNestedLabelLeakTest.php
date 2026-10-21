<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumnMulti;
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
use Exceedone\Exment\Services\Meili\GlobalSearch\ReadableAttributes;
use Exceedone\Exment\Services\Meili\SavedSearchService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;

/**
 * A linked value is withheld from a viewer by rendering it and looking for the
 * "no permission" text. Both guards that decide whether a path may be searched
 * used to stop one link short:
 *
 *  - ReadableAttributes::isHiddenColumn() called a column safe as soon as the
 *    table it points at was fully visible, so the path stayed in
 *    attributesToSearchOn;
 *  - HeaderSuggester::isReadableField() returned true on the same test without
 *    ever rendering the value, so the snippet was shown.
 *
 * Neither holds once the TARGET's own label is built from a further link: the
 * document carries that second-level label as the indexer resolved it, with no
 * user and therefore no filtering. The viewer then reads a name out of a table
 * they cannot open - printed in the suggestion, not merely confirmed.
 *
 * The chain this builds, all of it rolled back with the test:
 *
 *   pivot_table.child_relation_filter -> child_table (fully visible)
 *   child_table's label               -> its `user` column
 *   user                              -> filtered to the viewer's own organizations
 */
class MeiliNestedLabelLeakTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    private const A = 'pivot_table';
    private const B = 'child_table';
    private const LINK = 'child_relation_filter';

    /** One index for the whole class: each one costs a large memory mapping. */
    private static ?string $sharedIndex = null;
    private static ?string $host = null;
    private static ?string $key = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['meilisearch.global_search' => true]);
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
                $client = new \Meilisearch\Client((string) self::$host, self::$key);
                foreach ([self::$sharedIndex, MeiliIndexOwnership::markerIndexName(self::$sharedIndex)] as $name) {
                    $client->waitForTask($client->deleteIndex($name)['taskUid'], 30000);
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, "\n[MeiliNestedLabelLeakTest] could not drop " . self::$sharedIndex . ': ' . $e->getMessage() . "\n");
            }
            self::$sharedIndex = null;
        }

        parent::tearDownAfterClass();
    }

    /**
     * The path must be withheld, which also means the viewer gets a narrowed
     * list at all: null here means "nothing is hidden from them", and
     * Meilisearch is then asked to match the whole document.
     */
    public function testTheReadablePathsStopAtASecondLevelLink(): void
    {
        $this->scenario();

        $paths = ReadableAttributes::forTable(CustomTable::getEloquent(self::A));

        $this->assertNotNull($paths, 'a table reachable through a hidden name is not "nothing hidden"');
        $this->assertNotContains('fields.' . self::LINK, $paths);
    }

    /**
     * The names themselves must never reach the suggestion list.
     *
     * Not "no suggestion at all": typo tolerance means a hidden name also
     * matches similar names the viewer MAY read, and answering on those is
     * correct. What must never happen is a hidden name being printed.
     */
    public function testTheHeaderDoesNotPrintASecondLevelHiddenName(): void
    {
        [$viewer, $hidden, $probe, $readable] = $this->scenarioWithIndex();

        $this->be($viewer);
        $response = $this->get(admin_url('search/header') . '?query=' . rawurlencode($probe));

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        foreach ($hidden as $name) {
            $this->assertStringNotContainsString(
                $name,
                $body,
                "'{$name}' belongs to a table this viewer cannot open"
            );
        }
    }

    /** The control: a keyword this viewer may be matched on still answers. */
    public function testTheHeaderStillSuggestsOnAReadableKeyword(): void
    {
        [$viewer, $hidden, $probe, $readable] = $this->scenarioWithIndex();

        $this->be($viewer);
        $response = $this->get(admin_url('search/header') . '?query=' . rawurlencode($readable));

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json(), "'{$readable}' is readable and must still suggest");
    }

    /**
     * And the results page, which is the other half: with no narrowed paths
     * Meilisearch matched the hidden name and listed every record carrying it.
     */
    public function testTheResultsPageDoesNotMatchOnASecondLevelHiddenName(): void
    {
        [$viewer, $hidden, $probe, $readable] = $this->scenarioWithIndex();

        $this->be($viewer);
        $response = $this->get(
            admin_url('search/list') . '?query=' . rawurlencode($probe) . '&table_name=' . self::A
        );

        $response->assertStatus(200);
        $this->assertSame(0, (int) ($response->json()['total'] ?? 0));
    }

    // ---- fixtures -----------------------------------------------------------

    /**
     * Build the chain and return the viewer it is hidden from.
     */
    private function scenario(): LoginUser
    {
        $a = CustomTable::getEloquent(self::A);
        $b = CustomTable::getEloquent(self::B);
        $parent = CustomTable::getEloquent('parent_table');
        if (!$a || !$b || !$parent) {
            $this->markTestSkipped('the test data has no ' . self::A . ' / ' . self::B . ' chain');
        }

        // Users outside the viewer's own organizations become invisible.
        System::filter_multi_user((string) \Exceedone\Exment\Enums\JoinedMultiUserFilterType::ONLY_JOIN);

        $this->labelBy($b, 'user');
        // The record's OWN label must stay the same for the viewer as in the
        // index, or permittedSnippet drops the hit on that test instead and the
        // path under test is never reached.
        $this->labelBy($a, 'parent');

        $viewer = $this->viewerWithout($a);
        foreach ([$a->id, $b->id, $parent->id] as $tableId) {
            RoleGroupPermission::create([
                'role_group_id' => $this->roleGroupOf($viewer),
                'role_group_permission_type' => RoleType::TABLE,
                'role_group_target_id' => $tableId,
                'permissions' => ['custom_value_view_all'],
            ]);
        }
        System::clearCache();
        $this->be($viewer);
        System::clearRequestSession();

        if (getModelName($a)::query()->count() === 0) {
            $this->markTestSkipped('the viewer cannot be given ' . self::A);
        }
        if (!SavedSearchService::canViewAllRows($b)) {
            $this->markTestSkipped('the viewer cannot be given all of ' . self::B);
        }

        return $viewer;
    }

    /**
     * The same chain, with a throwaway index holding this table's documents as
     * the queue worker writes them.
     *
     * @return array{0:LoginUser,1:array<int,string>,2:string,3:string}
     *   viewer, the names it must never print, one of them to type, a readable keyword
     */
    private function scenarioWithIndex(): array
    {
        $viewer = $this->scenario();
        $a = CustomTable::getEloquent(self::A);

        // Built with no viewer, exactly as the indexer does.
        \Auth::guard('admin')->logout();
        System::clearRequestSession();
        $mapper = new DocumentMapper();
        $columns = $a->getFreewordSearchColumns();
        $docs = [];
        IndexingContext::run(function () use ($a, $mapper, $columns, &$docs) {
            $records = getModelName($a)::query()
                ->withoutGlobalScope(CustomValueModelScope::class)
                ->limit(100)
                ->get();
            foreach ($records as $record) {
                $docs[] = $mapper->map(
                    $record,
                    $columns,
                    $a->table_name,
                    $a->table_view_name,
                    FilterConfig::equalityColumns($a),
                    FilterConfig::rangeColumns($a),
                    FilterConfig::aliasMap($a)
                );
            }
        });

        $this->be($viewer);
        System::clearRequestSession();

        // A record whose link the viewer sees as "no permission" - so the test
        // is standing on the case under test - and a keyword they may read.
        $readable = null;
        $noPermission = exmtrans('common.message.no_permission');
        foreach ($docs as $doc) {
            $record = getModelName($a)::query()->find($doc['value_id']);
            if (!$record) {
                continue;
            }
            if (strpos((string) $record->getValue(self::LINK, true), $noPermission) === false) {
                continue;
            }
            $readable = (string) $record->label;
            break;
        }
        if ($readable === null || $readable === '') {
            $this->markTestSkipped('no record whose linked label hides a name from this viewer');
        }

        $hidden = $this->namesHiddenFromTheViewer();
        if (empty($hidden)) {
            $this->markTestSkipped('every user is visible to this viewer, so nothing can leak');
        }

        $this->loadIndex($docs);

        return [$viewer, $hidden, $hidden[0], $readable];
    }

    /**
     * The user names this viewer may not read - and only the ones that cannot
     * turn up as part of a name they MAY read, so the assertion on the
     * response body stays unambiguous.
     *
     * @return array<int,string>
     */
    private function namesHiddenFromTheViewer(): array
    {
        $users = CustomTable::getEloquent(SystemTableName::USER);
        $visible = getModelName($users)::query()->get()->map(fn ($u) => (string) $u->label)->all();
        $all = getModelName($users)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->get()
            ->map(fn ($u) => (string) $u->label)
            ->all();

        $hidden = [];
        foreach (array_diff($all, $visible) as $name) {
            if ($name === '') {
                continue;
            }
            foreach ($visible as $shown) {
                if (strpos($shown, $name) !== false) {
                    continue 2;   // a substring of a name they may read: unusable here
                }
            }
            $hidden[] = $name;
        }

        return array_values($hidden);
    }

    /** Make $column the table's label. */
    private function labelBy(CustomTable $table, string $column): void
    {
        $custom_column = $table->custom_columns_cache->first(fn ($c) => $c->column_name === $column);
        if (!$custom_column) {
            $this->markTestSkipped("{$table->table_name} has no '{$column}' column");
        }

        CustomColumnMulti::create([
            'custom_table_id' => $table->id,
            'multisetting_type' => MultisettingType::TABLE_LABELS,
            'options' => ['table_label_id' => (string) $custom_column->id],
        ]);
        System::clearRequestSession();
    }

    /** A non-administrator who cannot read $table yet and belongs to a role group. */
    private function viewerWithout(CustomTable $table): LoginUser
    {
        foreach (LoginUser::all() as $user) {
            $this->be($user);
            System::clearRequestSession();

            $exmentUser = \Exment::user();
            if (!$exmentUser || $exmentUser->isAdministrator()) {
                continue;
            }
            if ($exmentUser->hasPermission(\Exceedone\Exment\Enums\Permission::FILTER_MULTIUSER_ALL)) {
                continue;   // they may read every user, so nothing is hidden
            }
            if ($this->roleGroupOf($user) !== null) {
                return $user;
            }
        }

        $this->markTestSkipped('no non-administrator login user in a role group');
    }

    /** @return int|null */
    private function roleGroupOf(LoginUser $user)
    {
        return RoleGroupUserOrganization::where('role_group_user_org_type', SystemTableName::USER)
            ->where('role_group_target_id', $user->getUserId())
            ->value('role_group_id');
    }

    /**
     * @param array<int,array<string,mixed>> $docs
     */
    private function loadIndex(array $docs): void
    {
        $client = MeiliClientFactory::make();
        try {
            $client->health();
        } catch (\Throwable $e) {
            $this->markTestSkipped('needs a running Meilisearch: ' . $e->getMessage());
        }

        if (self::$sharedIndex === null) {
            $index = 'exment_test_nested_' . substr(md5((string) mt_rand()), 0, 8);
            $client->waitForTask($client->createIndex($index, ['primaryKey' => 'id'])['taskUid'], 30000);
            $client->waitForTask($client->index($index)->updateSettings(IndexSettings::build(IndexSettings::fromSystem()))['taskUid'], 60000);
            $result = $client->waitForTask($client->index($index)->addDocuments($docs, 'id')['taskUid'], 60000);
            if (($result['status'] ?? null) !== 'succeeded') {
                $this->markTestSkipped('Meilisearch could not take the documents: ' . json_encode($result['error'] ?? null));
            }

            self::$sharedIndex = $index;
            self::$host = (string) config('meilisearch.host');
            self::$key = config('meilisearch.key');
        }

        config(['meilisearch.index' => self::$sharedIndex]);
        ExmentIndexer::forgetIndexProbes();
        ExmentIndexer::markIndexBuilt(self::$sharedIndex);
    }
}
