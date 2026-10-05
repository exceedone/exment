<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\FilterConfig;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Services\Meili\MeiliSync;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Rebuild the documents of records that link to a record whose label changed
 * (documents store the linked record's label). One slice per run, chained like
 * ReindexMeiliTableJob, walking the linking tables one after another.
 */
class SyncMeiliReferencesJob implements ShouldQueue
{
    use JobTrait;

    public int $backoff = 10;

    /**
     * @param mixed $valueId id of the record whose label changed
     * @param int $tableIndex position in MeiliSync::referencingColumns()
     * @param int|null $afterId last record id handled in that table
     */
    public function __construct(public string $tableName, public $valueId, public int $tableIndex = 0, public ?int $afterId = null)
    {
        $this->timeout = 60;
        $this->afterCommit();

        // Scans the linking tables, so it stays off the per-record sync queue.
        try {
            $this->onQueue(config('meilisearch.reindex_queue', 'meili-reindex'));
        } catch (\Throwable $e) {
            $this->onQueue('meili-reindex');
        }
    }

    /**
     * True when running inline (sync driver) would map more records than batch_size inside the save request.
     *
     * @param CustomTable $refTable
     * @param mixed $valueId
     */
    public static function wouldBlockTheCaller($refTable, $valueId): bool
    {
        if (config('queue.default') !== 'sync') {
            return false;
        }

        $limit = max(1, (int) config('meilisearch.batch_size', 1000));
        $found = 0;
        foreach (MeiliSync::referencingColumns($refTable) as [$table, $linkColumns]) {
            $found += self::referencingQuery($table, $linkColumns, $valueId)->limit($limit + 1 - $found)->pluck('id')->count();
            if ($found > $limit) {
                return true;
            }
        }
        return false;
    }

    public function handle(): void
    {
        $this->resetRequestSessionOnWorker();
        // The save request may hold this record from before the change (sync driver): read the new label.
        System::clearRequestSession(sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALUE, $this->tableName, $this->valueId));

        $refTable = CustomTable::getEloquent($this->tableName);
        $refs = $refTable ? MeiliSync::referencingColumns($refTable) : [];
        if (!isset($refs[$this->tableIndex])) {
            return;
        }
        [$table, $linkColumns] = $refs[$this->tableIndex];

        $chunkSize = max(1, (int) config('meilisearch.reindex_chunk_size', 500));
        $records = self::referencingQuery($table, $linkColumns, $this->valueId)
            ->when($this->afterId !== null, fn ($query) => $query->where('id', '>', $this->afterId))
            ->orderBy('id')
            ->limit($chunkSize)
            ->get();

        if ($records->isNotEmpty()) {
            $mapper = new DocumentMapper();
            $columns = $table->getFreewordSearchColumns();
            $facetColumns = FilterConfig::equalityColumns($table);
            $rangeColumns = FilterConfig::rangeColumns($table);
            $aliases = FilterConfig::aliasMap($table);

            $docs = [];
            foreach ($records as $record) {
                $docs[] = $mapper->map($record, $columns, $table->table_name, $table->table_view_name, $facetColumns, $rangeColumns, $aliases);
            }
            $client = MeiliClientFactory::make();
            \Exceedone\Exment\Services\Meili\ExmentIndexer::ensureIndexExists($client, config('meilisearch.index'));
            $task = $client->index(config('meilisearch.index'))->addDocuments($docs, 'id');
            $client->waitForTask($task['taskUid'], 60000);
        }

        $next = ReindexMeiliTableJob::nextAfterId($records->pluck('id')->all(), $chunkSize);
        if ($next !== null) {
            self::dispatch($this->tableName, $this->valueId, $this->tableIndex, $next);
        } elseif (isset($refs[$this->tableIndex + 1])) {
            self::dispatch($this->tableName, $this->valueId, $this->tableIndex + 1);
        }
    }

    /**
     * Records of $table whose link columns point to $valueId.
     *
     * @param CustomTable $table
     * @param iterable<\Exceedone\Exment\Model\CustomColumn> $linkColumns
     * @param mixed $valueId
     * @return \Illuminate\Database\Eloquent\Builder<\Exceedone\Exment\Model\CustomValue>
     */
    protected static function referencingQuery($table, iterable $linkColumns, $valueId)
    {
        $dbTable = getDBTableName($table);

        return getModelName($table)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->where(function ($query) use ($linkColumns, $dbTable, $valueId) {
                foreach ($linkColumns as $column) {
                    $jsonKey = 'value->' . $column->column_name;
                    if (boolval($column->getOption('multiple_enabled'))) {
                        $query->orWhereJsonContains($jsonKey, (string) $valueId)
                            ->orWhereJsonContains($jsonKey, (int) $valueId);
                        continue;
                    }
                    // Indexed columns have a generated column; never create one here.
                    $indexName = $column->getIndexColumnName(false);
                    $key = $column->index_enabled && hasColumn($dbTable, $indexName) ? $indexName : $jsonKey;
                    $query->orWhere($key, (string) $valueId);
                }
            });
    }
}
