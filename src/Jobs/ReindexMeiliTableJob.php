<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

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
    use JobTrait;

    public int $backoff = 10;

    /** Hold the unique lock for at most 5 minutes in case the job hangs. */
    public int $uniqueFor = 300;

    public function __construct(public string $tableName)
    {
        // MUST stay below the connection's retry_after (database: 90s), or a
        // second worker re-reserves this job and two "wipe then refill" passes
        // race on the same table. Set here, not as a property: redeclaring a
        // trait property with a different value is a PHP fatal.
        $this->timeout = 60;

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
        // max(1): chunkById(0) would break out after step 1 already wiped the
        // table's documents, leaving it empty.
        $batchSize = max(1, (int) config('meilisearch.batch_size', 1000));

        // Scope dropped: see ExmentIndexer's class docblock.
        getModelName($table)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->chunkById($batchSize, function ($records) use ($index, $client, $mapper, $columns, $facetColumns, $rangeColumns, $aliases, $tableName, $tableLabel) {
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
     *
     * @param CustomTable|null $table
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
