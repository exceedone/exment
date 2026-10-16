<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\ExmentIndexer;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Model\CustomValueModelScope;
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
 * Use --dry-run to report the drift without changing anything.
 */
class MeiliReconcileCommand extends Command
{
    use CommandTrait;
    use MeiliCommandTrait;

    protected $signature = 'exment:meili-reconcile {--dry-run : Report drift only, do not modify the index}
        {--table= : Reconcile only this table_name}';

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

        if (!$this->assertMeiliReachable($client)) {
            return self::FAILURE;
        }

        $indexName = config('meilisearch.index');
        $mapper = new DocumentMapper();
        $service = new MeiliSearchService($client, $indexName);
        $indexer = new ExmentIndexer($client, $mapper, $indexName, (int) config('meilisearch.batch_size', 1000));

        $only = $this->option('table');
        $only = is_string($only) ? $only : null;
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
        $totalOutdated = 0;
        $totalUnverified = 0;
        $totalOrphan = 0;
        $skipped = 0;

        foreach ($tables as $table) {
            $tableName = $table->table_name;

            try {
                $result = $this->reconcileTable($table, $service, $indexer, $mapper, $dryRun);
            } catch (\Throwable $e) {
                $this->warn(sprintf('  %-30s skipped (%s)', $tableName, $e->getMessage()));
                $skipped++;
                continue;
            }

            $totalMissing += $result['missing'];
            $totalOutdated += $result['outdated'];
            $totalUnverified += $result['unverified'];
            $totalOrphan += $result['orphan'];

            if ($result['missing'] === 0 && $result['outdated'] === 0 && $result['orphan'] === 0) {
                $this->line(sprintf(
                    '  %-30s OK%s',
                    $tableName,
                    $result['unverified'] > 0 ? sprintf(' (%d not comparable)', $result['unverified']) : ''
                ));
                continue;
            }

            $this->line(sprintf(
                '  %-30s missing=%d outdated=%d orphan=%d %s',
                $tableName,
                $result['missing'],
                $result['outdated'],
                $result['orphan'],
                $dryRun ? '(dry-run)' : ''
            ));
        }

        // A table that stopped being search-enabled leaves every one of its
        // documents behind: the per-table loop above only visits tables that
        // still qualify, so nothing would ever come back for them.
        $totalStale = $only ? 0 : $this->purgeStaleTables($client, $indexName, $indexer, $dryRun);

        // A clean full pass means every searchable table has been walked and its
        // missing documents filled in, so the index is complete even if it was
        // never built by exment:meili-index (an upgrade, or a lost mark).
        if (!$dryRun && !$only && $skipped === 0) {
            ExmentIndexer::markIndexBuilt($indexName);
        }

        $verb = $dryRun ? 'found' : 'repaired';
        $this->info(sprintf(
            'Reconcile done. %s: %d missing, %d outdated, %d orphan, %d document(s) of de-indexed tables.',
            $verb,
            $totalMissing,
            $totalOutdated,
            $totalOrphan,
            $totalStale
        ));

        // Documents written before the updated_ts field existed cannot be
        // compared, so drift in them stays invisible until a full reindex.
        if ($totalUnverified > 0) {
            $this->warn(sprintf(
                '  %d document(s) carry no %s and were not compared. Run `php artisan exment:meili-index` once to enable drift detection for them.',
                $totalUnverified,
                DocumentMapper::UPDATED_FIELD
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Compare ONE table against the index and repair the drift.
     *
     * The ids are walked from MySQL instead of being loaded whole: every id
     * found in the index is dropped from the lookup set, so what is left in it
     * at the end is the orphan candidates.
     *
     * @param \Exceedone\Exment\Model\CustomTable $table
     * @param \Exceedone\Exment\Services\Meili\ExmentIndexer $indexer
     * @return array{missing:int, outdated:int, unverified:int, orphan:int}
     */
    protected function reconcileTable($table, MeiliSearchService $service, $indexer, DocumentMapper $mapper, bool $dryRun): array
    {
        $tableName = $table->table_name;
        $chunkSize = max(1, (int) config('meilisearch.batch_size', 1000));

        // value_id => updated_ts, so a document holding old content is caught
        // too: comparing ids alone only ever finds missing/orphan documents.
        $indexed = $service->indexedUpdatedStamps($tableName, $chunkSize);

        $missing = 0;
        $outdated = 0;
        $unverified = 0;
        $buffer = [];

        getModelName($table)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->select('id', 'updated_at')
            ->chunkById($chunkSize, function ($records) use (&$indexed, &$missing, &$outdated, &$unverified, &$buffer, $table, $indexer, $dryRun, $chunkSize) {
                foreach ($records as $record) {
                    $key = (string) $record->id;
                    if (array_key_exists($key, $indexed)) {
                        $stamp = $indexed[$key];
                        unset($indexed[$key]);

                        if ($stamp === null) {
                            // Indexed before the field existed: nothing to compare
                            // against, and reindexing every such document would
                            // mean a full rebuild on the first run after upgrade.
                            $unverified++;
                            continue;
                        }
                        if ($stamp === DocumentMapper::recordStamp($record->updated_at)) {
                            continue;
                        }
                        $outdated++;
                    } else {
                        $missing++;
                    }

                    // Dry-run only counts: keeping every id would hold the whole
                    // table in memory on an index that was never built.
                    if (!$dryRun) {
                        $buffer[] = $record->id;
                    }
                }

                if (!$dryRun && count($buffer) >= $chunkSize) {
                    $indexer->reindexIds($table, $buffer);
                    $buffer = [];
                }
            });

        if (!$dryRun && !empty($buffer)) {
            $indexer->reindexIds($table, $buffer);
        }

        // Re-check against the database before deleting anything: a record
        // created (or restored) after the index was read is in the index but was
        // never seen by the walk above, and its document would be dropped -
        // making a brand new record unsearchable until the next run.
        $orphan = 0;
        foreach (array_chunk(array_keys($indexed), $chunkSize) as $candidates) {
            $ids = ReindexMeiliTableJob::deletableOrphans(
                $candidates,
                ReindexMeiliTableJob::existingIds($table, $candidates)
            );
            $orphan += count($ids);

            if (!$dryRun && !empty($ids)) {
                $service->deleteByValueIds($tableName, $ids, $mapper);
            }
        }

        return ['missing' => $missing, 'outdated' => $outdated, 'unverified' => $unverified, 'orphan' => $orphan];
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
