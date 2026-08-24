<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\ExmentIndexer;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Illuminate\Console\Command;

class MeiliIndexCommand extends Command
{
    use CommandTrait;
    use MeiliCommandTrait;

    protected $signature = 'exment:meili-index {--fresh : Delete and recreate the index before indexing}
        {--force : Skip the confirmation prompt of --fresh}';

    protected $description = 'Index Exment data (search-enabled custom tables) into Meilisearch';

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

        // Check the Meilisearch connection.
        try {
            $health = $client->health();
            if (($health['status'] ?? null) !== 'available') {
                $this->error('Meilisearch is not available: ' . json_encode($health));
                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('Could not connect to Meilisearch (' . config('meilisearch.host') . '): ' . $e->getMessage());
            return self::FAILURE;
        }

        $indexName = config('meilisearch.index');
        $indexer = new ExmentIndexer(
            $client,
            new DocumentMapper(),
            $indexName,
            (int) config('meilisearch.batch_size')
        );

        $tables = $indexer->searchableTables();
        if ($tables->isEmpty()) {
            $this->warn('No search-enabled custom table with a freeword column to index.');
            return self::SUCCESS;
        }

        // --fresh drops the whole index (every table, not just this run's), so
        // confirm first like the other destructive commands do.
        if ($this->option('fresh') && !$this->option('force')) {
            if (!$this->confirm(sprintf(
                'This will DELETE and recreate the index "%s", removing every indexed document. Continue?',
                $indexName
            ))) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
        }

        $this->info(sprintf(
            'Indexing %d table(s) into "%s"%s...',
            $tables->count(),
            $indexName,
            $this->option('fresh') ? ' (fresh)' : ''
        ));

        $result = $indexer->indexAll((bool) $this->option('fresh'));

        foreach ($result['perTable'] as $name => $count) {
            $this->line(sprintf('  - %-30s %d records', $name, $count));
        }
        $this->info('Total: ' . $result['total'] . ' documents indexed.');

        return self::SUCCESS;
    }
}
