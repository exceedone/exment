<?php

namespace Exceedone\Exment\Services\Meili;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
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
                    foreach ($records as $record) {
                        $docs[] = $this->mapper->map($record, $columns, $tableName, $tableLabel, $facetColumns, $rangeColumns, $aliases);
                    }

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
            foreach ($records as $record) {
                $docs[] = $this->mapper->map($record, $columns, $tableName, $tableLabel, $facetColumns, $rangeColumns, $aliases);
            }
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
        try {
            $configured = in_array('table_name', $client->index($indexName)->getFilterableAttributes(), true);
        } catch (\Meilisearch\Exceptions\ApiException $e) {
            if ($e->httpStatus !== 404) {
                throw $e;
            }
            $configured = false;
        }
        if (!$configured) {
            (new self($client, new DocumentMapper(), $indexName, (int) config('meilisearch.batch_size')))->ensureIndex();
        }
        self::$seenAt[$indexName] = time();
    }

    public function ensureIndex(): void
    {
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
