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
 * Unique by table_name, and the lock is released once the job starts. A save
 * made while a continuation slice waits in the queue is dropped by that lock;
 * the slices detect it through configHash() and restart the pass.
 */
class ReindexMeiliTableJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use JobTrait;

    public int $backoff = 10;

    /** Hold the unique lock for at most 5 minutes in case the job hangs. */
    public int $uniqueFor = 300;

    /**
     * @param string $tableName
     * @param int|null $afterId Start of this slice: the last id the previous run
     *   indexed. null = start at the beginning of the table.
     * @param string|null $configHash configHash() the earlier slices of this
     *   chain indexed with. null = first slice, or unknown.
     */
    public function __construct(public string $tableName, public ?int $afterId = null, public ?string $configHash = null)
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
                self::warnAdmin();
                return;
            }

            // May be dropped without a word: while a continuation slice of this
            // table waits in the queue it holds the unique lock. handle() catches
            // that case by comparing configHash(), not by trusting this dispatch.
            self::dispatch($tableName)->delay(now()->addSeconds(self::DISPATCH_DELAY));
        } catch (\Throwable $e) {
            // A search-index problem must never break the user's save.
            Log::warning('[Meili] reindex dispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * Fingerprint of everything a document of this table is built from, other
     * than the record itself: the exact arguments DocumentMapper::map() gets.
     * Options are included whole - over-triggering a restart costs a pass,
     * missing a change leaves documents wrong.
     *
     * @param iterable<mixed> $columns
     * @param iterable<mixed> $facetColumns
     * @param iterable<mixed> $rangeColumns
     * @param array<string,string> $aliases
     */
    public static function configHash(string $tableLabel, iterable $columns, iterable $facetColumns, iterable $rangeColumns, array $aliases): string
    {
        // array_map, not ->map(): IndexPipelineTest reads every "->map(" in this
        // file as a DocumentMapper::map() call site and checks its arguments.
        $describe = fn ($list) => array_map(
            fn ($c) => [(string) $c->column_name, (string) $c->column_type, $c->options ?? null],
            collect($list)->values()->all()
        );

        ksort($aliases);

        return md5((string) json_encode([
            $tableLabel,
            $describe($columns),
            $describe($facetColumns),
            $describe($rangeColumns),
            $aliases,
        ]));
    }

    /**
     * True when the earlier slices of this chain were indexed with a different
     * configuration than the one in force now.
     *
     * That happens when the table is saved while a continuation slice waits in
     * the queue: the continuation holds the unique lock, so the save's own
     * reindex is dropped. Kept out of the cache on purpose - Exment flushes the
     * whole cache store when a table or column is saved, which is exactly when
     * this matters.
     */
    public static function configChangedMidChain(?int $afterId, ?string $chainHash, string $currentHash): bool
    {
        // First slice: nothing indexed yet. No hash: queued by an older version.
        if ($afterId === null || $chainHash === null) {
            return false;
        }

        return $chainHash !== $currentHash;
    }

    /**
     * Say it on screen, not only in the log: the settings screen otherwise
     * reports a plain success while the index silently stays stale.
     */
    public static function warnAdmin(): void
    {
        try {
            if (app()->runningInConsole()) {
                return;
            }
            // Flash, not admin_warning: it only lives for the current request on
            // exment-admin-core and is lost on the redirect after saving.
            session()->flash('warning', new \Illuminate\Support\MessageBag([
                'title' => exmtrans('search.reindex_skipped'),
                'message' => '',
            ]));
        } catch (\Throwable $e) {
            // A notification must never break the user's save.
        }
    }

    /**
     * Id the NEXT run has to start after, or null when this run reached the end
     * of the table. A slice shorter than the chunk size means there is no more.
     *
     * @param array<int,int|string> $ids ids indexed by this run, in id order
     */
    public static function nextAfterId(array $ids, int $chunkSize): ?int
    {
        if (empty($ids) || count($ids) < max(1, $chunkSize)) {
            return null;
        }

        return (int) max(array_map('intval', $ids));
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
        $this->resetRequestSessionOnWorker();

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
        // Smaller than batch_size on purpose: one slice must finish well inside
        // the job timeout, and indexing costs tens of milliseconds per record.
        $chunkSize = max(1, (int) config('meilisearch.reindex_chunk_size', 500));

        // Computed from the very values this slice maps with, so it describes
        // exactly what these documents are built from.
        $hash = self::configHash((string) $tableLabel, $columns, $facetColumns, $rangeColumns, $aliases);
        if (self::configChangedMidChain($this->afterId, $this->configHash, $hash)) {
            // The earlier slices carry the old configuration. Start over rather
            // than finish: every slice still to come would be redone anyway. If
            // the save's own job did get queued it holds the lock and this
            // dispatch is dropped - that job is a full pass, so nothing is lost.
            self::dispatch($this->tableName)->delay(now()->addSeconds(self::DISPATCH_DELAY));
            return;
        }

        // Scope dropped: see ExmentIndexer's class docblock.
        $records = getModelName($table)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->when($this->afterId !== null, fn ($query) => $query->where('id', '>', $this->afterId))
            ->orderBy('id')
            ->limit($chunkSize)
            ->get();

        $ids = [];
        $docs = [];
        foreach ($records as $record) {
            $ids[] = $record->id;
            $docs[] = $mapper->map($record, $columns, $tableName, $tableLabel, $facetColumns, $rangeColumns, $aliases);
        }

        if (!empty($docs)) {
            $task = $index->addDocuments($docs, 'id');
            $client->waitForTask($task['taskUid'], 60000);
        }

        $next = self::nextAfterId($ids, $chunkSize);
        if ($next !== null) {
            // More to walk. While queued, the continuation holds the unique
            // lock, so a save made meanwhile cannot queue its own job; the hash
            // handed over here is how the continuation notices that save.
            self::dispatch($this->tableName, $next, $hash);
            return;
        }

        // Last slice: records deleted since the previous run keep a document
        // nothing points at. Ids only, so this stays cheap on a large table.
        $dbIds = getModelName($table)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->pluck('id')->all();
        $service = new MeiliSearchService($client, $indexName);
        $orphan = MeiliSearchService::diffIds($dbIds, $service->indexedValueIds($this->tableName))['orphan'];

        // Re-check just before deleting: a record created during the scan exists now.
        $existingNow = [];
        foreach (array_chunk($orphan, 1000) as $chunk) {
            $existingNow = array_merge($existingNow, getModelName($table)::query()
                ->withoutGlobalScope(CustomValueModelScope::class)
                ->whereIn('id', $chunk)
                ->pluck('id')->all());
        }
        $service->deleteByValueIds($this->tableName, self::deletableOrphans($orphan, $existingNow), $mapper);
    }

    /**
     * Orphan ids minus those that exist in the database right now.
     *
     * @param  array<int,int|string>  $orphan
     * @param  array<int,int|string>  $existingNow
     * @return array<int,int|string>
     */
    public static function deletableOrphans(array $orphan, array $existingNow): array
    {
        $existing = array_flip(array_map('intval', $existingNow));

        return array_values(array_filter($orphan, fn ($id) => !isset($existing[(int) $id])));
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
