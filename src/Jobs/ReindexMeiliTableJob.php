<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reindex Meilisearch for ONE custom table when the table/column config changes.
 *
 * Always wipes the table's old documents first (handles cases: search disabled,
 * freeword column removed, column changed, table deleted). Then, if the table
 * still qualifies for indexing, reloads all current records.
 *
 * ShouldBeUnique by table_name: many rapid column saves merge into a single job.
 */
class ReindexMeiliTableJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    /** Hold the unique lock for at most 5 minutes in case the job hangs. */
    public int $uniqueFor = 300;

    public function __construct(public string $tableName)
    {
        // Heavy job (reindexes a whole table, can take minutes). Route it to a
        // separate queue so it never blocks the light per-record sync jobs on
        // the default queue. Run a worker with:
        //   php artisan queue:work --queue=default,<reindex_queue>
        // so the default queue is always drained first.
        // try/catch: constructing the job in a bare unit test has no app/config.
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
     * Meilisearch filter to select every document belonging to a table.
     */
    public static function tableFilter(string $tableName): string
    {
        return 'table_name = ' . MeiliSearchService::quoteFilterValue($tableName);
    }

    public function handle(): void
    {
        $client = MeiliClientFactory::make();
        $index = $client->index(config('meilisearch.index'));
        $mapper = new DocumentMapper();

        // 1) Always wipe this table's old documents.
        $task = $index->deleteDocuments(['filter' => self::tableFilter($this->tableName)]);
        $client->waitForTask($task['taskUid'], 60000);

        // 2) Table still exists + qualifies for indexing? If not -> stop (deletion done).
        $table = CustomTable::getEloquent($this->tableName);
        if (!$this->shouldIndex($table)) {
            return;
        }

        // 3) Reload all current records of the table.
        $columns = $table->getFreewordSearchColumns();
        $facetColumns = \Exceedone\Exment\Services\Meili\FilterConfig::equalityColumns($table);
        $rangeColumns = \Exceedone\Exment\Services\Meili\FilterConfig::rangeColumns($table);
        $aliases = \Exceedone\Exment\Services\Meili\FilterConfig::aliasMap($table);
        $tableName = $table->table_name;
        $tableLabel = $table->table_view_name;
        $batchSize = (int) config('meilisearch.batch_size', 1000);

        getModelName($table)::query()->chunkById($batchSize, function ($records) use ($index, $client, $mapper, $columns, $facetColumns, $rangeColumns, $aliases, $tableName, $tableLabel) {
            $docs = [];
            foreach ($records as $record) {
                $docs[] = $mapper->map($record, $columns, $tableName, $tableLabel, $facetColumns, $rangeColumns, $aliases);
            }

            if (!empty($docs)) {
                $task = $index->addDocuments($docs, 'id');
                $client->waitForTask($task['taskUid'], 60000);
            }
        });
    }

    /**
     * Whether the table should be in the index (matches the global search/Indexer criteria).
     */
    private function shouldIndex($table): bool
    {
        if (!$table) {
            return false;
        }

        if (!boolval($table->getOption('search_enabled'))) {
            return false;
        }

        return $table->getFreewordSearchColumns()->isNotEmpty();
    }
}
