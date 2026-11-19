<?php

namespace Exceedone\Exment\Services\Meili;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Collection;
use Meilisearch\Client;

/**
 * Push Exment data (search-enabled custom tables) into ONE combined Meilisearch index.
 *
 * The indexing criteria match Exment's global search:
 *  - Table: CustomTable::searchEnabled() with >=1 freeword column.
 *  - Column: index_enabled && freeword_search (getFreewordSearchColumns()).
 *
 * Reads drop CustomValueModelScope (the index is shared by all users), but keep
 * SoftDeletes - hence withoutGlobalScope, not withoutGlobalScopes.
 */
class ExmentIndexer
{
    /**
     * Clamped in the constructor: 0 from the system setting would index nothing.
     *
     * @var int<1,max>
     */
    private int $batchSize;

    public function __construct(
        private Client $client,
        private DocumentMapper $mapper,
        private string $indexName,
        int $batchSize
    ) {
        $this->batchSize = max(1, $batchSize);
    }

    /**
     * Record that $indexName holds a complete build.
     *
     * Without this, an index that was never built is indistinguishable from one
     * where nothing matched: Meilisearch answers "no results" either way, which
     * is a valid answer, so nothing falls back to MySQL and records simply go
     * missing from the search. The index name is stored with it, so pointing
     * MEILISEARCH_INDEX at a different index does not inherit the mark.
     */
    public static function markIndexBuilt(string $indexName): void
    {
        try {
            System::meili_indexed_at(['index' => $indexName, 'at' => now()->getTimestamp()]);
            System::clearCache();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Meili] could not record the index build: ' . $e->getMessage());
        }
    }

    /**
     * Whether the index in use has been built at least once.
     */
    public static function indexIsBuilt(): bool
    {
        try {
            $mark = (array) System::meili_indexed_at();
        } catch (\Throwable $e) {
            return false;
        }

        $indexName = (string) config('meilisearch.index');
        if (($mark['index'] ?? null) !== $indexName) {
            return false;
        }

        // An index another installation owns holds that installation's records:
        // searching it would answer this installation's users with content from
        // a system they have no account on. MySQL is the honest answer instead.
        try {
            return MeiliIndexOwnership::isOwned(MeiliClientFactory::make(), $indexName);
        } catch (\Throwable $e) {
            return true;    // cannot tell (no SDK, no client) -> behave as before
        }
    }

    /**
     *
     * @param  mixed  $table
     */
    public static function isIndexable($table): bool
    {
        if (!$table) {
            return false;
        }

        return boolval($table->getOption('search_enabled'))
            && $table->getFreewordSearchColumns()->isNotEmpty();
    }

    /**
     * List of custom tables to index (search-enabled + having freeword columns).
     *
     * @return Collection<int,CustomTable>
     */
    public function searchableTables(): Collection
    {
        return CustomTable::searchEnabled()->get()->filter(function (CustomTable $table) {
            return self::isIndexable($table);
        })->values();
    }

    /**
     * Suffix of the index a --fresh rebuild is built into before the swap.
     */
    public const BUILD_SUFFIX = '_building';

    /**
     * Name of the index a --fresh rebuild writes to.
     */
    public static function buildIndexName(string $indexName): string
    {
        return $indexName . self::BUILD_SUFFIX;
    }

    /**
     * Index all data.
     *
     * $fresh rebuilds from scratch. It builds into a separate index and swaps it
     * in at the end, because deleting the live index first leaves the search
     * answering "no results" - a valid, empty answer, so nothing falls back to
     * MySQL - for as long as the rebuild takes.
     *
     * @return array{total:int, perTable:array<string,int>}
     */
    public function indexAll(bool $fresh = false): array
    {
        $target = $fresh ? self::buildIndexName($this->indexName) : $this->indexName;

        if ($fresh) {
            $this->prepareBuildIndex($target);
        } else {
            $this->ensureIndex();
        }

        $index = $this->client->index($target);

        $total = 0;
        $perTable = [];

        foreach ($this->searchableTables() as $table) {
            $columns = $table->getFreewordSearchColumns();
            $facetColumns = FilterConfig::equalityColumns($table);
            $rangeColumns = FilterConfig::rangeColumns($table);
            $aliases = FilterConfig::aliasMap($table);
            $tableName = $table->table_name;
            $tableLabel = $table->table_view_name;
            $count = 0;

            getModelName($table)::query()
                ->withoutGlobalScope(CustomValueModelScope::class)
                ->chunkById($this->batchSize, function ($records) use ($index, $columns, $facetColumns, $rangeColumns, $aliases, $tableName, $tableLabel, &$count) {
                    $docs = [];
                    IndexingContext::run(function () use ($records, $columns, $tableName, $tableLabel, $facetColumns, $rangeColumns, $aliases, &$docs) {
                        foreach ($records as $record) {
                            $docs[] = $this->mapper->map($record, $columns, $tableName, $tableLabel, $facetColumns, $rangeColumns, $aliases);
                        }
                    });

                    if (!empty($docs)) {
                        $task = $index->addDocuments($docs, 'id');
                        $this->client->waitForTask($task['taskUid'], 60000);
                    }

                    $count += $records->count();
                });

            $perTable[$tableName] = $count;
            $total += $count;
        }

        if ($fresh) {
            $this->swapIn($target);
        }

        // Every searchable table has now been walked, so the index is complete.
        self::markIndexBuilt($this->indexName);

        return ['total' => $total, 'perTable' => $perTable];
    }

    /**
     * Create the build index from scratch, with the full settings.
     * A leftover from an interrupted rebuild is dropped first.
     */
    private function prepareBuildIndex(string $buildName): void
    {
        try {
            $task = $this->client->deleteIndex($buildName);
            $this->client->waitForTask($task['taskUid'], 60000);
        } catch (\Throwable $e) {
            // Nothing left over -> ignore.
        }

        $task = $this->client->createIndex($buildName, ['primaryKey' => 'id']);
        $this->client->waitForTask($task['taskUid'], 60000);

        $task = $this->client->index($buildName)->updateSettings(IndexSettings::build(IndexSettings::fromSystem()));
        $this->client->waitForTask($task['taskUid'], 60000);
    }

    /**
     * Put the freshly built index in place of the live one, then drop the old
     * documents (they are under the build name after the swap).
     *
     * The swap itself is atomic: no request ever sees a half-built index. Both
     * sides must exist, so the live index is created first when this is the very
     * first run.
     */
    private function swapIn(string $buildName): void
    {
        $this->ensureIndex();

        $task = $this->client->swapIndexes([[$this->indexName, $buildName]]);
        $this->client->waitForTask($task['taskUid'], 60000);

        try {
            $task = $this->client->deleteIndex($buildName);
            $this->client->waitForTask($task['taskUid'], 60000);
        } catch (\Throwable $e) {
            // The stale documents are out of the way already; leaving the index
            // behind is untidy, not harmful.
        }
    }

    /**
     * (Re)index a specific subset of records of one table (used by reconcile to
     * fill in missing documents). Returns the number of records indexed.
     *
     * @param array<int,int|string> $ids
     */
    public function reindexIds(CustomTable $table, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $columns = $table->getFreewordSearchColumns();
        $facetColumns = FilterConfig::equalityColumns($table);
        $rangeColumns = FilterConfig::rangeColumns($table);
        $aliases = FilterConfig::aliasMap($table);
        $tableName = $table->table_name;
        $tableLabel = $table->table_view_name;
        $index = $this->client->index($this->indexName);
        $count = 0;

        foreach (array_chunk($ids, $this->batchSize) as $chunk) {
            $records = getModelName($table)::query()
                ->withoutGlobalScope(CustomValueModelScope::class)
                ->whereIn('id', $chunk)->get();
            $docs = [];
            IndexingContext::run(function () use ($records, $columns, $tableName, $tableLabel, $facetColumns, $rangeColumns, $aliases, &$docs) {
                foreach ($records as $record) {
                    $docs[] = $this->mapper->map($record, $columns, $tableName, $tableLabel, $facetColumns, $rangeColumns, $aliases);
                }
            });
            if (!empty($docs)) {
                $task = $index->addDocuments($docs, 'id');
                $this->client->waitForTask($task['taskUid'], 60000);
                $count += count($docs);
            }
        }

        return $count;
    }

    /**
     * Ensure the index exists and is configured correctly.
     * Public: SyncMeiliDocumentJob also uses it so a realtime sync arriving
     * before the first `exment:meili-index` run never lets Meilisearch auto-create
     * the index with default settings (no filterableAttributes).
     */
    /** @var array<string,int> index name => when it was last seen to exist, per process */
    private static array $seenAt = [];

    /**
     * Make sure the index exists WITH its settings. Writing into a missing index makes
     * Meilisearch create it with defaults (nothing filterable), so "exists" is not enough.
     * $maxAge > 0 reuses a check that recent (per-record writers).
     */
    public static function ensureIndexExists(Client $client, string $indexName, int $maxAge = 0): void
    {
        if ($maxAge > 0 && time() - (self::$seenAt[$indexName] ?? 0) < $maxAge) {
            return;
        }

        // $seenAt is written on success only, so it never shortens an outage.
        // What keeps an outage from costing every single save a fresh connect
        // timeout is the breaker in MeiliClientFactory, which covers the search
        // path's calls too.
        try {
            // An index another installation owns must not be touched at all: its
            // documents would be overwritten here and deleted as orphans later.
            MeiliIndexOwnership::claim($client, $indexName);

            if (!self::indexIsConfigured($client, $indexName)) {
                self::buildIndex($client, $indexName);
            }
        } catch (\Meilisearch\Exceptions\CommunicationException $e) {
            // Never mark the index as seen on an outage: the next call has to
            // check again once the breaker lets a call through.
            throw $e;
        }

        self::$seenAt[$indexName] = time();
    }

    /**
     * Whether the index is there AND configured; false when it does not exist
     * at all, which is a thing to fix rather than an error to report.
     */
    private static function indexIsConfigured(Client $client, string $indexName): bool
    {
        try {
            return in_array('table_name', $client->index($indexName)->getFilterableAttributes(), true);
        } catch (\Meilisearch\Exceptions\ApiException $e) {
            if ($e->httpStatus !== 404) {
                throw $e;
            }

            return false;
        }
    }

    private static function buildIndex(Client $client, string $indexName): void
    {
        (new self($client, new DocumentMapper(), $indexName, (int) config('meilisearch.batch_size')))->ensureIndex();
    }

    /** Forget this process's index probes (tests, and after the host changes). */
    public static function forgetIndexProbes(): void
    {
        self::$seenAt = [];
        MeiliClientFactory::forgetUnreachable();
        MeiliIndexOwnership::forget();
    }

    public function ensureIndex(): void
    {
        // Every path that writes documents arrives here first, so this is where
        // an index belonging to another installation is refused.
        MeiliIndexOwnership::claim($this->client, $this->indexName);

        try {
            $task = $this->client->createIndex($this->indexName, ['primaryKey' => 'id']);
            $this->client->waitForTask($task['taskUid'], 60000);
        } catch (\Throwable $e) {
            // Index already exists -> ignore.
        }

        // Apply the full settings (searchable weighting, synonyms, stopwords, typo, range fields).
        $index = $this->client->index($this->indexName);
        $task = $index->updateSettings(IndexSettings::build(IndexSettings::fromSystem()));
        $this->client->waitForTask($task['taskUid'], 60000);
    }
}
