<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Sync one Exment document to Meilisearch (runs in background via queue).
 * Only passes scalars (table_name, value_id, action) for safe serialization.
 */
class SyncMeiliDocumentJob implements ShouldQueue
{
    use JobTrait;

    public int $backoff = 10;

    /**
     * @param mixed $valueId
     */
    public function __construct(
        public string $tableName,
        public $valueId,
        public string $action // 'upsert' | 'delete'
    ) {
        // Light job (one document, ~tens of ms). Stays on the priority queue so
        // record changes reflect in the index quickly, never waiting behind the
        // heavy table reindex job (see ReindexMeiliTableJob).
        // try/catch: constructing the job in a bare unit test has no app/config.
        try {
            $this->onQueue(config('meilisearch.sync_queue', 'default'));
        } catch (\Throwable $e) {
            $this->onQueue('default');
        }
    }

    /**
     * Per-process memo: whether the index is known to exist with settings
     * applied. Avoids one extra HTTP check per synced record on long-running
     * workers.
     */
    protected static bool $indexVerified = false;

    public function handle(): void
    {
        $client = MeiliClientFactory::make();
        $indexName = config('meilisearch.index');
        $index = $client->index($indexName);
        $mapper = new DocumentMapper();

        // Delete the document.
        if ($this->action === 'delete') {
            $index->deleteDocument($mapper->makeDocumentId($this->tableName, $this->valueId));
            return;
        }

        // Upserting into a missing index would let Meilisearch auto-create it
        // with default settings (no filterableAttributes), breaking every
        // filtered search until `exment:meili-index` runs. Create it properly instead.
        if (!self::$indexVerified) {
            try {
                $client->getRawIndex($indexName);
            } catch (\Throwable $e) {
                (new \Exceedone\Exment\Services\Meili\ExmentIndexer(
                    $client,
                    $mapper,
                    $indexName,
                    (int) config('meilisearch.batch_size')
                ))->ensureIndex();
            }
            self::$indexVerified = true;
        }

        $table = CustomTable::getEloquent($this->tableName);
        if (!$table) {
            return;
        }

        // Upsert: reload the record. If it has been deleted -> delete the document.
        $record = getModelName($table)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->find($this->valueId);
        if (!$record) {
            $index->deleteDocument($mapper->makeDocumentId($this->tableName, $this->valueId));
            return;
        }

        $doc = $mapper->map($record, $table->getFreewordSearchColumns(), $table->table_name, $table->table_view_name, \Exceedone\Exment\Services\Meili\FilterConfig::equalityColumns($table), \Exceedone\Exment\Services\Meili\FilterConfig::rangeColumns($table), \Exceedone\Exment\Services\Meili\FilterConfig::aliasMap($table));
        $index->addDocuments([$doc], 'id');
    }
}
