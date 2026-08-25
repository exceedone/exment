<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Reindex Meilisearch for ONE custom table when the table/column config changes.
 *
 * Overwrites the table's documents in place, then removes the ones no record
 * points at any more. Wiping first is only correct if the refill is guaranteed
 * to finish, and it is not: a queue timeout would leave the table unsearchable.
 *
 * Unique by table_name, and the lock is released once the job starts, so a save
 * made DURING a reindex still schedules the next one.
 */
class ReindexMeiliTableJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use JobTrait;

    public int $backoff = 10;

    /** Hold the unique lock for at most 5 minutes in case the job hangs. */
    public int $uniqueFor = 300;

    public function __construct(public string $tableName)
    {
        // Below the connection's retry_after (database: 90s) so a second worker
        // cannot re-reserve a still-running job. Set here, not as a property:
        // redeclaring a trait property with a different value is a PHP fatal.
        $this->timeout = 60;

        $this->afterCommit();

        // Own queue so a long reindex never blocks the per-record sync jobs:
        //   php artisan queue:work --queue=default,<reindex_queue>
        // try/catch: a bare unit test constructs this with no app/config.
        try {
            $this->onQueue(config('meilisearch.reindex_queue', 'meili-reindex'));
        } catch (\Throwable $e) {
            $this->onQueue('meili-reindex');
        }
    }

    public function uniqueId(): string
    {
        return $this->tableName;
    }

    /**
     * Seconds the dispatch is held back, so a screen saving many rows in one
     * request produces one job that reads the finished state, not N jobs racing
     * the save. The unique lock collapses the rest of the burst.
     */
    public const DISPATCH_DELAY = 5;

    public static function dispatchUnlessBlocking(string $tableName): void
    {
        try {
            if (self::wouldBlockTheCaller($tableName)) {
                Log::warning(
                    "[Meili] reindex of '{$tableName}' skipped: the queue connection is 'sync',"
                    . ' which would run it inline. Run `php artisan exment:meili-index` after the change.'
                );
                return;
            }

            self::dispatch($tableName)->delay(now()->addSeconds(self::DISPATCH_DELAY));
        } catch (\Throwable $e) {
            // A search-index problem must never break the user's save.
            Log::warning('[Meili] reindex dispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * True when the job would run inline AND the table is big enough for that to
     * hurt. The batch size is the natural threshold: it is what one round trip
     * to Meilisearch is already sized for.
     */
    protected static function wouldBlockTheCaller(string $tableName): bool
    {
        if (config('queue.default') !== 'sync') {
            return false;
        }

        $table = CustomTable::getEloquent($tableName);
        if (!$table) {
            return false;
        }

        // exists() past the threshold, not count(): a limit does not bound
        // count(*), so counting would scan the whole table on every save.
        $threshold = max(1, (int) config('meilisearch.batch_size', 1000));

        return getModelName($table)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->offset($threshold)
            ->limit(1)
            ->exists();
    }

    /**
     * Meilisearch filter to select every document belonging to a table.
     */
    public static function tableFilter(string $tableName): string
    {
        return 'table_name = ' . MeiliSearchService::quoteFilterValue($tableName);
    }

    public function handle(): void
    {
        $client = MeiliClientFactory::make();
        $indexName = config('meilisearch.index');
        $index = $client->index($indexName);
        $mapper = new DocumentMapper();

        // Table gone or no longer search-enabled: this is the ONLY case that wipes.
        $table = CustomTable::getEloquent($this->tableName);
        if (!$this->shouldIndex($table)) {
            $task = $index->deleteDocuments(['filter' => self::tableFilter($this->tableName)]);
            $client->waitForTask($task['taskUid'], 60000);
            return;
        }

        $columns = $table->getFreewordSearchColumns();
        $facetColumns = \Exceedone\Exment\Services\Meili\FilterConfig::equalityColumns($table);
        $rangeColumns = \Exceedone\Exment\Services\Meili\FilterConfig::rangeColumns($table);
        $aliases = \Exceedone\Exment\Services\Meili\FilterConfig::aliasMap($table);
        $tableName = $table->table_name;
        $tableLabel = $table->table_view_name;
        $batchSize = max(1, (int) config('meilisearch.batch_size', 1000));

        // Overwrite in place instead of wiping first: addDocuments upserts on the
        // primary key, so a job killed halfway leaves the index stale, never empty.
        // Batches are queued without waiting between them - Meilisearch processes
        // them in order, so one wait at the end covers the lot.
        $dbIds = [];
        $lastTask = null;

        // Scope dropped: see ExmentIndexer's class docblock.
        getModelName($table)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->chunkById($batchSize, function ($records) use ($index, $mapper, $columns, $facetColumns, $rangeColumns, $aliases, $tableName, $tableLabel, &$dbIds, &$lastTask) {
                $docs = [];
                foreach ($records as $record) {
                    $dbIds[] = $record->id;
                    $docs[] = $mapper->map($record, $columns, $tableName, $tableLabel, $facetColumns, $rangeColumns, $aliases);
                }

                if (!empty($docs)) {
                    $lastTask = $index->addDocuments($docs, 'id')['taskUid'];
                }
            });

        if ($lastTask !== null) {
            $client->waitForTask($lastTask, 60000);
        }

        // Records deleted since the last run keep a document nothing points at.
        $service = new MeiliSearchService($client, $indexName);
        $orphan = MeiliSearchService::diffIds($dbIds, $service->indexedValueIds($this->tableName))['orphan'];
        $service->deleteByValueIds($this->tableName, $orphan, $mapper);
    }

    /**
     * A table large enough to outrun the queue timeout never finishes here, and
     * every retry restarts from the top. The documents are safe - they are
     * overwritten in place - but they stay stale until someone reindexes, so say
     * so instead of leaving a bare MaxAttemptsExceededException in the log.
     */
    public function failed(\Throwable $e): void
    {
        Log::warning(
            "[Meili] reindex of '{$this->tableName}' gave up after {$this->tries} attempts:"
            . ' ' . $e->getMessage()
            . ' The table keeps its previous documents; run `php artisan exment:meili-index` to refresh them.'
        );
    }

    /**
     * Whether the table should be in the index (matches the global search/Indexer criteria).
     *
     * @param CustomTable|null $table
     */
    private function shouldIndex($table): bool
    {
        return \Exceedone\Exment\Services\Meili\ExmentIndexer::isIndexable($table);
    }
}
