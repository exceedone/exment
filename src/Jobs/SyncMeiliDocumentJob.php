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

    /** How long to wait for Meilisearch to process this one document. */
    public const TASK_WAIT_MS = 10000;

    /**
     * @param mixed $valueId
     */
    public function __construct(
        public string $tableName,
        public $valueId,
        public string $action // 'upsert' | 'delete' - kept for callers; handle() reads the record instead
    ) {
        // Saves run inside a transaction: a worker picking this up before the
        // commit would find() nothing and delete the document instead.
        $this->afterCommit();

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
     * The record state decides, not $action: two jobs for the same record can be
     * processed in any order by parallel workers, and a delete job that trusts
     * its own verb would drop the document of a record that has been restored
     * since. Reading the row makes both jobs converge on the same result, so the
     * order they run in stops mattering. $action is kept for the queued payloads
     * of older versions.
     */
    public function handle(): void
    {
        $this->resetRequestSessionOnWorker();

        $client = MeiliClientFactory::make();
        $indexName = config('meilisearch.index');
        $index = $client->index($indexName);
        $mapper = new DocumentMapper();
        $documentId = $mapper->makeDocumentId($this->tableName, $this->valueId);

        // Re-checked every minute, not once per worker: the index can be dropped under a running worker.
        \Exceedone\Exment\Services\Meili\ExmentIndexer::ensureIndexExists($client, $indexName, 60);

        $table = CustomTable::getEloquent($this->tableName);
        // Table gone: the whole table's documents are handled by ReindexMeiliTableJob.
        if (!$table) {
            return;
        }

        $record = getModelName($table)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->find($this->valueId);

        if (!$record) {
            $this->report($client, $index->deleteDocument($documentId), 'delete');
            return;
        }

        $doc = $mapper->map($record, $table->getFreewordSearchColumns(), $table->table_name, $table->table_view_name, \Exceedone\Exment\Services\Meili\FilterConfig::equalityColumns($table), \Exceedone\Exment\Services\Meili\FilterConfig::rangeColumns($table), \Exceedone\Exment\Services\Meili\FilterConfig::aliasMap($table));
        $this->report($client, $index->addDocuments([$doc], 'id'), 'upsert');
    }

    /**
     * Meilisearch processes writes asynchronously: the job is "done" the moment
     * the task is accepted, so a task that then fails leaves the document out of
     * the index with nothing anywhere to say so.
     *
     * @param \Meilisearch\Client $client
     * @param array<string,mixed> $task
     */
    private function report($client, array $task, string $what): void
    {
        // Sync driver: this runs inside the request that saved the record, and
        // the write is already on its way - do not make the user wait for it.
        if ($this->job !== null && $this->job instanceof \Illuminate\Queue\Jobs\SyncJob) {
            return;
        }

        try {
            $result = $client->waitForTask($task['taskUid'], static::TASK_WAIT_MS);
        } catch (\Throwable $e) {
            // Still queued on a busy Meilisearch: not an error of this job.
            return;
        }

        if (($result['status'] ?? null) === 'failed') {
            \Illuminate\Support\Facades\Log::warning(sprintf(
                "[Meili] %s of '%s' #%s failed: %s",
                $what,
                $this->tableName,
                (string) $this->valueId,
                (string) ($result['error']['message'] ?? 'unknown error')
            ));
        }
    }
}
