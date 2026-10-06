<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\ExmentIndexer;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Illuminate\Console\Command;

/**
 * Reconcile the Meilisearch index against MySQL and repair any drift.
 *
 * Incremental sync runs through a queue, so the index can drift from the source
 * of truth: missed events, failed jobs, bulk operations, mass deletes. This
 * command compares, per table, the ids that SHOULD be indexed (MySQL) against
 * the value_ids currently in the index, then:
 *  - indexes documents that are missing (new records not yet synced);
 *  - removes orphan documents (records deleted but still in the index).
 *
 * The id comparison alone never notices that a document's ATTACHMENTS drifted:
 * a file added or removed while the attachment queue had no worker leaves the
 * record present but its file list stale. --attachments adds that comparison
 * and prunes extraction-cache rows whose file is gone.
 *
 * Use --dry-run to report the drift without changing anything.
 */
class MeiliReconcileCommand extends Command
{
    use CommandTrait;
    use MeiliCommandTrait;

    protected $signature = 'exment:meili-reconcile {--dry-run : Report drift only, do not modify the index}
        {--table= : Reconcile only this table_name}
        {--attachments : Also repair attachment drift and prune the extraction cache}';

    protected $description = 'Reconcile the Meilisearch index against MySQL and repair drift (missing/orphan documents)';

    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();
    }

    public function handle(): int
    {
        if (!$this->assertMeiliSdkInstalled()) {
            return self::FAILURE;
        }

        $client = MeiliClientFactory::make();

        try {
            $client->health();
        } catch (\Throwable $e) {
            $this->error('Could not connect to Meilisearch (' . config('meilisearch.host') . '): ' . $e->getMessage());
            return self::FAILURE;
        }

        $indexName = config('meilisearch.index');
        $mapper = new DocumentMapper();
        $service = new MeiliSearchService($client, $indexName);
        $indexer = new ExmentIndexer($client, $mapper, $indexName, (int) config('meilisearch.batch_size', 1000));

        $only = $this->option('table');
        $dryRun = (bool) $this->option('dry-run');

        $tables = $indexer->searchableTables();
        if ($only) {
            $tables = $tables->filter(fn ($t) => $t->table_name === $only)->values();
            if ($tables->isEmpty()) {
                $this->error('Table not found or not search-enabled: ' . $only);
                return self::FAILURE;
            }
        }

        $totalMissing = 0;
        $totalOrphan = 0;

        foreach ($tables as $table) {
            $tableName = $table->table_name;

            // Ids that should be indexed = current (non-deleted) records of the table.
            $dbIds = ExmentIndexer::recordsQuery($table)
                ->pluck('id')->all();

            try {
                $indexIds = $service->indexedValueIds($tableName);
            } catch (\Throwable $e) {
                $this->warn(sprintf('  %-30s skipped (cannot read index: %s)', $tableName, $e->getMessage()));
                continue;
            }

            $diff = MeiliSearchService::diffIds($dbIds, $indexIds);
            $missing = $diff['missing'];
            $orphan = $diff['orphan'];

            if (empty($missing) && empty($orphan)) {
                $this->line(sprintf('  %-30s OK', $tableName));
                continue;
            }

            $totalMissing += count($missing);
            $totalOrphan += count($orphan);

            $action = $dryRun ? '(dry-run)' : '';
            $this->line(sprintf('  %-30s missing=%d orphan=%d %s', $tableName, count($missing), count($orphan), $action));

            if ($dryRun) {
                continue;
            }

            if (!empty($missing)) {
                $indexer->reindexIds($table, $missing);
            }
            if (!empty($orphan)) {
                $service->deleteByValueIds($tableName, $orphan, $mapper);
            }
        }

        $totalAttachments = 0;
        $prunedCacheRows = 0;
        if ($this->option('attachments')) {
            foreach ($tables as $table) {
                $totalAttachments += $this->repairAttachments($table, $service, $indexer, $dryRun);
            }
            if (!$dryRun) {
                try {
                    $prunedCacheRows = \Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextCache::pruneOrphanRows();
                } catch (\Throwable $e) {
                    $this->warn('  Could not prune the extraction cache: ' . $e->getMessage());
                }
            }
            $this->line(sprintf(
                '  attachments: %d record(s) drifted, %d orphan cache row(s) %s',
                $totalAttachments,
                $prunedCacheRows,
                $dryRun ? '(dry-run, cache left alone)' : 'pruned'
            ));
        }

        // A table that stopped being search-enabled leaves every one of its
        // documents behind: the per-table loop above only visits tables that
        // still qualify, so nothing would ever come back for them.
        $totalStale = $only ? 0 : $this->purgeStaleTables($client, $indexName, $indexer, $dryRun);

        $verb = $dryRun ? 'found' : 'repaired';
        $this->info(sprintf(
            'Reconcile done. %s: %d missing, %d orphan, %d document(s) of de-indexed tables.',
            $verb,
            $totalMissing,
            $totalOrphan,
            $totalStale
        ));

        return self::SUCCESS;
    }

    /**
     * Re-map the records of one table whose indexed attachment set no longer
     * matches the database. Returns how many records that covered.
     *
     * @param \Exceedone\Exment\Model\CustomTable $table
     * @param \Exceedone\Exment\Services\Meili\MeiliSearchService $service
     * @param \Exceedone\Exment\Services\Meili\ExmentIndexer $indexer
     */
    protected function repairAttachments($table, $service, $indexer, bool $dryRun): int
    {
        $tableName = $table->table_name;

        try {
            $indexed = $service->indexedAttachmentUuids($tableName);
            $current = \Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextCache::currentAttachmentUuids($tableName);
        } catch (\Throwable $e) {
            $this->warn(sprintf('  %-30s attachment check skipped (%s)', $tableName, $e->getMessage()));
            return 0;
        }

        // Only records that should hold a document: the id loop above already
        // removes the documents of the ones that should not.
        $allowed = ExmentIndexer::recordsQuery($table)->pluck('id')->all();
        $drifted = MeiliSearchService::recordsWithDriftedAttachments($indexed, $current, $allowed);
        if (empty($drifted)) {
            return 0;
        }

        if (!$dryRun) {
            $indexer->reindexIds($table, $drifted);
        }

        return count($drifted);
    }

    /**
     * Delete the documents of tables that are in the index but no longer
     * qualify for it. Returns how many documents that covered.
     *
     * @param \Meilisearch\Client $client
     * @param \Exceedone\Exment\Services\Meili\ExmentIndexer $indexer
     */
    protected function purgeStaleTables($client, string $indexName, $indexer, bool $dryRun): int
    {
        try {
            // One query, no documents fetched: the facet distribution lists every
            // table_name present with its document count.
            $distribution = $client->index($indexName)
                ->search('', ['limit' => 0, 'facets' => ['table_name']])
                ->getFacetDistribution()['table_name'] ?? [];
        } catch (\Throwable $e) {
            $this->warn('  could not list indexed tables: ' . $e->getMessage());
            return 0;
        }

        $searchable = $indexer->searchableTables()->pluck('table_name')->all();
        $total = 0;

        foreach ($distribution as $tableName => $count) {
            if (in_array((string) $tableName, $searchable, true)) {
                continue;
            }

            $total += (int) $count;
            $this->line(sprintf('  %-30s de-indexed, %d document(s) %s', $tableName, $count, $dryRun ? '(dry-run)' : 'removed'));

            if ($dryRun) {
                continue;
            }

            $task = $client->index($indexName)->deleteDocuments([
                'filter' => ReindexMeiliTableJob::tableFilter((string) $tableName),
            ]);
            $client->waitForTask($task['taskUid'], 60000);
        }

        return $total;
    }
}
