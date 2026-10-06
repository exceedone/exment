<?php

namespace Exceedone\Exment\Services\Meili;

use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextCache;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Enums\FileType;
use Illuminate\Support\Collection;
use Meilisearch\Client;

/**
 * Push Exment data (search-enabled custom tables) into ONE combined Meilisearch index.
 *
 * The indexing criteria match Exment's global search:
 *  - Table: CustomTable::searchEnabled() with >=1 freeword column, or with a
 *    business attachment (type 1/2). Attachment-only tables index only the
 *    records that actually own files.
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

    /** A search-enabled table may receive a type-1/type-2 attachment. */
    public static function isAttachmentCapable($table): bool
    {
        return $table && boolval($table->getOption('search_enabled'));
    }

    /**
     * True when this table belongs in the index right now. This retains the
     * old freeword rule and extends it only to tables that actually have a
     * business attachment; it does not add every empty search-enabled table.
     */
    public static function isSearchable($table): bool
    {
        return self::isIndexable($table)
            || (self::isAttachmentCapable($table) && self::hasAttachments((string) $table->table_name));
    }

    public static function hasAttachments(string $tableName): bool
    {
        return File::query()
            ->where('parent_type', $tableName)
            ->tap(fn ($query) => AttachmentTextCache::scopeBusinessFiles($query, $tableName))
            ->exists();
    }

    /**
     * Source records that should have a document. For a normal freeword table
     * that is every non-deleted record; for attachment-only tables it is only
     * records with a type-1/type-2 File row.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Exceedone\Exment\Model\CustomValue>
     */
    public static function recordsQuery($table)
    {
        $query = getModelName($table)::query()
            ->withoutGlobalScope(CustomValueModelScope::class);
        if (!self::isIndexable($table)) {
            $query->whereIn('id', File::query()
                ->select('parent_id')
                ->where('parent_type', $table->table_name)
                ->tap(fn ($query) => AttachmentTextCache::scopeBusinessFiles($query, $table->table_name))
                ->distinct());
        }
        return $query;
    }

    /**
     * List of custom tables to index. Attachment-only tables are included only
     * when they currently own a business attachment.
     *
     * @return Collection<int,CustomTable>
     */
    public function searchableTables(): Collection
    {
        $attachmentTables = File::query()
            ->whereIn('file_type', [FileType::CUSTOM_VALUE_COLUMN, FileType::CUSTOM_VALUE_DOCUMENT])
            ->whereNotNull('parent_type')
            ->distinct()
            ->pluck('parent_type')
            ->all();

        return CustomTable::searchEnabled()->get()->filter(function (CustomTable $table) use ($attachmentTables) {
            return self::isIndexable($table)
                || (self::isAttachmentCapable($table) && in_array($table->table_name, $attachmentTables, true));
        })->values();
    }

    /**
     * Index all data.
     *
     * @return array{total:int, perTable:array<string,int>, pruned:array{orphaned_records:int,deindexed_table_documents:int}}
     */
    public function indexAll(bool $fresh = false): array
    {
        $this->ensureIndex($fresh);
        $index = $this->client->index($this->indexName);

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

            self::recordsQuery($table)
                ->chunkById($this->batchSize, function ($records) use ($index, $columns, $facetColumns, $rangeColumns, $aliases, $tableName, $tableLabel, &$count) {
                    $attachments = \Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextCache::forRecords($tableName, $records->pluck('id')->all());
                    $docs = [];
                    foreach ($records as $record) {
                        $docs[] = $this->mapper->map($record, $columns, $tableName, $tableLabel, $facetColumns, $rangeColumns, $aliases, $attachments);
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

        return [
            'total' => $total,
            'perTable' => $perTable,
            // A normal full index is authoritative, not append-only. Without
            // this cleanup, a record that lost its final attachment while
            // realtime sync was unavailable remains searchable indefinitely.
            'pruned' => $this->pruneStaleDocuments(),
        ];
    }

    /**
     * Remove documents that are no longer generated by the current source
     * rules. This covers both records that lost their final type-1/type-2 file
     * and tables that no longer qualify for search at all.
     *
     * @return array{orphaned_records:int,deindexed_table_documents:int}
     */
    public function pruneStaleDocuments(): array
    {
        $service = new MeiliSearchService($this->client, $this->indexName);
        $tables = $this->searchableTables();
        $orphanedRecords = 0;

        foreach ($tables as $table) {
            $tableName = $table->table_name;
            $dbIds = self::recordsQuery($table)->pluck('id')->all();
            $indexIds = $service->indexedValueIds($tableName);
            $orphan = MeiliSearchService::diffIds($dbIds, $indexIds)['orphan'];
            if (empty($orphan)) {
                continue;
            }

            // Re-check right before deleting, the same way ReindexMeiliTableJob
            // does: $dbIds was read before the index listing, so a record
            // created in between looks orphaned although its document is new.
            $existingNow = [];
            foreach (array_chunk($orphan, 1000) as $chunk) {
                $existingNow = array_merge($existingNow, self::recordsQuery($table)
                    ->whereIn('id', $chunk)
                    ->pluck('id')->all());
            }
            $deletable = ReindexMeiliTableJob::deletableOrphans($orphan, $existingNow);
            if (!empty($deletable)) {
                $service->deleteByValueIds($tableName, $deletable, $this->mapper);
                $orphanedRecords += count($deletable);
            }
        }

        // A table absent from searchableTables() is not visited above, yet it
        // may still have documents left from a prior attachment/full-text
        // state. Faceting gives the complete set of indexed table names without
        // fetching every document.
        $distribution = $this->client->index($this->indexName)
            ->search('', ['limit' => 0, 'facets' => ['table_name']])
            ->getFacetDistribution()['table_name'] ?? [];
        $searchable = $tables->pluck('table_name')->all();
        $deindexedTableDocuments = 0;
        foreach ($distribution as $tableName => $count) {
            if (in_array((string) $tableName, $searchable, true)) {
                continue;
            }

            $task = $this->client->index($this->indexName)->deleteDocuments([
                'filter' => 'table_name = ' . MeiliSearchService::quoteFilterValue((string) $tableName),
            ]);
            $this->client->waitForTask($task['taskUid'], 60000);
            $deindexedTableDocuments += (int) $count;
        }

        return [
            'orphaned_records' => $orphanedRecords,
            'deindexed_table_documents' => $deindexedTableDocuments,
        ];
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
            $records = self::recordsQuery($table)
                ->whereIn('id', $chunk)->get();
            $attachments = \Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextCache::forRecords($tableName, $records->pluck('id')->all());
            $docs = [];
            foreach ($records as $record) {
                $docs[] = $this->mapper->map($record, $columns, $tableName, $tableLabel, $facetColumns, $rangeColumns, $aliases, $attachments);
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
     * Ensure the index exists and is configured correctly. If $fresh: delete then recreate.
     * Public: SyncMeiliDocumentJob also uses it so a realtime sync arriving
     * before the first `exment:meili-index` run never lets Meilisearch auto-create
     * the index with default settings (no filterableAttributes).
     */
    public function ensureIndex(bool $fresh = false): void
    {
        if ($fresh) {
            try {
                $task = $this->client->deleteIndex($this->indexName);
                $this->client->waitForTask($task['taskUid'], 60000);
            } catch (\Throwable $e) {
                // Index does not exist yet -> ignore.
            }
        }

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
